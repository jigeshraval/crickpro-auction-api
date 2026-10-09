<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginPasswordMobileRequest extends FormRequest
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
            'password' => 'required|string',
            'countryCode' => 'nullable|string|size:2|regex:/^[A-Za-z]{2}$/',
            'countryName' => 'nullable|string|max:100',
        ];
    }
}
