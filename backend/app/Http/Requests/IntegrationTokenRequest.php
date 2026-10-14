<?php

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('manage integrations');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1', 'max:'.count(IntegrationClient::SCOPES)],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(IntegrationClient::SCOPES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
