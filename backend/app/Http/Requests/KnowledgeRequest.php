<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KnowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $module = $this->is('*/sla-policies') ? 'sla policies' : ($this->is('*/canned-responses*') ? 'canned responses' : 'knowledge base');

        return $this->user()?->can(($this->isMethod('POST') ? 'create' : 'edit').' '.$module) ?? false;
    }
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        if ($this->is('*/kb/categories*')) { return ['name' => [$required, 'required', 'string', 'max:255'], 'sort_order' => 'sometimes|integer|min:0|max:10000']; }
        if ($this->is('*/canned-responses*')) { return ['title' => [$required, 'required', 'string', 'max:255'], 'body' => [$required, 'required', 'string', 'max:10000'], 'is_shared' => 'sometimes|boolean']; }
        if ($this->is('*/sla-policies')) {
            return ['policies' => 'required|array:urgent,high,normal,low', 'policies.urgent' => 'required|array:first_response_hours,resolution_hours', 'policies.high' => 'required|array:first_response_hours,resolution_hours', 'policies.normal' => 'required|array:first_response_hours,resolution_hours', 'policies.low' => 'required|array:first_response_hours,resolution_hours', 'policies.*.first_response_hours' => 'required|integer|min:1|max:8760', 'policies.*.resolution_hours' => 'required|integer|min:1|max:8760|gte:policies.*.first_response_hours'];
        }

        return ['title' => [$required, 'required', 'string', 'max:255'], 'slug' => [$required, 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('kb_articles', 'slug')->ignore($this->route('article')?->id)], 'body' => [$required, 'required', 'string', 'max:100000'], 'category_id' => 'sometimes|nullable|integer|exists:kb_categories,id', 'status' => 'sometimes|in:draft,published'];
    }
}
