<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) { $this->merge(['email' => strtolower(trim($this->email))]); }
    }

    public function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:255', 'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)], 'roles' => 'prohibited', 'permissions' => 'prohibited', 'is_active' => 'prohibited', 'otp_email' => 'prohibited'];
    }
}
