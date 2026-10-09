<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class StorePayoutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('ifsc')) {
            $this->merge(['ifsc' => strtoupper(trim((string) $this->input('ifsc')))]);
        }
        if ($this->filled('accountNumber')) {
            $this->merge(['accountNumber' => preg_replace('/\s+/', '', (string) $this->input('accountNumber'))]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:bank,vpa',
            'accountNumber' => ['exclude_unless:type,bank', 'required', 'string', 'regex:/^\d{9,18}$/'],
            'ifsc' => ['exclude_unless:type,bank', 'required', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'holderName' => 'exclude_unless:type,bank|required|string|min:2|max:120',
            'vpa' => ['exclude_unless:type,vpa', 'required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/'],
        ];
    }
}
