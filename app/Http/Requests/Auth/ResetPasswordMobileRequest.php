<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordMobileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => 'required|string',
            'phoneCode' => 'required|string',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:128',
        ];
    }
}
