<?php

namespace Database\Seeders;

use App\Models\Courier;
use App\Models\Rate;
use App\Models\Zone;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SAMPLE couriers and SAMPLE rates for demo and test databases only, so quotes, booking and checkout can be tried;
 * the owner enters the real contract rates on the live site. Run after PhilippineZoneSeeder:
 * php artisan db:seed --class=SampleCourierRateSeeder. Not part of DatabaseSeeder; never writes in production.
 *
 * The prices are illustrative, NOT the couriers' real tariffs. Base fee by distance class between the two zones
 * (centavos): same zone 9500, Metro Manila <-> Greater Manila Area 11000, two different Luzon zones 14500,
 * Luzon <-> Visayas 19500, Luzon <-> Mindanao 22500, Visayas <-> Mindanao 18500. Express is standard x 1.5, then the
 * courier's adjustment in basis points; each step is rounded half-up to whole pesos. Base weight 1 kg, additional
 * 500 g PHP 25 (express PHP 35), COD fee 1.5%, max weight 30 kg (express 20 kg).
 *
 * Only the six couriers below and their rates are created or updated; other couriers, their rates and shipments are
 * never touched. An existing courier keeps its webhook secret and on/off switch.
 */
class SampleCourierRateSeeder extends Seeder
{
    public const LUZON = ['Metro Manila', 'Greater Manila Area', 'North & Central Luzon', 'South Luzon & Bicol'];
    public const ZONES = [...self::LUZON, 'Visayas', 'Mindanao'];
    public const SERVICES = ['standard', 'express'];

    /** Courier code => [name, base-fee adjustment in basis points]. */
    public const COURIERS = [
        'jnt-express' => ['J&T Express (sample)', 0],
        'lbc-express' => ['LBC Express (sample)', 1500],
        'ninja-van' => ['Ninja Van (sample)', 500],
        'flash-express' => ['Flash Express (sample)', -500],
        'twogo-express' => ['2GO Express (sample)', 1000], // codes must start with a letter (LogisticsRequest)
        'jrs-express' => ['JRS Express (sample)', 800],
    ];

    /** Sample base fee in centavos for one route; pure so it can be tested on its own. */
    public static function baseFee(string $origin, string $destination, string $service, int $adjustmentBp): int
    {
        $luzon = fn (string $zone) => in_array($zone, self::LUZON, true);
        $pair = [$origin, $destination];
        sort($pair);
        $fee = match (true) {
            $origin === $destination => 9500,
            $pair === ['Greater Manila Area', 'Metro Manila'] => 11000,
            $luzon($origin) && $luzon($destination) => 14500,
            $pair === ['Mindanao', 'Visayas'] => 18500,
            in_array('Visayas', $pair, true) => 19500,
            default => 22500, // Luzon <-> Mindanao
        };
        $wholePesos = fn (int $amount, int $numerator, int $denominator) => Money::halfUp($amount, $numerator, $denominator * 100) * 100;
        if ($service === 'express') { $fee = $wholePesos($fee, 3, 2); }

        return $wholePesos($fee, 10000 + $adjustmentBp, 10000);
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipped: sample couriers and rates are never created in production.');
            return;
        }

        $zones = Zone::whereIn('name', self::ZONES)->orderBy('id')->get()->unique('name')->keyBy('name');
        $missing = array_diff(self::ZONES, $zones->keys()->all());
        if ($missing) {
            throw new RuntimeException('Missing delivery zones: '.implode(', ', $missing).'. Run php artisan db:seed --class=PhilippineZoneSeeder first, then run this seeder again.');
        }

        $counts = DB::transaction(function () use ($zones) {
            $counts = [];
            foreach (self::COURIERS as $code => [$name, $adjustmentBp]) {
                $courier = Courier::firstOrNew(['code' => $code], ['is_active' => true, 'webhook_secret' => 'courier_'.Str::random(64), 'tracking_url_template' => null]);
                // Verified public tracking links are added by the owner later; none is guessed here.
                $courier->fill(['name' => $name, 'service_types' => self::SERVICES, 'volumetric_divisor' => 3500])->save(); // a tracking link the owner adds by hand survives a rerun
                $counts[$code] = 0;
                foreach (self::SERVICES as $service) {
                    foreach ($zones as $from) {
                        foreach ($zones as $to) {
                            Rate::updateOrCreate(
                                ['courier_id' => $courier->id, 'service_type' => $service, 'origin_zone_id' => $from->id, 'destination_zone_id' => $to->id],
                                ['base_weight_grams' => 1000, 'base_fee_amount' => self::baseFee($from->name, $to->name, $service, $adjustmentBp), 'additional_per_500g_amount' => $service === 'express' ? 3500 : 2500, 'cod_fee_bp' => 150, 'max_weight_grams' => $service === 'express' ? 20000 : 30000, 'currency' => 'PHP', 'is_active' => true],
                            );
                            $counts[$code]++;
                        }
                    }
                }
            }
            return $counts;
        });

        foreach ($counts as $code => $rates) {
            $this->command?->info(sprintf('%s: %s, %d sample rates', $code, self::COURIERS[$code][0], $rates));
        }
    }
}
