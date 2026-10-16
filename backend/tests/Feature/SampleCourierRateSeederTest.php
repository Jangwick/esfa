<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Courier;
use App\Models\Rate;
use App\Models\Region;
use App\Models\Zone;
use App\Services\LogisticsService;
use Database\Seeders\SampleCourierRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SampleCourierRateSeederTest extends TestCase
{
    use RefreshDatabase;

    private const NAMES = [
        'jnt-express' => 'J&T Express (sample)', 'lbc-express' => 'LBC Express (sample)', 'ninja-van' => 'Ninja Van (sample)',
        'flash-express' => 'Flash Express (sample)', 'twogo-express' => '2GO Express (sample)', 'jrs-express' => 'JRS Express (sample)',
    ];

    /** The six delivery zones, each holding one province-less city (zone name => city name). */
    private function zones(?string $skip = null): void
    {
        $cities = ['Metro Manila' => 'City of Makati', 'Greater Manila Area' => 'City of Bacoor', 'North & Central Luzon' => 'City of Baguio', 'South Luzon & Bicol' => 'City of Legazpi', 'Visayas' => 'Cebu City', 'Mindanao' => 'Davao City'];
        $index = 0;
        foreach ($cities as $zone => $name) {
            $index++;
            $region = Region::create(['code' => sprintf('%02d00000000', $index), 'name' => "Region of {$name}"]);
            $city = City::create(['code' => sprintf('%02d01000000', $index), 'name' => $name, 'type' => 'city', 'region_id' => $region->id, 'province_id' => null]);
            if ($zone !== $skip) {
                Zone::create(['name' => $zone, 'min_days' => $index, 'max_days' => $index + 2, 'province_ids' => [], 'city_ids' => [$city->id], 'is_active' => true]);
            }
        }
    }

    private function fee(string $code, string $service, string $from, string $to): int
    {
        $zone = fn (string $name) => Zone::where('name', $name)->value('id');

        return Rate::where('courier_id', Courier::where('code', $code)->value('id'))->where('service_type', $service)
            ->where('origin_zone_id', $zone($from))->where('destination_zone_id', $zone($to))->sole()->base_fee_amount;
    }

    private function demoCourier(): array
    {
        $zone = Zone::where('name', 'Metro Manila')->firstOrFail();
        $courier = Courier::create(['name' => 'Archon Nell In-house Fleet', 'code' => 'demo-courier', 'service_types' => ['standard'], 'webhook_secret' => 'courier_demo', 'volumetric_divisor' => 3500, 'tracking_url_template' => 'https://courier.example.com/track?tracking={tracking_number}', 'is_active' => true]);
        $rate = Rate::create(['courier_id' => $courier->id, 'service_type' => 'standard', 'origin_zone_id' => $zone->id, 'destination_zone_id' => $zone->id, 'base_weight_grams' => 1000, 'base_fee_amount' => 8000, 'additional_per_500g_amount' => 1500, 'cod_fee_bp' => 100, 'max_weight_grams' => 20000, 'is_active' => true]);

        return [$courier, $rate];
    }

    public function test_it_creates_six_sample_couriers_and_432_integer_rates(): void
    {
        $this->zones();
        $this->artisan('db:seed', ['--class' => SampleCourierRateSeeder::class])
            ->expectsOutputToContain('lbc-express: LBC Express (sample), 72 sample rates')
            ->assertSuccessful();

        $couriers = Courier::orderBy('id')->get();
        $this->assertSame(self::NAMES, $couriers->pluck('name', 'code')->all());
        foreach ($couriers as $courier) {
            $this->assertSame([['standard', 'express'], 3500, null, true], [$courier->service_types, $courier->volumetric_divisor, $courier->tracking_url_template, $courier->is_active]);
            $this->assertStringStartsWith('courier_', $courier->webhook_secret);
        }
        $this->assertSame(432, Rate::count());
        $this->assertSame(432, Rate::where('is_active', true)->where('currency', 'PHP')->where('base_weight_grams', 1000)->where('cod_fee_bp', 150)->count());

        // Spot checks: rounding is half-up to whole pesos, express before the courier adjustment.
        $this->assertSame(9500, $this->fee('jnt-express', 'standard', 'Visayas', 'Visayas'));
        $this->assertSame(38900, $this->fee('lbc-express', 'express', 'Metro Manila', 'Mindanao')); // 22500 x 1.5 = 33750 -> 33800; +15% = 38870 -> 38900
        $this->assertSame(17600, $this->fee('flash-express', 'standard', 'Visayas', 'Mindanao')); // 18500 - 5% = 17575 -> 17600
        $this->assertSame(11000, $this->fee('jnt-express', 'standard', 'Greater Manila Area', 'Metro Manila'));
        $this->assertSame(14500, $this->fee('jnt-express', 'standard', 'North & Central Luzon', 'South Luzon & Bicol'));
        $this->assertSame(19500, $this->fee('jnt-express', 'standard', 'South Luzon & Bicol', 'Visayas'));
        $this->assertSame(SampleCourierRateSeeder::baseFee('Metro Manila', 'Mindanao', 'express', 1500), 38900);

        $raw = DB::table('shipping_rates')->get();
        $fees = [];
        foreach ($raw as $rate) {
            foreach (['base_weight_grams', 'base_fee_amount', 'additional_per_500g_amount', 'cod_fee_bp', 'max_weight_grams'] as $column) { $this->assertIsInt($rate->{$column}); }
            $this->assertSame(0, $rate->base_fee_amount % 100, 'Sample fees are whole pesos.');
            $this->assertSame($rate->service_type === 'express' ? [3500, 20000] : [2500, 30000], [$rate->additional_per_500g_amount, $rate->max_weight_grams]);
            $fees["{$rate->courier_id}|{$rate->service_type}|{$rate->origin_zone_id}|{$rate->destination_zone_id}"] = $rate->base_fee_amount;
        }
        foreach ($raw as $rate) {
            $this->assertSame($rate->base_fee_amount, $fees["{$rate->courier_id}|{$rate->service_type}|{$rate->destination_zone_id}|{$rate->origin_zone_id}"], 'A route and its reverse cost the same.');
        }
    }

    public function test_rerunning_keeps_counts_and_secrets_and_leaves_other_couriers_alone(): void
    {
        $this->zones();
        [$demo, $demoRate] = $this->demoCourier();
        [$demoBefore, $demoRateBefore] = [$demo->fresh()->getAttributes(), $demoRate->fresh()->getAttributes()];

        (new SampleCourierRateSeeder)->run();
        $secrets = DB::table('couriers')->orderBy('id')->pluck('webhook_secret', 'code')->all();
        Rate::whereHas('courier', fn ($q) => $q->where('code', 'jnt-express'))->update(['base_fee_amount' => 1]);
        Courier::where('code', 'ninja-van')->update(['is_active' => false]);
        Courier::where('code', 'lbc-express')->update(['tracking_url_template' => 'https://example.test/track/{tracking_number}']);
        (new SampleCourierRateSeeder)->run();

        $this->assertSame([7, 433], [Courier::count(), Rate::count()]);
        $this->assertSame('https://example.test/track/{tracking_number}', Courier::where('code', 'lbc-express')->value('tracking_url_template'), 'A tracking link the owner added survives a rerun.');
        foreach (Courier::where('name', 'like', '%(sample)')->pluck('code') as $code) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $code, "{$code} must pass the courier code rule so the owner can edit it.");
        }
        $this->assertSame($secrets, DB::table('couriers')->orderBy('id')->pluck('webhook_secret', 'code')->all(), 'Secrets are never rotated.');
        $this->assertStringStartsWith('courier_', Crypt::decryptString($secrets['jnt-express']));
        $this->assertSame(9500, $this->fee('jnt-express', 'standard', 'Metro Manila', 'Metro Manila'), 'A rerun restores the sample prices.');
        $this->assertFalse(Courier::where('code', 'ninja-van')->value('is_active'), 'A rerun keeps the on/off switch of an existing courier.');
        $this->assertSame($demoBefore, $demo->fresh()->getAttributes());
        $this->assertSame($demoRateBefore, $demoRate->fresh()->getAttributes());
    }

    public function test_it_writes_nothing_in_production(): void
    {
        $this->zones();
        $this->app['env'] = 'production';
        try {
            $this->artisan('db:seed', ['--class' => SampleCourierRateSeeder::class, '--force' => true])
                ->expectsOutputToContain('never created in production')
                ->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame([0, 0], [Courier::count(), Rate::count()]);
    }

    public function test_it_refuses_to_run_without_all_six_zones_and_writes_nothing(): void
    {
        $this->zones(skip: 'Mindanao');
        $this->assertThrows(fn () => (new SampleCourierRateSeeder)->run(), RuntimeException::class, 'Missing delivery zones: Mindanao. Run php artisan db:seed --class=PhilippineZoneSeeder first');
        $this->assertSame([0, 0], [Courier::count(), Rate::count()]);
    }

    public function test_a_metro_manila_to_cebu_quote_offers_every_sample_courier(): void
    {
        $this->zones();
        (new SampleCourierRateSeeder)->run();

        $quotes = app(LogisticsService::class)->quotes([
            'origin_city_id' => City::where('name', 'City of Makati')->value('id'), 'destination_city_id' => City::where('name', 'Cebu City')->value('id'),
            'weight_grams' => 1000, 'dimensions' => ['length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10'], 'cod_amount' => 0,
        ]);

        $this->assertCount(12, $quotes);
        $this->assertEqualsCanonicalizing(array_values(self::NAMES), array_values(array_unique(array_column($quotes, 'courier_name'))));
        // Flash standard is cheapest: 19500 - 5% = 18525 -> 18500.
        $this->assertSame(['Flash Express (sample)', 'standard', 18500, true], [$quotes[0]['courier_name'], $quotes[0]['service_type'], $quotes[0]['total_amount'], $quotes[0]['recommended']]);
    }
}
