<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Http\Requests\LogisticsRequest;
use App\Models\Courier;
use App\Models\Rate;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\LogisticsService;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogisticsConfigController extends Controller
{
    private function model(string $kind): string { return ['couriers' => Courier::class, 'zones' => Zone::class, 'rates' => Rate::class, 'warehouses' => Warehouse::class][$kind]; }
    private function relations(string $kind): array { return match ($kind) { 'rates' => ['courier', 'originZone', 'destinationZone'], 'warehouses' => ['seller', 'address.region', 'address.province', 'address.city', 'address.barangay'], default => [] }; }

    public function index(ListRequest $request, string $kind)
    {
        $this->requirePermission('view '.$kind); $model = $this->model($kind); $query = $model::with($this->relations($kind));
        $request->validate(['is_active' => 'nullable|boolean', 'courier_id' => 'nullable|integer|exists:couriers,id']);
        if ($request->filled('is_active')) { $query->where('is_active', $request->boolean('is_active')); }
        if ($kind === 'rates' && $request->filled('courier_id')) { $query->where('courier_id', $request->courier_id); }
        if ($request->filled('search')) { $term = '%'.mb_strtolower($request->search).'%'; $query->where(fn ($q) => $kind === 'rates' ? $q->whereHas('courier', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))->orWhereRaw('LOWER(service_type) LIKE ?', [$term]) : $q->whereRaw('LOWER(name) LIKE ?', [$term])); }
        $query = ListResponse::sort($query, $request, $kind === 'rates' ? ['created_at', 'base_fee_amount'] : ['created_at', 'name']);
        $summary = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('is_active', true)->count(), 'inactive' => (clone $query)->where('is_active', false)->count()];
        if ($request->export === 'csv') {
            $headers = match ($kind) { 'couriers' => ['ID', 'Name', 'Code', 'Services', 'Active'], 'zones' => ['ID', 'Name', 'Provinces', 'Cities', 'Min days', 'Max days', 'Active'], 'rates' => ['ID', 'Courier', 'Service', 'Origin', 'Destination', 'Base grams', 'Base fee centavos', 'Additional per 500g centavos', 'COD basis points', 'Max grams', 'Active'], 'warehouses' => ['ID', 'Name', 'Code', 'Type', 'Seller', 'Address', 'City', 'Active'] };
            return ListResponse::csv($query, $kind.'.csv', $headers, fn ($row) => match ($kind) { 'couriers' => [$row->id, $row->name, $row->code, implode(';', $row->service_types), $row->is_active], 'zones' => [$row->id, $row->name, implode(';', $row->province_ids), implode(';', $row->city_ids), $row->min_days, $row->max_days, $row->is_active], 'rates' => [$row->id, $row->courier->name, $row->service_type, $row->originZone->name, $row->destinationZone->name, $row->base_weight_grams, $row->base_fee_amount, $row->additional_per_500g_amount, $row->cod_fee_bp, $row->max_weight_grams, $row->is_active], 'warehouses' => [$row->id, $row->name, $row->code, $row->type, $row->seller?->shop_name, $row->address->line1, $row->address->city->name, $row->is_active] });
        }
        return ListResponse::paginate($query, $request, $summary);
    }

    public function store(LogisticsRequest $request, string $kind) { $this->requirePermission('create '.$kind); return $this->save($request, $kind); }
    public function update(LogisticsRequest $request, string $kind, string $record) { $this->requirePermission('edit '.$kind); return $this->save($request, $kind, $record); }

    private function save(LogisticsRequest $request, string $kind, ?string $record = null)
    {
        return DB::transaction(function () use ($request, $kind, $record) {
            $model = $this->model($kind); $row = $record ? $model::findOrFail($record) : new $model; $values = $request->validated(); $old = $record ? $row->toArray() : null; $secret = null;
            if ($kind === 'couriers') {
                if ($values['tracking_url_template'] ?? null) { $url = $values['tracking_url_template']; if (! str_contains($url, '{tracking_number}') || ! str_starts_with($url, 'https://') || ! filter_var(str_replace('{tracking_number}', 'example', $url), FILTER_VALIDATE_URL)) { throw ValidationException::withMessages(['tracking_url_template' => ['Use an HTTPS tracking URL containing {tracking_number}.']]); } }
                if (! $record || ($values['rotate_secret'] ?? false)) { $secret = 'courier_'.Str::random(64); $values['webhook_secret'] = $secret; } unset($values['rotate_secret']);
            }
            if ($kind === 'zones') { if (! count($values['province_ids']) && ! count($values['city_ids'])) { throw ValidationException::withMessages(['city_ids' => ['A zone must contain at least one province or city.']]); } $values['province_ids'] = array_map('intval', $values['province_ids']); $values['city_ids'] = array_map('intval', $values['city_ids']); }
            if ($kind === 'rates') {
                $courier = Courier::findOrFail($values['courier_id']); if (! in_array($values['service_type'], $courier->service_types, true)) { throw ValidationException::withMessages(['service_type' => ['This courier does not offer the selected service.']]); }
                $duplicate = Rate::where('courier_id', $values['courier_id'])->where('service_type', $values['service_type'])->where('origin_zone_id', $values['origin_zone_id'])->where('destination_zone_id', $values['destination_zone_id'])->when($record, fn ($q) => $q->where('id', '!=', $record))->exists();
                if ($duplicate) { throw ValidationException::withMessages(['rate' => ['A rate already exists for this courier, service and route.']]); } $values['currency'] = 'PHP';
            }
            if ($kind === 'warehouses') { $address = app(LogisticsService::class)->address($values['address']); unset($values['address']); $values['address_id'] = $address->id; if ($values['type'] === 'platform_hub') { $values['seller_id'] = null; } }
            $row->fill($values)->save(); $row->load($this->relations($kind));
            $request->attributes->set('audit.entity_type', class_basename($row)); $request->attributes->set('audit.entity_id', $row->id); $request->attributes->set('audit.old_values', $old); $request->attributes->set('audit.new_values', $row->toArray());
            return response()->json(array_merge($row->toArray(), $secret ? ['webhook_secret' => $secret] : []), $record ? 200 : 201);
        });
    }

    public function destroy(Request $request, string $kind, string $record)
    {
        $this->requirePermission('delete '.$kind); $model = $this->model($kind); $row = $model::findOrFail($record);
        $used = match ($kind) { 'couriers' => Shipment::where('courier_id', $row->id)->exists() || Rate::where('courier_id', $row->id)->exists(), 'zones' => Rate::where('origin_zone_id', $row->id)->orWhere('destination_zone_id', $row->id)->exists(), 'warehouses' => Shipment::where('origin_warehouse_id', $row->id)->exists(), default => false };
        if ($used) { throw ValidationException::withMessages(['record' => ['This record is in use. Deactivate it instead of deleting it.']]); }
        $request->attributes->set('audit.entity_type', class_basename($row)); $request->attributes->set('audit.entity_id', $row->id); $request->attributes->set('audit.old_values', $row->toArray()); $row->delete();
        return response()->json(['message' => 'Record deleted successfully.']);
    }
}
