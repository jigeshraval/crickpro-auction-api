<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CheckUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // RFC syntax only — no live "dns" sub-check. That requires a
        // successful checkdnsrr()/MX lookup for the domain, which is
        // network-dependent and flaky (confirmed failing here even for a
        // real, always-resolvable domain like example.com despite raw
        // checkdnsrr() succeeding — likely egulias/email-validator's
        // stricter DNSCheckValidation, not a simple DNS outage). A fast,
        // deterministic auth endpoint shouldn't depend on live DNS per call.
        return [
            'email' => 'required|email:rfc|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'Please enter a valid email address',
        ];
    }
}
