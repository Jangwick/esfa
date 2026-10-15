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
 * Platform hubs (one per delivery zone) at real Philippine logistics areas for demo and test databases only;
 * the owner adds real warehouses later. Run after the PSGC import: php artisan db:seed --class=SampleWarehouseSeeder.
 * Not part of DatabaseSeeder.
 */
class SampleWarehouseSeeder extends Seeder
{
    /** Warehouse code => [name, PSGC city code, PSGC barangay code, line1, postal, latitude, longitude]. */
    private const HUBS = [
        'sample-hub-metro-manila'   => ['Archon Nell hub - Taguig', '1381500000', '1381500018', 'Warehouse 4, FTI Complex, DBP Avenue', '1630', 14.5084, 121.0381],
        'sample-hub-greater-manila' => ['Archon Nell hub - Meycauayan', '0301412000', '0301412009', 'Sterling Industrial Park, Iba', '3020', 14.7367, 120.9608],
        'sample-hub-north-luzon'    => ['Archon Nell hub - San Fernando (Pampanga)', '0305416000', '0305416036', 'Jose Abad Santos Avenue, Sindalan', '2000', 15.0594, 120.6567],
        'sample-hub-south-luzon'    => ['Archon Nell hub - Calamba', '0403405000', '0403405011', 'Carmelray Industrial Park II, Canlubang', '4027', 14.2116, 121.1653],
        'sample-hub-visayas'        => ['Archon Nell hub - Mandaue', '0731300000', '0731300005', 'A.C. Cortes Avenue, Cabancalan', '6014', 10.3546, 123.9322],
        'sample-hub-mindanao'       => ['Archon Nell hub - Davao', '1130700000', '1130700035', 'Cabantian Road, Communal, Buhangin', '8000', 7.0907, 125.6097],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipped: sample warehouses are never created in production.');
            return;
        }

        // Resolve every location before writing anything.
        $plan = [];
        foreach (self::HUBS as $code => [$name, $cityCode, $barangayCode, $line1, $postal, $latitude, $longitude]) {
            $city = City::where('code', $cityCode)->first();
            $barangay = $city ? Barangay::where('code', $barangayCode)->where('city_id', $city->id)->first() : null;
            if (! $barangay) {
                throw new RuntimeException("The city {$cityCode} or barangay {$barangayCode} (for \"{$name}\") is missing. Import the PSGC locations first (Logistics > Locations > Import), then run this seeder again.");
            }
            $plan[$code] = [$name, $line1, $postal, $latitude, $longitude, $city, $barangay];
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $code => [$name, $line1, $postal, $latitude, $longitude, $city, $barangay]) {
                $address = ['line1' => $line1, 'line2' => null, 'region_id' => $city->region_id, 'province_id' => $city->province_id, 'city_id' => $city->id, 'barangay_id' => $barangay->id, 'postal_code' => $postal, 'latitude' => $latitude, 'longitude' => $longitude, 'contact_name' => 'Hub operations desk', 'contact_phone' => '09000000000'];
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

        foreach ($plan as $code => [$name, , , , , $city, $barangay]) {
            $this->command?->line("{$code}: {$name} ({$barangay->name}, {$city->name})");
        }
    }
}
