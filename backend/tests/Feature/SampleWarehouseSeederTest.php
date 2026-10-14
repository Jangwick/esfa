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

    /** Warehouse code => [name, city code, postal, province code or null, region code, first barangay code]. */
    private const EXPECTED = [
        'sample-hub-metro-manila' => ['Sample hub - Metro Manila', '1381300000', '1100', null, '1300000000', '1381300001'],
        'sample-hub-greater-manila' => ['Sample hub - Greater Manila Area', '0403405000', '4027', '0403400000', '0400000000', '0403405001'],
        'sample-hub-north-luzon' => ['Sample hub - North & Central Luzon', '0305416000', '2000', '0305400000', '0300000000', '0305416001'],
        'sample-hub-south-luzon' => ['Sample hub - South Luzon & Bicol', '0500506000', '4500', '0500500000', '0500000000', '0500506001'],
        'sample-hub-visayas' => ['Sample hub - Visayas', '0730600000', '6000', null, '0700000000', '0730600001'],
        'sample-hub-mindanao' => ['Sample hub - Mindanao', '1130700000', '8000', null, '1100000000', '1130700001'],
    ];

    /** The six hub cities with their real region/province codes and one barangay each, minus $skipCity. */
    private function locations(?string $skipCity = null): void
    {
        $cities = [
            ['1381300000', 'Quezon City', ['1300000000', 'National Capital Region (NCR)'], null],
            ['0403405000', 'City of Calamba', ['0400000000', 'Region IV-A (CALABARZON)'], ['0403400000', 'Laguna']],
            ['0305416000', 'City of San Fernando', ['0300000000', 'Region III (Central Luzon)'], ['0305400000', 'Pampanga']],
            ['0500506000', 'City of Legazpi', ['0500000000', 'Region V (Bicol Region)'], ['0500500000', 'Albay']],
            ['0730600000', 'City of Cebu', ['0700000000', 'Region VII (Central Visayas)'], null],
            ['1130700000', 'City of Davao', ['1100000000', 'Region XI (Davao Region)'], null],
        ];
        foreach ($cities as [$code, $name, [$regionCode, $regionName], $province]) {
            $region = Region::create(['code' => $regionCode, 'name' => $regionName]);
            $provinceId = $province ? Province::create(['code' => $province[0], 'name' => $province[1], 'region_id' => $region->id])->id : null;
            if ($code === $skipCity) { continue; }
            $city = City::create(['code' => $code, 'name' => $name, 'type' => 'city', 'region_id' => $region->id, 'province_id' => $provinceId]);
            if ($code === '1381300000') {
                // Inserted first (lower id) but higher code: the seeder must order by code, not by id.
                Barangay::create(['code' => '1381300002', 'name' => 'Second barangay of Quezon City', 'city_id' => $city->id]);
            }
            Barangay::create(['code' => substr($code, 0, 7).'001', 'name' => "First barangay of {$name}", 'city_id' => $city->id]);
        }
    }

    private function demoWarehouse(): Warehouse
    {
        $barangay = Barangay::where('code', '1381300001')->first();
        $address = Address::create(['line1' => 'Demo hub building', 'line2' => null, 'region_id' => $barangay->city->region_id, 'province_id' => null, 'city_id' => $barangay->city_id, 'barangay_id' => $barangay->id, 'postal_code' => '1740', 'latitude' => null, 'longitude' => null, 'contact_name' => 'Demo hub desk', 'contact_phone' => '09000000000']);

        return Warehouse::create(['code' => 'demo-las-pinas-hub', 'name' => 'Demo Las Piñas hub', 'type' => 'platform_hub', 'address_id' => $address->id, 'is_active' => true]);
    }

    public function test_it_creates_six_sample_hubs_with_addresses_in_the_right_locations(): void
    {
        $this->locations();
        $demo = $this->demoWarehouse();
        $demoBefore = [$demo->fresh()->toArray(), $demo->address->fresh()->toArray()];

        $this->artisan('db:seed', ['--class' => SampleWarehouseSeeder::class])
            ->expectsOutputToContain('sample-hub-metro-manila: Sample hub - Metro Manila')
            ->expectsOutputToContain('sample-hub-mindanao: Sample hub - Mindanao')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), Warehouse::where('code', 'like', 'sample-hub-%')->pluck('code')->all());
        $this->assertSame(7, Warehouse::count());
        $this->assertSame(7, Address::count());
        foreach (self::EXPECTED as $code => [$name, $cityCode, $postal, $provinceCode, $regionCode, $barangayCode]) {
            $warehouse = Warehouse::where('code', $code)->firstOrFail();
            $this->assertSame([$name, 'platform_hub', null, true], [$warehouse->name, $warehouse->type, $warehouse->seller_id, $warehouse->is_active], $code);
            $address = $warehouse->address;
            $this->assertSame(
                [$cityCode, $barangayCode, $regionCode, $provinceCode, $postal, 'Sample warehouse - not a real site', null, null, null, 'Sample hub desk', '09000000000'],
                [$address->city->code, $address->barangay->code, $address->region->code, $address->province?->code, $address->postal_code, $address->line1, $address->line2, $address->latitude, $address->longitude, $address->contact_name, $address->contact_phone],
                $code,
            );
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
        $this->assertSame([$addressId, 'Sample hub - Metro Manila', true], [$metro->address_id, $metro->name, $metro->is_active]);
        $this->assertSame(['Sample warehouse - not a real site', '1100'], [$metro->address->line1, $metro->address->postal_code]);
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
