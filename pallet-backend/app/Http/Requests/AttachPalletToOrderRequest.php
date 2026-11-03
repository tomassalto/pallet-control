<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachPalletToOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización la dan auth:sanctum, user.active y has.role.
        return true;
    }

    public function rules(): array
    {
        // `bail` + `integer` antes de `exists` (H-050): el id no numérico
        // nunca llega a la consulta. Laravel 12 ya omite `exists` cuando
        // una regla previa falló; `bail` lo hace explícito y no depende
        // de ese detalle interno del validador.
        return [
            'pallet_id' => ['bail', 'required', 'integer', 'exists:pallets,id'],
        ];
    }
}
