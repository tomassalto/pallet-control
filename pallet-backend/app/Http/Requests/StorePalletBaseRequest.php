<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePalletBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['nullable', 'string', 'max:255'],
            'note'                   => ['nullable', 'string', 'max:2000'],
            'items'                  => ['nullable', 'array'],
            'items.*.order_item_id'  => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
        ];
    }
}
