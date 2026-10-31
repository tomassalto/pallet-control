<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePendingItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'exists:orders,id'],
            'order_item_id' => ['required', 'exists:order_items,id'],
            'qty_missing' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
