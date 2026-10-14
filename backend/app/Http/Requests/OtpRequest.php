<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OtpRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['challenge' => 'required|string|size:64', 'code' => $this->is('*/resend') ? 'prohibited' : 'required|string|regex:/^[0-9]{6}$/'];
    }
}
