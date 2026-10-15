<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Region;
use App\Models\Seller;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\LogisticsService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class LogisticsDemoSeeder extends Seeder
{
    /** A clearly fake 1×1 PNG used as the demo proof-of-delivery photo. */
    private const PHOTO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII=';

    public function run(): void
    {
        if (app()->environment('production')) { return; }
        // Official codes verified from the PSA's June 2026 PSGC publication pages; NCR has no province.
        $region = Region::updateOrCreate(['code' => '1300000000'], ['name' => 'National Capital Region (NCR)']);
        $lasPinas = City::updateOrCreate(['code' => '1380200000'], ['name' => 'City of Las Piñas', 'region_id' => $region->id, 'province_id' => null, 'type' => 'city']);
        $makati = City::updateOrCreate(['code' => '1380300000'], ['name' => 'City of Makati', 'region_id' => $region->id, 'province_id' => null, 'type' => 'city']);
        foreach ([['1380200001', 'Almanza Uno'], ['1380200009', 'Zapote']] as [$code, $name]) { Barangay::updateOrCreate(['code' => $code], ['name' => $name, 'city_id' => $lasPinas->id]); }
        $courier = Courier::firstOrCreate(['code' => 'demo-courier'], ['name' => 'Archon Nell In-house Fleet', 'service_types' => ['standard', 'express'], 'is_active' => true, 'webhook_secret' => 'courier_'.Str::random(64), 'volumetric_divisor' => 3500, 'tracking_url_template' => 'https://courier.example.com/track?tracking={tracking_number}']);
        $zone = Zone::firstOrCreate(['name' => 'Metro Manila local route'], ['province_ids' => [], 'city_ids' => [$lasPinas->id, $makati->id], 'min_days' => 1, 'max_days' => 3, 'is_active' => true]);
        Rate::firstOrCreate(['courier_id' => $courier->id, 'service_type' => 'standard', 'origin_zone_id' => $zone->id, 'destination_zone_id' => $zone->id], ['base_weight_grams' => 1000, 'base_fee_amount' => 8000, 'additional_per_500g_amount' => 1500, 'cod_fee_bp' => 100, 'max_weight_grams' => 20000, 'is_active' => true]);
        Rate::firstOrCreate(['courier_id' => $courier->id, 'service_type' => 'express', 'origin_zone_id' => $zone->id, 'destination_zone_id' => $zone->id], ['base_weight_grams' => 1000, 'base_fee_amount' => 12000, 'additional_per_500g_amount' => 2000, 'cod_fee_bp' => 100, 'max_weight_grams' => 10000, 'is_active' => true]);

        // Real PSGC zones (PhilippineZoneSeeder): one lane per zone pair per service. Same-zone is the cheapest lane,
        // same-island costs more, and lanes crossing to or from the Visayas or Mindanao cost the most.
        $zones = Zone::whereIn('name', ['Metro Manila', 'Greater Manila Area', 'North & Central Luzon', 'South Luzon & Bicol', 'Visayas', 'Mindanao'])->get()->keyBy('name');
        if ($zones->count() === 6) {
            foreach ($zones as $origin => $from) {
                $originIsland = in_array($origin, ['Visayas', 'Mindanao'], true);
                foreach ($zones as $destination => $to) {
                    $acrossWater = $originIsland !== in_array($destination, ['Visayas', 'Mindanao'], true);
                    $factor = $origin === $destination ? 100 : ($acrossWater ? 240 : 170);
                    foreach (['standard' => [8500, 1500, 20000], 'express' => [14000, 2500, 10000]] as $service => [$base, $per500, $max]) {
                        Rate::firstOrCreate(['courier_id' => $courier->id, 'service_type' => $service, 'origin_zone_id' => $from->id, 'destination_zone_id' => $to->id], ['base_weight_grams' => 1000, 'base_fee_amount' => (int) ($base * $factor / 100), 'additional_per_500g_amount' => (int) ($per500 * $factor / 100), 'cod_fee_bp' => 100, 'max_weight_grams' => $max, 'is_active' => true]);
                    }
                }
            }
        }

        [$almanza, $zapote] = [Barangay::where('code', '1380200001')->first(), Barangay::where('code', '1380200009')->first()];
        $address = fn (Barangay $barangay, string $line, string $contact) => ['line1' => $line, 'line2' => null, 'region_id' => $region->id, 'province_id' => null, 'city_id' => $lasPinas->id, 'barangay_id' => $barangay->id, 'postal_code' => '1740', 'latitude' => null, 'longitude' => null, 'contact_name' => $contact, 'contact_phone' => '09000000000'];
        $hub = Warehouse::where('code', 'demo-las-pinas-hub')->first() ?? Warehouse::create(['code' => 'demo-las-pinas-hub', 'name' => 'Archon Nell hub - Las Pinas', 'type' => 'platform_hub', 'address_id' => Address::create($address($almanza, 'Archon Nell hub building', 'Receiving desk'))->id, 'is_active' => true]);
        // Pickup point per shop, at the real city in its pickup address (needs the PSGC import; repeats update the
        // address instead of duplicating the warehouse).
        foreach ([[1, 'Quezon City', 'Fairview'], [2, 'Makati', 'San Antonio'], [3, 'Mandaue', 'Tipolo'], [4, 'Davao', 'Buhangin'], [5, 'Santa Rosa', 'Macabling'], [6, 'Mandaue', 'North Reclamation'], [7, 'Batangas', 'Poblacion'], [8, 'Iloilo', 'City Proper'], [9, 'Cagayan', 'Nazareth'], [10, 'Naga', 'Concepcion']] as [$index, $cityLike, $barangayLike]) {
            $seller = Seller::where('source_system', 'demo-storefront')->where('external_id', 'demo-seller-'.$index)->first();
            $city = City::where('name', 'like', '%'.$cityLike.'%')->orderBy('id')->first();
            if (! $seller || ! $city) { continue; }
            $barangay = Barangay::where('city_id', $city->id)->where('name', 'like', '%'.$barangayLike.'%')->first() ?? Barangay::where('city_id', $city->id)->orderBy('code')->first();
            if (! $barangay) { continue; }
            $pickup = $seller->pickup_address ?? [];
            $address = ['line1' => $pickup['line1'] ?? 'Pickup counter', 'line2' => null, 'region_id' => $city->region_id, 'province_id' => $city->province_id, 'city_id' => $city->id, 'barangay_id' => $barangay->id, 'postal_code' => $pickup['postal_code'] ?? '0000', 'latitude' => null, 'longitude' => null, 'contact_name' => $seller->owner_name, 'contact_phone' => '09000000000'];
            $hub = ['name' => $seller->shop_name.' pickup point', 'type' => 'seller_pickup', 'seller_id' => $seller->id, 'is_active' => true];
            $warehouse = Warehouse::where('code', 'demo-seller-'.$index.'-pickup')->first();
            if ($warehouse) { Address::whereKey($warehouse->address_id)->update($address); $warehouse->update($hub); }
            else { Warehouse::create(['code' => 'demo-seller-'.$index.'-pickup', 'address_id' => Address::create($address)->id] + $hub); }
        }

        $actor = User::where('email', 'logistics@example.com')->first() ?? User::where('email', 'admin@example.com')->first();
        if (! $actor || Shipment::exists()) { return; }
        $service = app(LogisticsService::class);
        $paid = Order::where('source_system', 'demo-storefront')->where('status_snapshot', '!=', 'cancelled')->whereHas('payments', fn ($q) => $q->whereIn('status', ['captured', 'partially_refunded', 'refunded', 'disputed', 'dispute_won', 'dispute_lost']))->orderBy('id')->get();
        $cod = Order::where('source_system', 'demo-storefront')->whereHas('payments', fn ($q) => $q->where('method', 'cod')->where('status', 'pending'))->orderBy('id')->get();
        // Final status => [order, steps after creation]. "late" delivers after the promised window.
        $plan = [['pending', $paid->get(0)], ['ready_for_pickup', $paid->get(1)], ['picked_up', $paid->get(2)], ['in_transit', $paid->get(3)], ['out_for_delivery', $paid->get(4)], ['failed_attempt', $paid->get(5)], ['returning', $paid->get(6)], ['returned_to_sender', $paid->get(7)], ['delivered', $paid->get(8)], ['delivered', $paid->get(9)], ['late', $paid->get(10)], ['cancelled', $paid->get(11)], ['cod_delivered', $cod->get(0)], ['cod_delivered', $cod->get(1)], ['cod_out_for_delivery', $cod->get(2)]];
        try {
            foreach ($plan as $number => [$final, $order]) {
                if (! $order) { continue; }
                $clock = Carbon::parse($order->placed_at)->addHours(2); Carbon::setTestNow($clock);
                $isCod = str_starts_with($final, 'cod_');
                $codAmount = $isCod ? (int) Payment::where('order_id', $order->id)->where('method', 'cod')->value('total_amount') : 0;
                $shipment = $service->create(['order_id' => $order->id, 'seller_id' => $order->items()->value('seller_id'), 'courier_id' => $courier->id, 'service_type' => 'standard', 'origin_warehouse_id' => $hub->id, 'destination' => $address($number % 2 ? $zapote : $almanza, 'Demo delivery address '.($number + 1), 'Demo recipient '.($number + 1)), 'weight_grams' => 800 + $number * 150, 'dimensions' => ['length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10'], 'cod_amount' => $codAmount, 'tracking_number' => sprintf('DEMO-TRK-%04d', $number + 1)], $actor);
                $step = function (string $status, int $hours, ?string $note = null) use (&$shipment, &$clock, $service, $actor) {
                    $clock = $clock->copy()->addHours($hours); Carbon::setTestNow($clock);
                    $shipment = $service->transition($shipment, ['status' => $status, 'location' => 'Taguig sorting hub', 'note' => $note ?? 'Demo status update.'], 'manual', $actor);
                };
                $toDoor = function () use ($step) { foreach (['ready_for_pickup' => 2, 'picked_up' => 4, 'in_transit' => 6, 'out_for_delivery' => 12] as $status => $hours) { $step($status, $hours); } };
                $deliver = function (int $hours, bool $cash) use (&$shipment, &$clock, $service, $actor) {
                    $clock = $clock->copy()->addHours($hours); Carbon::setTestNow($clock);
                    $shipment = $service->proof($shipment, UploadedFile::fake()->createWithContent('demo-proof.png', base64_decode(self::PHOTO)), ['recipient_name' => 'Demo recipient', 'note' => 'Clearly fake demo delivery.', 'cash_collected' => $cash], $actor);
                };
                match ($final) {
                    'pending' => null,
                    'ready_for_pickup' => $step('ready_for_pickup', 2),
                    'picked_up' => [$step('ready_for_pickup', 2), $step('picked_up', 4)],
                    'in_transit' => [$step('ready_for_pickup', 2), $step('picked_up', 4), $step('in_transit', 6)],
                    'out_for_delivery', 'cod_out_for_delivery' => $toDoor(),
                    'failed_attempt' => [$toDoor(), $step('failed_attempt', 3, 'Recipient unavailable - nobody was home.')],
                    'returning', 'returned_to_sender' => [$toDoor(), $step('failed_attempt', 3, 'First delivery attempt failed - gate closed.'), $step('out_for_delivery', 20), $step('failed_attempt', 3, 'Demo attempt 2 failed.'), $step('out_for_delivery', 20), $step('failed_attempt', 3, 'Demo attempt 3 failed.'), $final === 'returned_to_sender' ? $step('returned_to_sender', 30) : null],
                    'delivered' => [$toDoor(), $deliver(3, false)],
                    'late' => [$toDoor(), $clock = $clock->copy()->addDays(4), $deliver(1, false)],
                    'cod_delivered' => [$toDoor(), $deliver(3, true)],
                    'cancelled' => $step('cancelled', 1, 'Seller cancelled before pickup.'),
                };
            }
        } finally {
            Carbon::setTestNow();
        }
    }
}