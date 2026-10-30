<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización la resuelven las rutas/middleware, como hoy.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return self::reglas();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajes();
    }

    /**
     * Reglas estáticas: permiten aplicar el mismo contrato de forma inline
     * donde el endpoint debe validar DESPUÉS de sus comprobaciones 404.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglas(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:20480'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'photo.required' => 'No se recibió ningún archivo.',
            'photo.uploaded' => 'El archivo no se pudo subir. Verificá el tamaño e intentá de nuevo.',
            'photo.mimes' => 'Formato no permitido. Usá JPG, PNG o WEBP.',
            'photo.max' => 'La foto supera los 20 MB.',
            'note.max' => 'La nota no puede superar los 1000 caracteres.',
        ];
    }
}
