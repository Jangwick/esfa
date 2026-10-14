<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer',
            'search' => 'nullable|string|max:255',
            'sort' => 'nullable|string|max:80',
            'export' => 'nullable|in:csv',
            'role' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:80',
            'action' => 'nullable|string|max:80',
            'entity_type' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer|exists:users,id',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'is_read' => 'nullable|boolean',
        ];
    }

    public function perPage(): int
    {
        return max(1, min(100, (int) $this->input('per_page', 20)));
    }
}
