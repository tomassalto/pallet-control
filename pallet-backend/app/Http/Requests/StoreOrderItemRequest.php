<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'min:1'],
            'ean' => ['nullable', 'string', 'regex:/^\d{8,15}$/'],
            'last4' => ['nullable', 'string', 'regex:/^\d{1,4}$/'],
        ];
    }
}
