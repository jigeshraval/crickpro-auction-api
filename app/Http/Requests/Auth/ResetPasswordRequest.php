<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:128',
            'password_confirmation' => 'required|same:password',
        ];
    }
}
