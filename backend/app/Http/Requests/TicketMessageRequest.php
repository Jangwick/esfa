<?php

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use Illuminate\Foundation\Http\FormRequest;

class TicketMessageRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof IntegrationClient || ($this->user()?->can('edit tickets') ?? false); }
    public function rules(): array
    {
        $integration = $this->user() instanceof IntegrationClient;

        // status: staff only, the ticket status to set after a public reply ("Reply and resolve").
        return ['body' => 'required|string|max:20000', 'is_internal_note' => $integration ? 'prohibited' : 'sometimes|boolean', 'status' => $integration || $this->boolean('is_internal_note') ? 'prohibited' : 'sometimes|nullable|in:waiting_on_customer,resolved', 'attachments' => 'sometimes|array|max:8', 'attachments.*' => 'file|max:4096|mimes:pdf,png,jpg,jpeg,webp,txt,csv'];
    }
    public function after(): array
    {
        return [function ($validator) { $bytes = 0; foreach ($this->file('attachments', []) as $file) { $bytes += $file->getSize(); } if ($bytes > 4 * 1024 * 1024) { $validator->errors()->add('attachments', 'Upload at most 4 MB in one message. Send additional files in another message.'); } }];
    }
}
