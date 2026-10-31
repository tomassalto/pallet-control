<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePendingItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:pending,resolved'],
            'qty_missing' => ['sometimes', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
