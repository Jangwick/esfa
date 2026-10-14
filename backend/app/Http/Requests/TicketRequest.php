<?php

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof IntegrationClient || ($this->user()?->can(($this->isMethod('POST') ? 'create' : 'edit').' tickets') ?? false); }
    public function rules(): array
    {
        $create = $this->isMethod('POST'); $required = $create ? 'required' : 'sometimes'; $integration = $this->user() instanceof IntegrationClient;

        return [
            'subject' => [$required, 'required', 'string', 'max:255'], 'description' => [$required, 'required', 'string', 'max:20000'],
            // Nobody may choose 'system'; a system ticket keeps it when staff edit other fields.
            'channel' => [$required, Rule::in(Ticket::CHANNELS), Rule::notIn($this->route('ticket')?->channel === 'system' ? [] : ['system'])],'category' => [$required, Rule::in(Ticket::CATEGORIES)], 'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
            'requester_type' => [$create ? 'required' : 'prohibited', Rule::in(['customer', 'seller'])],
            'requester_id' => [$integration || ! $create ? 'prohibited' : 'required', 'integer', 'min:1'],
            'requester_external_id' => [$integration ? 'required' : 'prohibited', 'string', 'max:255'],
            'order_id' => [$integration ? 'prohibited' : 'sometimes', 'nullable', 'integer', 'exists:orders,id'],
            'payment_id' => [$integration ? 'prohibited' : 'sometimes', 'nullable', 'integer', 'exists:payments,id'],
            'shipment_id' => [$integration ? 'prohibited' : 'sometimes', 'nullable', 'integer', 'exists:shipments,id'],
            'order_external_id' => [$integration ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:255'],
            'payment_reference' => [$integration ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:255'],
            'shipment_reference' => [$integration ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:255'],
            'tags' => 'sometimes|nullable|array|max:20', 'tags.*' => 'string|max:40|distinct', 'status' => 'prohibited', 'assignee_id' => 'prohibited', 'source_system' => 'prohibited',
        ];
    }
}
