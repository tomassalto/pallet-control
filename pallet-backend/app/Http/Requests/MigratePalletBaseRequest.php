<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MigratePalletBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.order_item_id'  => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'destination_pallet_id'  => ['nullable', 'integer', 'exists:pallets,id'],
            'destination_base_id'    => ['nullable', 'integer', 'exists:pallet_bases,id'],
        ];
    }
}
