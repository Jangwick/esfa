<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Province;
use App\Models\Region;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Real delivery zones built from the imported PSGC locations. Business configuration, safe in production:
 * run it on purpose with `php artisan db:seed --class=PhilippineZoneSeeder` (add --force in production; it is not part of DatabaseSeeder).
 * It only creates or updates the six zones below by exact name; other zones and every rate are left alone.
 */
class PhilippineZoneSeeder extends Seeder
{
    /** name => [min days, max days, region codes, extra province codes, excluded province codes] */
    private const ZONES = [
        'Metro Manila' => [1, 2, ['1300000000'], [], []],
        'Greater Manila Area' => [1, 3, [], ['0301400000', '0402100000', '0403400000', '0405800000'], []],
        'North & Central Luzon' => [2, 5, ['0100000000', '0200000000', '0300000000', '1400000000'], [], ['0301400000']],
        'South Luzon & Bicol' => [2, 5, ['0400000000', '1700000000', '0500000000'], [], ['0402100000', '0403400000', '0405800000']],
        'Visayas' => [3, 7, ['0600000000', '0700000000', '0800000000', '1800000000'], [], []],
        'Mindanao' => [4, 8, ['0900000000', '1000000000', '1100000000', '1200000000', '1600000000', '1900000000'], [], []],
    ];

    public function run(): void
    {
        $codes = array_merge(...array_column(self::ZONES, 2));
        $regionIds = Region::whereIn('code', $codes)->pluck('id', 'code');
        $missing = array_diff($codes, $regionIds->keys()->all());
        if ($missing || ! Province::exists()) {
            throw new RuntimeException('Import the PSGC locations first (Logistics > Locations > Import), then run this seeder again.'.($missing ? ' Missing region codes: '.implode(', ', $missing).'.' : ' No provinces were found.'));
        }

        $provinces = Province::get(['id', 'code', 'region_id']);
        $provinceless = City::whereNull('province_id')->get(['id', 'code', 'region_id']);
        $prefix = fn (string $code) => substr($code, 0, 5); // PSGC: 2-digit region + 3-digit province
        $ids = fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $zones = [];
        foreach (self::ZONES as $name => [$min, $max, $regions, $extra, $excluded]) {
            $inRegions = $regionIds->only($regions)->map(fn ($id) => (int) $id)->all();
            [$extraPrefixes, $excludedPrefixes] = [array_map($prefix, $extra), array_map($prefix, $excluded)];
            $zones[$name] = [
                'min_days' => $min,
                'max_days' => $max,
                'province_ids' => $ids($provinces->filter(fn ($p) => (in_array((int) $p->region_id, $inRegions, true) || in_array($p->code, $extra, true)) && ! in_array($p->code, $excluded, true))),
                // Province-less cities (NCR, HUCs, BARMM SGA) belong to their region, unless their code sits under an excluded or extra province.
                'city_ids' => $ids($provinceless->filter(fn ($c) => (in_array((int) $c->region_id, $inRegions, true) || in_array($prefix($c->code), $extraPrefixes, true)) && ! in_array($prefix($c->code), $excludedPrefixes, true))),
            ];
            if (! $zones[$name]['province_ids'] && ! $zones[$name]['city_ids']) {
                throw new RuntimeException("Zone \"{$name}\" would have no provinces or cities. Import the PSGC locations first (Logistics > Locations > Import), then run this seeder again.");
            }
        }

        DB::transaction(function () use ($zones) {
            foreach ($zones as $name => $values) {
                Zone::orderBy('id')->firstOrNew(['name' => $name], ['is_active' => true])->fill($values)->save();
            }
        });

        foreach ($zones as $name => $values) {
            $this->command?->info(sprintf('%s: %d provinces, %d cities', $name, count($values['province_ids']), count($values['city_ids'])));
        }
        $covered = array_merge(...array_column($zones, 'city_ids'));
        $uncovered = City::whereNotIn('id', $covered)->where(fn ($q) => $q->whereNull('province_id')->orWhereNotIn('province_id', array_merge(...array_column($zones, 'province_ids'))))->count();
        if ($uncovered) { $this->command?->warn("{$uncovered} cities or municipalities are in none of these zones (for example a region outside the PSGC 2Q 2026 list)."); }
    }
}
