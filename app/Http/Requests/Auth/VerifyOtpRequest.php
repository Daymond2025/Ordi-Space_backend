<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'code' => ['required', 'string', 'size:'.OTP_LONGUEUR],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
