<?php

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user() instanceof User && $this->user()->can('manage integrations'))
            || ($this->user() instanceof IntegrationClient && $this->user()->tokenCan('webhooks:manage'));
    }

    public function rules(): array
    {
        return [
            'client_id' => $this->user() instanceof IntegrationClient
                ? ['prohibited']
                : ['required', 'integer', 'exists:integration_clients,id'],
            'url' => ['required', 'string', 'max:2048', new SafeWebhookUrl],
            'events' => ['required', 'array', 'min:1', 'max:'.count(WebhookEndpoint::EVENTS)],
            'events.*' => ['required', 'string', 'distinct', Rule::in(WebhookEndpoint::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
            'secret' => ['prohibited'],
        ];
    }
}
