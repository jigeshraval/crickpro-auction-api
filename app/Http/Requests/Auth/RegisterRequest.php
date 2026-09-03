<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => "required|string|min:2|max:100|regex:/^[a-zA-Z\s\.\-']+$/",
            'email' => 'nullable|email|max:255|required_without:mobile',
            'mobile' => 'nullable|string|max:20|required_without:email',
            'phoneCode' => 'nullable|string|max:5|required_with:mobile',
            'password' => 'required|string|min:8|max:128',
            'countryCode' => 'required|string|max:5',
            'termsAccepted' => 'required',
            'profileImage' => 'nullable|image|max:5120',
        ];
    }
}
