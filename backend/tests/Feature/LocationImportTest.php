<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LocationImportTest extends TestCase
{
    use RefreshDatabase;

    // A slice of the PSA "PSGC 2Q 2026" publication saved as CSV, with its real codes, levels and blank-level grouping rows.
    private const PSGC = <<<'CSV'
10-digit PSGC,Name,Geographic Level
1300000000,National Capital Region (NCR),Reg
1380100000,City of Caloocan,City
1380100001,Barangay 1,Bgy
1380600000,City of Manila,City
1380601000,Tondo I/II,SubMun
1380601001,Barangay 1,Bgy
1380602000,Binondo,SubMun
1380602001,Barangay 287,Bgy
0100000000,Region I (Ilocos Region),Reg
0102800000,Ilocos Norte,Prov
0102801000,Adams,Mun
0102801001,Adams,Bgy
0300000000,Region III (Central Luzon),Reg
0330100000,City of Angeles,City
0330100001,Agapito del Rosario,Bgy
0900000000,Region IX (Zamboanga Peninsula),Reg
0990100000,City of Isabela (Not a Province),
0990101000,City of Isabela,City
0990101002,Aguada,Bgy
1900000000,Bangsamoro Autonomous Region In Muslim Mindanao (BARMM),Reg
1999900000,Special Geographic Area,
1999901000,Kapalawan,Mun

CSV;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RolePermissionSeeder::class);
        $admin = User::create(['name' => 'Location administrator', 'email' => 'locations.admin@example.com', 'password' => 'password', 'is_active' => true]); $admin->assignRole('Admin');
        $this->token = $admin->createToken('test', ['staff'])->plainTextToken;
    }

    private function upload(string $csv, ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($token ?? $this->token)->post('/api/v1/locations/import', ['file' => UploadedFile::fake()->createWithContent('psgc.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function tableCounts(): array
    {
        return [Region::count(), Province::count(), City::count(), Barangay::count()];
    }

    public function test_imports_the_psa_publication_layout_with_manila_sub_municipalities_and_grouping_rows(): void
    {
        $this->upload(self::PSGC)->assertOk()->assertExactJson(['message' => 'PSGC locations imported successfully.', 'summary' => ['regions' => 5, 'provinces' => 1, 'cities' => 6, 'barangays' => 6]]);
        $manila = City::where('code', '1380600000')->firstOrFail();
        $this->assertSame([$manila->id, $manila->id], Barangay::whereIn('code', ['1380601001', '1380602001'])->pluck('city_id')->all());
        $this->assertFalse(City::whereIn('code', ['1380601000', '1380602000', '0990100000', '1999900000'])->exists());
        $this->assertFalse(Province::whereIn('code', ['0990100000', '1999900000'])->exists());
        $adams = City::where('code', '0102801000')->firstOrFail();
        $this->assertSame(['municipality', Province::where('code', '0102800000')->value('id')], [$adams->type, $adams->province_id]);
        $this->assertSame($adams->id, Barangay::where('code', '0102801001')->value('city_id'));
        foreach (['1380100000' => '1300000000', '0330100000' => '0300000000', '0990101000' => '0900000000', '1999901000' => '1900000000'] as $code => $region) {
            $city = City::where('code', $code)->firstOrFail();
            $this->assertNull($city->province_id, $code); $this->assertSame(Region::where('code', $region)->value('id'), $city->region_id, $code);
        }
        $this->assertSame(City::where('code', '0990101000')->value('id'), Barangay::where('code', '0990101002')->value('city_id'));
        $audit = AuditLog::where('entity_type', 'LocationImport')->latest('id')->firstOrFail();
        $this->assertSame(['regions' => 5, 'provinces' => 1, 'cities' => 6, 'barangays' => 6], $audit->new_values);
    }

    public function test_reimport_is_idempotent_and_updates_names(): void
    {
        $this->upload(self::PSGC)->assertOk(); $counts = $this->tableCounts(); $createdAt = Barangay::where('code', '1380601001')->firstOrFail()->getRawOriginal('created_at');
        $this->assertNotNull($createdAt); $this->travel(5)->minutes();
        $this->upload(str_replace('1380601001,Barangay 1,Bgy', '1380601001,Barangay 1 (Tondo),Bgy', self::PSGC))->assertOk()->assertJsonPath('summary.barangays', 6);
        $this->assertSame($counts, $this->tableCounts());
        $barangay = Barangay::where('code', '1380601001')->firstOrFail();
        $this->assertSame(['Barangay 1 (Tondo)', $createdAt], [$barangay->name, $barangay->getRawOriginal('created_at')]);
    }

    public function test_empty_optional_parent_cells_are_derived_and_explicit_ones_win(): void
    {
        $this->upload("code,name,level,region_code,province_code,city_code\n1300000000,National Capital Region (NCR),Reg,,,\n1380100000,City of Caloocan,City,,,\n1380200000,City of Las Pi\xF1as,City,1300000000,,\n1380100001,Barangay 1,Bgy,,,\n1380200001,Almanza Uno,Bgy,,,1380200000\n")
            ->assertOk()->assertJsonPath('summary', ['regions' => 1, 'provinces' => 0, 'cities' => 2, 'barangays' => 2]);
        $this->assertSame(City::where('code', '1380100000')->value('id'), Barangay::where('code', '1380100001')->value('city_id'));
        $this->assertSame('City of Las Piñas', City::where('code', '1380200000')->value('name'));
        // An explicit city_code wins over the code-derived parent; here it points to a city that does not exist.
        $this->upload("code,name,level,region_code,province_code,city_code\n1380100002,Barangay 2,Bgy,,,1380900000\n")
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The parent city for CSV row 2 must be included or already imported.');
    }

    public function test_bad_rows_return_the_line_number_and_write_nothing(): void
    {
        $cases = [
            "1300000000,National Capital Region (NCR),Reg\n1380100000,City of Caloocan,Town\n" => 'CSV row 3 needs an official 10-digit code, valid geographic level and name. Preserve leading zeroes when saving the PSA publication.',
            "1300000000,National Capital Region (NCR),Reg\n1380100000,City of Caloocan,\n" => 'CSV row 3 needs an official 10-digit code, valid geographic level and name. Preserve leading zeroes when saving the PSA publication.',
            "1300000000,National Capital Region (NCR),Reg\n0102800000,Ilocos Norte,Prov\n" => 'The parent region for CSV row 3 must be included or already imported.',
            "1300000000,National Capital Region (NCR),Reg\n1380100000,City of Caloocan,City\n1380100001,Barangay 1,Bgy\n1380900001,Bagumbayan North,Bgy\n" => 'The parent city for CSV row 5 must be included or already imported.',
        ];
        foreach ($cases as $rows => $message) {
            $this->upload("10-digit PSGC,Name,Geographic Level\n".$rows)->assertUnprocessable()->assertJsonPath('errors.file.0', $message);
            $this->assertSame([0, 0, 0, 0], $this->tableCounts(), $message);
        }
        // A barangay may not hang under a city of another region, even through an explicit city_code.
        $this->upload("code,name,level,city_code\n1300000000,National Capital Region (NCR),Reg,\n0100000000,Region I (Ilocos Region),Reg,\n0102801000,Adams,Mun,\n1380100001,Barangay 1,Bgy,0102801000\n")
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The parent city for CSV row 5 must be included or already imported.');
        $this->assertSame([0, 0, 0, 0], $this->tableCounts());
    }

    public function test_only_authenticated_admins_can_import(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/locations/import', ['file' => UploadedFile::fake()->createWithContent('psgc.csv', self::PSGC)], ['Accept' => 'application/json'])->assertUnauthorized();
        $coordinator = User::create(['name' => 'Logistics coordinator', 'email' => 'locations.coordinator@example.com', 'password' => 'password', 'is_active' => true]); $coordinator->assignRole('Warehouseman');
        $this->upload(self::PSGC, $coordinator->createToken('test', ['staff'])->plainTextToken)->assertForbidden()->assertJsonPath('message', 'Only Admins can import the official PSGC publication.');
        $this->assertSame([0, 0, 0, 0], $this->tableCounts());
    }

    public function test_five_thousand_rows_import_with_a_fixed_number_of_queries(): void
    {
        $csv = "10-digit PSGC,Name,Geographic Level\n0100000000,Region I (Ilocos Region),Reg\n0102800000,Ilocos Norte,Prov\n";
        for ($mun = 1; $mun <= 10; $mun++) { $csv .= sprintf("01028%02d000,Municipality %d,Mun\n", $mun, $mun); }
        for ($i = 0; $i < 4988; $i++) { $csv .= sprintf("01028%02d%03d,Barangay %d,Bgy\n", intdiv($i, 499) + 1, $i % 499 + 1, $i); }
        DB::enableQueryLog();
        $this->upload($csv)->assertOk()->assertJsonPath('summary', ['regions' => 1, 'provinces' => 1, 'cities' => 10, 'barangays' => 4988]);
        $queries = count(DB::getQueryLog()); DB::disableQueryLog();
        $this->assertLessThan(100, $queries);
        $this->assertGreaterThanOrEqual(13, $queries); // 10 barangay chunks + the region, province and city upserts
        $this->assertSame(4988, Barangay::count());
        $this->assertSame(499, Barangay::where('city_id', City::where('code', '0102801000')->value('id'))->count());
    }

    public function test_barangays_can_be_added_to_cities_imported_earlier(): void
    {
        $this->upload("10-digit PSGC,Name,Geographic Level\n1300000000,National Capital Region (NCR),Reg\n1380100000,City of Caloocan,City\n")->assertOk();
        $this->upload("10-digit PSGC,Name,Geographic Level\n1380100001,Barangay 1,Bgy\n")->assertOk()->assertJsonPath('summary.barangays', 1);
        $this->upload("code,name,level,city_code\n1380100002,Barangay 2,Bgy,1380100000\n")->assertOk()->assertJsonPath('summary.barangays', 1);
        $caloocan = City::where('code', '1380100000')->value('id');
        $this->assertSame([$caloocan, $caloocan], Barangay::whereIn('code', ['1380100001', '1380100002'])->orderBy('code')->pluck('city_id')->all());
    }

    public function test_a_file_far_larger_than_the_publication_is_refused_before_anything_is_written(): void
    {
        $csv = "10-digit PSGC,Name,Geographic Level\n1300000000,National Capital Region (NCR),Reg\n".str_repeat("1380100001,Barangay 1,Bgy\n", 60000);
        $this->upload($csv)->assertUnprocessable()->assertJsonPath('errors.file.0', 'The CSV has more rows than the PSGC publication.');
        $this->assertSame([0, 0, 0, 0], $this->tableCounts());
    }
}
