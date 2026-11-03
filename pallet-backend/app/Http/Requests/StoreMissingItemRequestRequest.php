<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMissingItemRequestRequest extends FormRequest
{
    private ?Order $order = null;

    public function authorize(): bool
    {
        // Endpoint público: la autorización la da el token opaco de la ruta.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // El token resuelve antes que la validación: un pedido desconocido
        // responde 404 aunque el payload sea inválido (precedencia previa).
        // HttpResponseException devuelve el cuerpo exacto del 404 original.
        $this->order = Order::where('public_token', $this->route('token'))->first();

        if (! $this->order) {
            throw new HttpResponseException(
                response()->json(['message' => 'Pedido no encontrado'], 404)
            );
        }
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function rules(): array
    {
        return [
            'order_item_id' => ['bail', 'required', 'integer', 'exists:order_items,id'],
            'qty_missing' => ['required', 'integer', 'min:1'],
            'requester_name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
