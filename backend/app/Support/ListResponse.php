<?php

namespace App\Support;

use App\Http\Requests\ListRequest;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListResponse
{
    public static function sort(Builder $query, ListRequest $request, array $columns): Builder
    {
        $sort = $request->input('sort') ?: '-created_at';
        $column = ltrim($sort, '-');
        if (! in_array($column, $columns, true)) {
            throw ValidationException::withMessages(['sort' => ['The selected sort field is invalid.']]);
        }

        return $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id', 'desc');
    }

    public static function dates(Builder $query, ListRequest $request, string $column = 'created_at'): Builder
    {
        $zone = Setting::valueFor('business_timezone', config('sfa.timezone'));
        if ($request->filled('date_from')) {
            $query->where($column, '>=', CarbonImmutable::parse($request->date_from, $zone)->startOfDay()->utc());
        }
        if ($request->filled('date_to')) {
            $query->where($column, '<', CarbonImmutable::parse($request->date_to, $zone)->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    public static function paginate(Builder $query, ListRequest $request, array $summary)
    {
        return response()->json(array_merge($query->paginate($request->perPage())->withQueryString()->toArray(), ['summary' => $summary]));
    }

    /** Neutralize spreadsheet formulas (=, +, -, @, tab, CR, LF); plain numbers such as -150 stay numeric. */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return ! is_numeric($value) && preg_match('/^[=+@\-\t\r\n]/', $value) ? "'".$value : $value;
    }

    public static function csv(Builder $query, string $filename, array $headers, callable $row)
    {
        return response()->streamDownload(function () use ($query, $headers, $row) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            foreach ($query->cursor() as $record) {
                fputcsv($stream, array_map(self::cell(...), $row($record)));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
