<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_id'   => ['nullable', 'integer', 'exists:customers,id'],
            'code'          => ['required', 'string', 'max:255'],
            'note'          => ['nullable', 'string'],
        ];
    }
}
