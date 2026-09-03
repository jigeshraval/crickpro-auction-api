<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

class ShowPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('player')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [];
    }
}
