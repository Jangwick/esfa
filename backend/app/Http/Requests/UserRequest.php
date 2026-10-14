<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'otp_email'] as $field) {
            if (is_string($this->input($field))) { $this->merge([$field => strtolower(trim($this->input($field)))]); }
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:255'],
            'email' => [$required, 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'min:8', 'max:1024', 'confirmed'],
            'roles' => [$required, 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'is_active' => 'sometimes|required|boolean',
            'otp_email' => 'sometimes|nullable|string|email|max:255',
            'permissions' => 'prohibited',
        ];
    }
}
