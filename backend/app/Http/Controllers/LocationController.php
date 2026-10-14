<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Province;
use App\Models\Region;
use App\Services\LocationImporter;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    public function index(ListRequest $request, string $kind)
    {
        abort_unless($request->user()->can('view locations') || $request->user()->can('view shipments'), 403);
        $request->validate(['region_id' => 'nullable|integer|exists:regions,id', 'province_id' => 'nullable|integer|exists:provinces,id', 'city_id' => 'nullable|integer|exists:cities,id']);
        $model = ['regions' => Region::class, 'provinces' => Province::class, 'cities' => City::class, 'barangays' => Barangay::class][$kind];
        $query = $model::query();
        if (in_array($kind, ['cities', 'provinces']) && $request->filled('region_id')) { $query->where('region_id', $request->region_id); }
        if ($kind === 'cities' && $request->filled('province_id')) { $query->where('province_id', $request->province_id); }
        if ($kind === 'barangays' && $request->filled('city_id')) { $query->where('city_id', $request->city_id); }
        if ($request->filled('search')) { $term = '%'.mb_strtolower($request->search).'%'; $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhere('code', 'like', $term)); }
        $query->orderBy('name')->orderBy('id');
        if ($request->export === 'csv') { return ListResponse::csv($query, $kind.'.csv', ['ID', 'PSGC code', 'Name'], fn ($row) => [$row->id, $row->code, $row->name]); }
        return ListResponse::paginate($query, $request, ['total' => (clone $query)->count()]);
    }

    public function import(Request $request)
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only Admins can import the official PSGC publication.');
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);
        $stream = fopen($request->file('file')->getRealPath(), 'r'); $headers = fgetcsv($stream);
        if (! $headers) { fclose($stream); throw ValidationException::withMessages(['file' => ['The CSV is empty.']]); }
        $headers = array_map(fn ($header) => preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/^\xEF\xBB\xBF/', '', trim($header)))), $headers);
        $alias = ['10digitpsgc' => 'code', '10digitpsgccode' => 'code', 'psgccode' => 'code', 'geographiclevel' => 'level', 'geolevel' => 'level'];
        $headers = array_map(fn ($header) => $alias[$header] ?? $header, $headers);
        if (count(array_intersect(['code', 'name', 'level'], $headers)) !== 3) { fclose($stream); throw ValidationException::withMessages(['file' => ['CSV headers must include 10-digit PSGC, Name, and Geographic Level (or code,name,level). Optional parent columns are region_code,province_code,city_code.']]); }
        $rows = []; $subMun = []; $line = 1;
        while (($values = fgetcsv($stream)) !== false) {
            $line++; if (! count(array_filter($values, fn ($value) => trim((string) $value) !== ''))) { continue; }
            if (count($rows) >= 60000) { fclose($stream); throw ValidationException::withMessages(['file' => ['The CSV has more rows than the PSGC publication.']]); } // keeps a padded 4 MB file within memory
            if (count($values) !== count($headers)) { fclose($stream); throw ValidationException::withMessages(['file' => ["CSV row {$line} has an incorrect number of columns."]]); }
            $row = array_combine($headers, array_map('trim', $values));
            if (! mb_check_encoding($row['name'], 'UTF-8')) { $row['name'] = mb_convert_encoding($row['name'], 'UTF-8', 'Windows-1252'); } // Excel's plain "CSV" export on Windows
            $level = strtolower($row['level']); $level = ['reg' => 'region', 'prov' => 'province', 'mun' => 'municipality', 'bgy' => 'barangay', 'brgy' => 'barangay'][$level] ?? $level;
            if (in_array($level, ['submun', 'submunicipality'], true) && preg_match('/^\d{10}$/', $row['code'])) { $subMun[] = $row['code']; }
            if (in_array($level, ['dist', 'district', 'submun', 'submunicipality'], true)) { continue; }
            // PSA grouping rows with a blank level, such as "City of Isabela (Not a Province)" and "Special Geographic Area", are not places.
            if ($level === '' && preg_match('/\(Not a Province\)|^Special Geographic Area$/i', $row['name'])) { continue; }
            if (! in_array($level, ['region', 'province', 'city', 'municipality', 'barangay'], true) || ! preg_match('/^\d{10}$/', $row['code']) || ! $row['name'] || strlen($row['name']) > 255) { fclose($stream); throw ValidationException::withMessages(['file' => ["CSV row {$line} needs an official 10-digit code, valid geographic level and name. Preserve leading zeroes when saving the PSA publication."]]); }
            $row['level'] = $level; $row['line'] = $line; $rows[] = $row;
        }
        fclose($stream);
        if (! $rows) { throw ValidationException::withMessages(['file' => ['No geographic records were found.']]); }
        $counts = app(LocationImporter::class)->import($rows, $subMun);
        $request->attributes->set('audit.entity_type', 'LocationImport'); $request->attributes->set('audit.new_values', $counts);
        return response()->json(['message' => 'PSGC locations imported successfully.', 'summary' => $counts]);
    }
}
