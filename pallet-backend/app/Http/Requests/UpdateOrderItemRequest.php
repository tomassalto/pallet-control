<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'   => ['nullable', 'in:pending,done,removed'],
            'qty'      => ['nullable', 'integer', 'min:1'],
            'done_qty' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
