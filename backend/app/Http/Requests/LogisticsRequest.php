<?php

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use App\Models\Courier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogisticsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof IntegrationClient || (bool) $this->user()?->can($this->isMethod('POST') ? 'create logistics' : 'edit logistics'); }

    public function rules(): array
    {
        $kind = $this->route('kind') ?? $this->route()->defaults['kind'] ?? 'shipment';
        return match ($kind) {
            'couriers' => [
                'name' => 'required|string|max:255', 'code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('couriers')->ignore($this->route('record'))],
                'service_types' => 'required|array|min:1', 'service_types.*' => ['required', 'distinct', Rule::in(Courier::SERVICES)],
                'tracking_url_template' => 'nullable|string|max:2048', 'is_active' => 'required|boolean', 'volumetric_divisor' => 'nullable|integer|min:1|max:100000', 'rotate_secret' => 'sometimes|boolean', 'webhook_secret' => 'prohibited',
            ],
            'zones' => ['name' => 'required|string|max:255', 'min_days' => 'required|integer|min:0|max:365', 'max_days' => 'required|integer|min:0|max:365|gte:min_days', 'province_ids' => 'present|array', 'province_ids.*' => 'required|integer|distinct|exists:provinces,id', 'city_ids' => 'present|array', 'city_ids.*' => 'required|integer|distinct|exists:cities,id', 'is_active' => 'required|boolean'],
            'rates' => ['courier_id' => 'required|integer|exists:couriers,id', 'service_type' => ['required', Rule::in(Courier::SERVICES)], 'origin_zone_id' => 'required|integer|exists:zones,id', 'destination_zone_id' => 'required|integer|exists:zones,id', 'base_weight_grams' => 'required|integer|min:1|max:100000000', 'base_fee_amount' => 'required|integer|min:0|max:9000000000000000', 'additional_per_500g_amount' => 'required|integer|min:0|max:9000000000000000', 'cod_fee_bp' => 'required|integer|min:0|max:10000', 'max_weight_grams' => 'required|integer|gte:base_weight_grams|max:100000000', 'currency' => 'sometimes|in:PHP', 'is_active' => 'required|boolean'],
            'warehouses' => ['name' => 'required|string|max:255', 'code' => ['required', 'string', 'max:80', Rule::unique('warehouses')->ignore($this->route('record'))], 'type' => 'required|in:platform_hub,seller_pickup', 'seller_id' => 'nullable|required_if:type,seller_pickup|integer|exists:sellers,id', 'address' => 'required|array', 'is_active' => 'required|boolean'],
            default => ['order_id' => 'required_without:order_external_id|integer|exists:orders,id', 'order_external_id' => 'required_without:order_id|string|max:255', 'seller_id' => 'required_without:seller_external_id|integer|exists:sellers,id', 'seller_external_id' => 'required_without:seller_id|string|max:255', 'courier_id' => 'nullable|required_with:service_type|integer|exists:couriers,id', 'service_type' => ['nullable', 'required_with:courier_id', Rule::in(Courier::SERVICES)], 'origin_warehouse_id' => 'required|integer|exists:warehouses,id', 'destination' => 'required|array', 'weight_grams' => 'required|integer|min:1|max:100000000', 'dimensions' => 'required|array:length_cm,width_cm,height_cm|required_array_keys:length_cm,width_cm,height_cm', 'dimensions.*' => 'required|numeric|min:0.01|max:10000|decimal:0,2', 'tracking_number' => 'nullable|string|max:100', 'cod_amount' => 'required|integer|min:0|max:9000000000000000', 'rate_id' => 'nullable|integer|exists:shipping_rates,id', 'status' => 'prohibited', 'shipping_fee_amount' => 'prohibited'],
        };
    }
}
