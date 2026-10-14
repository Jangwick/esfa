<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('manage integrations');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'system_code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('integration_clients')->ignore($this->route('client'))],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // Read financial data of every storefront (read-only): analytics summary, subscriptions and statements.
            'reads_all_financials' => ['sometimes', 'boolean'],
        ];
    }
}
