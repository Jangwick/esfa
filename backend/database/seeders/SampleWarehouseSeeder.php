<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sample platform hubs (one per delivery zone) for demo and test databases only; the owner adds real warehouses later.
 * Run after the PSGC import: php artisan db:seed --class=SampleWarehouseSeeder. Not part of DatabaseSeeder.
 */
class SampleWarehouseSeeder extends Seeder
{
    /** Warehouse code => [name, PSGC city code, postal code]. */
    private const HUBS = [
        'sample-hub-metro-manila' => ['Sample hub - Metro Manila', '1381300000', '1100'],
        'sample-hub-greater-manila' => ['Sample hub - Greater Manila Area', '0403405000', '4027'],
        'sample-hub-north-luzon' => ['Sample hub - North & Central Luzon', '0305416000', '2000'],
        'sample-hub-south-luzon' => ['Sample hub - South Luzon & Bicol', '0500506000', '4500'],
        'sample-hub-visayas' => ['Sample hub - Visayas', '0730600000', '6000'],
        'sample-hub-mindanao' => ['Sample hub - Mindanao', '1130700000', '8000'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipped: sample warehouses are never created in production.');
            return;
        }

        // Resolve every location before writing anything.
        $plan = [];
        foreach (self::HUBS as $code => [$name, $cityCode, $postal]) {
            $city = City::where('code', $cityCode)->first();
            $barangay = $city ? Barangay::where('city_id', $city->id)->orderBy('code')->first() : null;
            if (! $barangay) {
                throw new RuntimeException("The city with PSGC code {$cityCode} (for \"{$name}\") or its barangays are missing. Import the PSGC locations first (Logistics > Locations > Import), then run this seeder again.");
            }
            $plan[$code] = [$name, $postal, $city, $barangay];
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $code => [$name, $postal, $city, $barangay]) {
                $address = ['line1' => 'Sample warehouse - not a real site', 'line2' => null, 'region_id' => $city->region_id, 'province_id' => $city->province_id, 'city_id' => $city->id, 'barangay_id' => $barangay->id, 'postal_code' => $postal, 'latitude' => null, 'longitude' => null, 'contact_name' => 'Sample hub desk', 'contact_phone' => '09000000000'];
                $hub = ['name' => $name, 'type' => 'platform_hub', 'seller_id' => null, 'is_active' => true];
                $warehouse = Warehouse::where('code', $code)->first();
                if ($warehouse) {
                    Address::whereKey($warehouse->address_id)->update($address);
                    $warehouse->update($hub);
                } else {
                    Warehouse::create(['code' => $code, 'address_id' => Address::create($address)->id] + $hub);
                }
            }
        });

        foreach ($plan as $code => [$name, , $city, $barangay]) {
            $this->command?->line("{$code}: {$name} ({$barangay->name}, {$city->name})");
        }
    }
}
