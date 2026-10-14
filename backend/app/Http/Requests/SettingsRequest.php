<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage settings') ?? false; }

    public function messages(): array { return ['sla_policies.prohibited' => 'Edit SLA policies on the Support page (SLA policies tab).']; }

    public function after(): array
    {
        return [function ($validator) {
            // BIL-7: the default plan is given without a charge or approval, so it must be active, free and take its
            // commission from the rules.
            if ($this->filled('default_plan_id') && ! $validator->errors()->has('default_plan_id') && ! \App\Models\Plan::whereKey($this->input('default_plan_id'))
                ->where('is_active', true)->where('monthly_price_amount', 0)->where('annual_price_amount', 0)->whereNull('commission_rate_bp')->exists()) {
                $validator->errors()->add('default_plan_id', 'Choose an active plan with both prices at 0 and no commission rate of its own.');
            }
        }];
    }

    public function rules(): array
    {
        return [
            'company_name' => 'sometimes|required|string|max:255',
            'support_email' => 'sometimes|required|email|max:255',
            'business_timezone' => ['sometimes', 'required', Rule::in(timezone_identifiers_list())],
            'currency' => 'sometimes|required|in:PHP',
            // SLA hours are edited only through PUT /support/sla-policies; this copy is the SlaPolicy fallback.
            'sla_policies' => 'prohibited',
            'auto_assign' => 'sometimes|required|boolean',
            'ticket_waiting_auto_resolve_days' => 'sometimes|required|integer|min:0|max:90',
            'ticket_auto_acknowledge' => 'sometimes|required|boolean',
            'risk_failed_attempts' => 'sometimes|required|integer|min:1|max:1000',
            'risk_amount_threshold' => 'sometimes|required|integer|min:1|max:9000000000000000',
            'settlement_schedule' => 'sometimes|required|in:weekly,monthly',
            'volumetric_divisor' => 'sometimes|required|integer|min:1|max:100000',
            'webhook_auto_recover' => 'sometimes|required|boolean',
            'auto_capture_authorized' => 'sometimes|required|boolean',
            'finance_reminder_hours' => 'sometimes|required|integer|min:0|max:168',
            'risk_auto_clear_min_captured' => 'sometimes|required|integer|min:0|max:1000',
            'risk_auto_clear_max_amount' => 'sometimes|required|integer|min:1|max:9000000000000000',
            'refund_auto_approve_cap_amount' => 'sometimes|required|integer|min:0|max:9000000000000000',
            'refund_auto_approve_daily_limit' => 'sometimes|required|integer|min:1|max:9000000000000000',
            'refund_auto_approve_reasons' => 'sometimes|array',
            'refund_auto_approve_reasons.*' => ['string', 'distinct', Rule::in(\App\Models\Refund::REASONS)],
            'risk_review_hold_hours' => 'sometimes|required|integer|min:0|max:168',
            'settlement_auto_finalize_days' => 'sometimes|required|integer|min:0|max:30',
            'settlement_carry_forward' => 'sometimes|required|boolean',
            'default_plan_id' => 'sometimes|nullable|integer|exists:plans,id',
            'token_expiry_reminder_days' => 'sometimes|required|integer|min:0|max:90',
            'notification_retention_days' => 'sometimes|required|integer|min:0|max:3650',
            'logistics_stuck_hours' => 'sometimes|required|integer|min:0|max:720',
            'logistics_auto_ticket' => 'sometimes|required|boolean',
            'logistics_courier_fill_gaps' => 'sometimes|required|boolean',
            'logistics_courier_pod' => 'sometimes|required|boolean',
            'ai_enabled' => 'sometimes|required|boolean',
            ...array_fill_keys(array_map(fn ($feature) => 'ai_'.$feature, \App\Services\AiService::FEATURES), 'sometimes|required|boolean'),
            'ai_daily_token_limit' => 'sometimes|required|integer|min:0|max:1000000000',
            'refund_window_days' => 'sometimes|required|integer|min:1|max:365',
            'refund_max_per_30_days' => 'sometimes|required|integer|min:0|max:100',
            'risk_amount_multiple' => 'sometimes|required|integer|min:1|max:1000',
            'risk_new_customer_days' => 'sometimes|required|integer|min:0|max:365',
        ];
    }
}
