<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH v1/auth/user — currently only the country (app syncs it for existing sessions). */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'countryCode' => 'required|string|size:2|regex:/^[A-Za-z]{2}$/',
            'countryName' => 'nullable|string|max:100',
        ];
    }
}
