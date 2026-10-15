<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Province;
use App\Models\Region;
use App\Models\Warehouse;
use Database\Seeders\SampleWarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SampleWarehouseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Warehouse code => [name, city code, postal, province code or null, region code, barangay code, line1]. */
    private const EXPECTED = [
        'sample-hub-metro-manila' => ['Archon Nell hub - Taguig', '1381500000', '1630', null, '1300000000', '1381500018', 'Warehouse 4, FTI Complex, DBP Avenue'],
        'sample-hub-greater-manila' => ['Archon Nell hub - Meycauayan', '0301412000', '3020', '0301400000', '0300000000', '0301412009', 'Sterling Industrial Park, Iba'],
        'sample-hub-north-luzon' => ['Archon Nell hub - San Fernando (Pampanga)', '0305416000', '2000', '0305400000', '0300000000', '0305416036', 'Jose Abad Santos Avenue, Sindalan'],
        'sample-hub-south-luzon' => ['Archon Nell hub - Calamba', '0403405000', '4027', '0403400000', '0400000000', '0403405011', 'Carmelray Industrial Park II, Canlubang'],
        'sample-hub-visayas' => ['Archon Nell hub - Mandaue', '0731300000', '6014', '0731200000', '0700000000', '0731300005', 'A.C. Cortes Avenue, Cabancalan'],
        'sample-hub-mindanao' => ['Archon Nell hub - Davao', '1130700000', '8000', null, '1100000000', '1130700035', 'Cabantian Road, Communal, Buhangin'],
    ];

    /** The six hub cities with their real region/province codes and the seeder's barangay each, minus $skipCity. */
    private function locations(?string $skipCity = null): void
    {
        $cities = [
            ['1381500000', 'Taguig City', ['1300000000', 'National Capital Region (NCR)'], null, '1381500018'],
            ['0301412000', 'City of Meycauayan', ['0300000000', 'Region III (Central Luzon)'], ['0301400000', 'Bulacan'], '0301412009'],
            ['0305416000', 'City of San Fernando', ['0300000000', 'Region III (Central Luzon)'], ['0305400000', 'Pampanga'], '0305416036'],
            ['0403405000', 'City of Calamba', ['0400000000', 'Region IV-A (CALABARZON)'], ['0403400000', 'Laguna'], '0403405011'],
            ['0731300000', 'City of Mandaue', ['0700000000', 'Region VII (Central Visayas)'], ['0731200000', 'Cebu'], '0731300005'],
            ['1130700000', 'City of Davao', ['1100000000', 'Region XI (Davao Region)'], null, '1130700035'],
        ];
        $regions = [];
        foreach ($cities as [$code, $name, [$regionCode, $regionName], $province, $barangayCode]) {
            $region = $regions[$regionCode] ??= Region::create(['code' => $regionCode, 'name' => $regionName]);
            $provinceId = $province ? Province::create(['code' => $province[0], 'name' => $province[1], 'region_id' => $region->id])->id : null;
            if ($code === $skipCity) { continue; }
            $city = City::create(['code' => $code, 'name' => $name, 'type' => 'city', 'region_id' => $region->id, 'province_id' => $provinceId]);
            Barangay::create(['code' => $barangayCode, 'name' => "Barangay of {$name}", 'city_id' => $city->id]);
        }
    }

    private function demoWarehouse(): Warehouse
    {
        $barangay = Barangay::where('code', '1381500018')->first();
        $address = Address::create(['line1' => 'Demo hub building', 'line2' => null, 'region_id' => $barangay->city->region_id, 'province_id' => null, 'city_id' => $barangay->city_id, 'barangay_id' => $barangay->id, 'postal_code' => '1740', 'latitude' => null, 'longitude' => null, 'contact_name' => 'Demo hub desk', 'contact_phone' => '09000000000']);

        return Warehouse::create(['code' => 'demo-las-pinas-hub', 'name' => 'Demo Las Piñas hub', 'type' => 'platform_hub', 'address_id' => $address->id, 'is_active' => true]);
    }

    public function test_it_creates_six_sample_hubs_with_addresses_in_the_right_locations(): void
    {
        $this->locations();
        $demo = $this->demoWarehouse();
        $demoBefore = [$demo->fresh()->toArray(), $demo->address->fresh()->toArray()];

        $this->artisan('db:seed', ['--class' => SampleWarehouseSeeder::class])
            ->expectsOutputToContain('sample-hub-metro-manila: Archon Nell hub - Taguig')
            ->expectsOutputToContain('sample-hub-mindanao: Archon Nell hub - Davao')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), Warehouse::where('code', 'like', 'sample-hub-%')->pluck('code')->all());
        $this->assertSame(7, Warehouse::count());
        $this->assertSame(7, Address::count());
        foreach (self::EXPECTED as $code => [$name, $cityCode, $postal, $provinceCode, $regionCode, $barangayCode, $line1]) {
            $warehouse = Warehouse::where('code', $code)->firstOrFail();
            $this->assertSame([$name, 'platform_hub', null, true], [$warehouse->name, $warehouse->type, $warehouse->seller_id, $warehouse->is_active], $code);
            $address = $warehouse->address;
            $this->assertSame(
                [$cityCode, $barangayCode, $regionCode, $provinceCode, $postal, $line1, null, 'Hub operations desk', '09000000000'],
                [$address->city->code, $address->barangay->code, $address->region->code, $address->province?->code, $address->postal_code, $address->line1, $address->line2, $address->contact_name, $address->contact_phone],
                $code,
            );
            $this->assertNotNull([$address->latitude, $address->longitude][0], $code);
        }
        $this->assertSame($demoBefore, [$demo->fresh()->toArray(), $demo->address->fresh()->toArray()]);
    }

    public function test_rerunning_updates_the_samples_in_place_without_duplicates(): void
    {
        $this->locations();
        $demo = $this->demoWarehouse();
        $this->seed(SampleWarehouseSeeder::class);
        $metro = Warehouse::where('code', 'sample-hub-metro-manila')->firstOrFail();
        $addressId = $metro->address_id;
        $metro->update(['name' => 'Renamed', 'is_active' => false]);
        Address::whereKey($addressId)->update(['line1' => 'Moved', 'postal_code' => '9999']);
        $counts = [Warehouse::count(), Address::count()];
        $demoBefore = [$demo->fresh()->toArray(), $demo->address->fresh()->toArray()];

        $this->seed(SampleWarehouseSeeder::class);

        $this->assertSame($counts, [Warehouse::count(), Address::count()]);
        $metro->refresh();
        $this->assertSame([$addressId, 'Archon Nell hub - Taguig', true], [$metro->address_id, $metro->name, $metro->is_active]);
        $this->assertSame(['Warehouse 4, FTI Complex, DBP Avenue', '1630'], [$metro->address->line1, $metro->address->postal_code]);
        $this->assertSame($demoBefore, [$demo->fresh()->toArray(), $demo->address->fresh()->toArray()]);
    }

    public function test_it_writes_nothing_in_production(): void
    {
        $this->locations();
        $this->app['env'] = 'production';
        try {
            $this->artisan('db:seed', ['--class' => SampleWarehouseSeeder::class, '--force' => true])
                ->expectsOutputToContain('never created in production')
                ->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame([0, 0], [Warehouse::count(), Address::count()]);
    }

    public function test_it_refuses_to_run_before_the_locations_are_imported(): void
    {
        $this->locations(skipCity: '1130700000');
        foreach ([false, true] as $cityWithoutBarangays) {
            if ($cityWithoutBarangays) {
                City::create(['code' => '1130700000', 'name' => 'City of Davao', 'type' => 'city', 'region_id' => Region::where('code', '1100000000')->value('id'), 'province_id' => null]);
            }
            try {
                (new SampleWarehouseSeeder)->run();
                $this->fail('The seeder should refuse to run without the imported locations.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('1130700000', $e->getMessage());
                $this->assertStringContainsString('Import the PSGC locations first (Logistics > Locations > Import)', $e->getMessage());
            }
            $this->assertSame([0, 0], [Warehouse::count(), Address::count()]);
        }
    }
}
