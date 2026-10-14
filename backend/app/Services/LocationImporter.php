<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\City;
use App\Models\Province;
use App\Models\Region;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationImporter
{
    /**
     * Imports parsed PSGC rows (code, name, level, line and the optional regioncode, provincecode, citycode).
     * Every parent is checked before anything is written. Each level is then upserted in chunks keyed on the
     * unique code, so the number of queries grows with the number of chunks, not rows. Eloquent's upsert()
     * fills created_at and updated_at and leaves created_at alone on re-import.
     */
    public function import(array $rows, array $subMunCodes = []): array
    {
        return DB::transaction(function () use ($rows, $subMunCodes) {
            $levels = array_fill_keys(['region', 'province', 'city', 'municipality', 'barangay'], []);
            foreach ($rows as $row) { $levels[$row['level']][] = $row; }
            $subMun = array_flip($subMunCodes);
            $regions = array_flip(array_merge(Region::pluck('code')->all(), array_column($levels['region'], 'code')));
            $cityRegions = City::join('regions', 'regions.id', '=', 'cities.region_id')->pluck('regions.code as region_code', 'cities.code as city_code')->all();
            $tables = ['region' => [], 'province' => [], 'city' => [], 'barangay' => []];
            foreach ($levels['region'] as $row) { $tables['region'][$row['code']] = ['code' => $row['code'], 'name' => $row['name']]; }
            foreach (['province', 'city', 'municipality', 'barangay'] as $level) {
                foreach ($levels[$level] as $row) {
                    $code = $row['code'];
                    $region = $this->parent($row, 'regioncode', substr($code, 0, 2).'00000000');
                    if (! isset($regions[$region])) { $this->fail('region', $row); }
                    if ($level === 'province') { $tables['province'][$code] = ['code' => $code, 'name' => $row['name'], 'region_id' => $region]; continue; }
                    if ($level !== 'barangay') {
                        $tables['city'][$code] = ['code' => $code, 'name' => $row['name'], 'type' => $level, 'region_id' => $region, 'province_id' => $this->parent($row, 'provincecode', substr($code, 0, 5).'00000')];
                        $cityRegions[$code] = $region;
                        continue;
                    }
                    $city = substr($code, 0, 7).'000';
                    if (isset($subMun[$city])) { $city = substr($city, 0, 5).'00000'; } // Barangays under a Manila sub-municipality belong to the City of Manila.
                    $city = $this->parent($row, 'citycode', $city);
                    if (($cityRegions[$city] ?? null) !== $region) { $this->fail('city', $row); }
                    $tables['barangay'][$code] = ['code' => $code, 'name' => $row['name'], 'city_id' => $city];
                }
            }

            // Parent codes become ids one chunk at a time, after the parent level is written and its id map reloaded.
            $this->upsert(Region::class, $tables['region']);
            $regionIds = Region::pluck('id', 'code')->all();
            $this->upsert(Province::class, $tables['province'], fn ($row) => ['region_id' => $regionIds[$row['region_id']]] + $row);
            $provinceIds = Province::pluck('id', 'code')->all();
            $this->upsert(City::class, $tables['city'], fn ($row) => ['region_id' => $regionIds[$row['region_id']], 'province_id' => $provinceIds[$row['province_id']] ?? null] + $row);
            $cityIds = City::pluck('id', 'code')->all();
            $this->upsert(Barangay::class, $tables['barangay'], fn ($row) => ['city_id' => $cityIds[$row['city_id']]] + $row);

            return ['regions' => count($levels['region']), 'provinces' => count($levels['province']), 'cities' => count($levels['city']) + count($levels['municipality']), 'barangays' => count($levels['barangay'])];
        });
    }

    private function parent(array $row, string $column, string $derived): string
    {
        return ($row[$column] ?? '') !== '' ? $row[$column] : $derived;
    }

    private function fail(string $parent, array $row): never
    {
        throw ValidationException::withMessages(['file' => ["The parent {$parent} for CSV row {$row['line']} must be included or already imported."]]);
    }

    private function upsert(string $model, array $rows, ?Closure $ids = null): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            if ($ids) { $chunk = array_map($ids, $chunk); }
            $model::upsert($chunk, ['code'], array_values(array_diff(array_keys($chunk[0]), ['code'])));
        }
    }
}
