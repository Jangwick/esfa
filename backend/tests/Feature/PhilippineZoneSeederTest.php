<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Courier;
use App\Models\Province;
use App\Models\Rate;
use App\Models\Region;
use App\Models\Zone;
use Database\Seeders\PhilippineZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PhilippineZoneSeederTest extends TestCase
{
    use RefreshDatabase;

    private const REGIONS = ['0100000000', '0200000000', '0300000000', '0400000000', '0500000000', '0600000000', '0700000000', '0800000000', '0900000000', '1000000000', '1100000000', '1200000000', '1300000000', '1400000000', '1600000000', '1700000000', '1800000000', '1900000000'];

    /** A small PSGC-shaped fixture: all 18 regions, the four GMA provinces, other provinces, and province-less cities. */
    private function fixture(array $skipRegions = []): void
    {
        foreach (array_diff(self::REGIONS, $skipRegions) as $code) { Region::create(['code' => $code, 'name' => "Region {$code}"]); }
        $region = fn (string $code) => Region::where('code', $code)->value('id');
        $provinces = [
            ['0301400000', 'Bulacan', '0300000000'], ['0402100000', 'Cavite', '0400000000'], ['0403400000', 'Laguna', '0400000000'], ['0405800000', 'Rizal', '0400000000'],
            ['0102800000', 'Ilocos Norte', '0100000000'], ['0305400000', 'Pampanga', '0300000000'], ['1401100000', 'Benguet', '1400000000'], ['0401000000', 'Batangas', '0400000000'],
            ['0500500000', 'Albay', '0500000000'], ['1705300000', 'Palawan', '1700000000'], ['0702200000', 'Cebu', '0700000000'], ['1102400000', 'Davao del Sur', '1100000000'],
        ];
        foreach ($provinces as [$code, $name, $regionCode]) { Province::create(['code' => $code, 'name' => $name, 'region_id' => $region($regionCode)]); }
        $province = fn (?string $code) => $code ? Province::where('code', $code)->value('id') : null;
        $cities = [
            // [code, name, region, province or null]
            ['1380200000', 'City of Las Piñas', '1300000000', null], ['1380300000', 'City of Makati', '1300000000', null],
            ['0301410000', 'City of Malolos', '0300000000', '0301400000'], ['0402103000', 'City of Bacoor', '0400000000', '0402100000'],
            ['0403405000', 'City of Calamba', '0400000000', '0403400000'], ['0405802000', 'City of Antipolo', '0400000000', '0405800000'],
            ['0102801000', 'Adams', '0100000000', '0102800000'], ['0305416000', 'City of San Fernando', '0300000000', '0305400000'],
            ['0330100000', 'Angeles City', '0300000000', null], ['1430300000', 'City of Baguio', '1400000000', null],
            ['0401005000', 'Batangas City', '0400000000', '0401000000'], ['0500514000', 'City of Legazpi', '0500000000', '0500500000'],
            ['0702251000', 'City of Toledo', '0700000000', '0702200000'], ['0730600000', 'Cebu City', '0700000000', null],
            ['1102403000', 'City of Digos', '1100000000', '1102400000'], ['1130700000', 'Davao City', '1100000000', null],
            ['1999901000', 'Pahamuddin', '1900000000', null],
            // Hypothetical province-less city under the Bulacan prefix: must land in GMA only.
            ['0301499000', 'Fixture province-less Bulacan city', '0300000000', null],
        ];
        foreach ($cities as [$code, $name, $regionCode, $provinceCode]) { City::create(['code' => $code, 'name' => $name, 'type' => 'city', 'region_id' => $region($regionCode), 'province_id' => $province($provinceCode)]); }
    }

    private function zone(string $name): Zone { return Zone::where('name', $name)->firstOrFail(); }

    private function snapshot(): array { return Zone::orderBy('id')->get()->map(fn (Zone $zone) => $zone->only(['id', 'name', 'min_days', 'max_days', 'province_ids', 'city_ids', 'is_active']))->all(); }

    public function test_it_builds_six_zones_that_include_every_city_exactly_once(): void
    {
        $this->fixture();
        $this->artisan('db:seed', ['--class' => PhilippineZoneSeeder::class])->expectsOutputToContain('Visayas: 1 provinces, 1 cities')->assertSuccessful();

        $days = Zone::orderBy('id')->get()->mapWithKeys(fn (Zone $zone) => [$zone->name => [$zone->min_days, $zone->max_days, $zone->is_active]])->all();
        $this->assertSame(['Metro Manila' => [1, 2, true], 'Greater Manila Area' => [1, 3, true], 'North & Central Luzon' => [2, 5, true], 'South Luzon & Bicol' => [2, 5, true], 'Visayas' => [3, 7, true], 'Mindanao' => [4, 8, true]], $days);

        $provinceId = fn (string $code) => Province::where('code', $code)->value('id');
        $cityId = fn (string $code) => City::where('code', $code)->value('id');
        $this->assertSame([], $this->zone('Metro Manila')->province_ids);
        $this->assertSame([$cityId('1380200000'), $cityId('1380300000')], $this->zone('Metro Manila')->city_ids);
        $this->assertSame(array_map($provinceId, ['0301400000', '0402100000', '0403400000', '0405800000']), $this->zone('Greater Manila Area')->province_ids);
        $this->assertSame([$cityId('0301499000')], $this->zone('Greater Manila Area')->city_ids);
        $this->assertNotContains($provinceId('0301400000'), $this->zone('North & Central Luzon')->province_ids);
        $this->assertContains($provinceId('0305400000'), $this->zone('North & Central Luzon')->province_ids);
        $this->assertSame([$cityId('0330100000'), $cityId('1430300000')], $this->zone('North & Central Luzon')->city_ids);
        $this->assertSame([$provinceId('0401000000'), $provinceId('0500500000'), $provinceId('1705300000')], $this->zone('South Luzon & Bicol')->province_ids);
        $this->assertSame([$cityId('0730600000')], $this->zone('Visayas')->city_ids);
        $this->assertSame([$cityId('1130700000'), $cityId('1999901000')], $this->zone('Mindanao')->city_ids);

        $zones = Zone::all();
        foreach (City::all() as $city) {
            $this->assertSame(1, $zones->filter(fn (Zone $zone) => $zone->includesCity($city))->count(), "{$city->name} must be in exactly one zone.");
        }
    }

    public function test_running_twice_changes_nothing_and_leaves_other_zones_and_rates_alone(): void
    {
        $this->fixture();
        $demo = Zone::create(['name' => 'Demo NCR route', 'province_ids' => [], 'city_ids' => [City::where('code', '1380200000')->value('id')], 'min_days' => 1, 'max_days' => 3, 'is_active' => true]);
        $courier = Courier::create(['name' => 'Demo Courier', 'code' => 'demo-courier', 'service_types' => ['standard'], 'webhook_secret' => 'secret', 'is_active' => true]);
        $rate = Rate::create(['courier_id' => $courier->id, 'service_type' => 'standard', 'origin_zone_id' => $demo->id, 'destination_zone_id' => $demo->id, 'base_weight_grams' => 1000, 'base_fee_amount' => 8000, 'additional_per_500g_amount' => 1500, 'cod_fee_bp' => 100, 'max_weight_grams' => 20000, 'is_active' => true]);
        [$demoBefore, $rateBefore] = [$demo->fresh()->toArray(), $rate->fresh()->toArray()];

        (new PhilippineZoneSeeder)->run();
        $first = $this->snapshot();
        (new PhilippineZoneSeeder)->run();

        $this->assertCount(7, $first);
        $this->assertSame($first, $this->snapshot());
        $this->assertSame($demoBefore, $demo->fresh()->toArray());
        $this->assertSame($rateBefore, $rate->fresh()->toArray());
        $this->assertSame(1, Rate::count());

        // A rerun repairs the zone's coverage and days but keeps the owner's on/off choice.
        $this->zone('Visayas')->update(['min_days' => 9, 'city_ids' => [], 'is_active' => false]);
        (new PhilippineZoneSeeder)->run();
        $visayas = $this->zone('Visayas');
        $this->assertSame([3, 7, [City::where('code', '0730600000')->value('id')], false], [$visayas->min_days, $visayas->max_days, $visayas->city_ids, $visayas->is_active]);
        $this->assertSame(7, Zone::count());
    }

    public function test_it_refuses_to_run_before_the_locations_are_imported_and_writes_nothing(): void
    {
        $this->fixture(['1600000000']);
        $this->assertThrows(fn () => (new PhilippineZoneSeeder)->run(), RuntimeException::class, 'Import the PSGC locations first');
        $this->assertSame(0, Zone::count());

        Region::create(['code' => '1600000000', 'name' => 'Caraga']);
        City::query()->delete();
        Province::query()->delete();
        $this->assertThrows(fn () => (new PhilippineZoneSeeder)->run(), RuntimeException::class, 'No provinces were found');
        $this->assertSame(0, Zone::count());

        // Provinces exist but most zones would be empty: still refuse, and still write nothing.
        Province::create(['code' => '0102800000', 'name' => 'Ilocos Norte', 'region_id' => Region::where('code', '0100000000')->value('id')]);
        $this->assertThrows(fn () => (new PhilippineZoneSeeder)->run(), RuntimeException::class, 'would have no provinces or cities');
        $this->assertSame(0, Zone::count());
    }
}
