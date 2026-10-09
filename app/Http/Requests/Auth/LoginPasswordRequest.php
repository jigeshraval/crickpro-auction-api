<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
            'countryCode' => 'nullable|string|size:2|regex:/^[A-Za-z]{2}$/',
            'countryName' => 'nullable|string|max:100',
        ];
    }
}
