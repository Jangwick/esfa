<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Validation\Rule;

class SupportListRequest extends ListRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['status' => 'nullable|string|max:80', 'priority' => ['nullable', Rule::in(Ticket::PRIORITIES)], 'category' => ['nullable', Rule::in(Ticket::CATEGORIES)], 'channel' => ['nullable', Rule::in(Ticket::CHANNELS)], 'sla_state' => 'nullable|in:on_track,at_risk,breached,met', 'assignee' => 'nullable|string|max:30', 'category_id' => 'nullable|integer|exists:kb_categories,id', 'is_shared' => 'nullable|boolean']);
    }
}
