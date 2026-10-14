<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketActionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof \App\Models\IntegrationClient || ($this->user()?->can('edit tickets') ?? false); }
    public function rules(): array
    {
        if ($this->is('*/assign')) { return ['assignee_id' => ['required', function ($attribute, $value, $fail) { if ($value !== 'me' && (! ctype_digit((string) $value) || (int) $value < 1)) { $fail('Choose an active Support Agent, Admin or me.'); } }]]; }
        if ($this->is('*/csat')) { return ['score' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:2000']; }

        return ['status' => ['required', Rule::in(Ticket::STATUSES)], 'note' => 'nullable|string|max:2000'];
    }
}
