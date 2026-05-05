<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\PalletBase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Helpers\ImageConverter;

class PalletBasePhotoController extends Controller
{
    public function store(Request $request, Pallet $pallet, $baseId)
    {
        try {
            // Resolver la base manualmente
            $base = PalletBase::findOrFail($baseId);

            // Verificar que la base pertenece al pallet
            if ($base->pallet_id !== $pallet->id) {
                return response()->json(['message' => 'Base no encontrada'], 404);
            }

            // Log para diagnóstico
            Log::info('PalletBasePhotoController@store: Request recibido', [
                'has_file' => $request->hasFile('photo'),
                'all_files' => array_keys($request->allFiles()),
                'content_type' => $request->header('Content-Type'),
                'content_length' => $request->header('Content-Length'),
                'request_method' => $request->method(),
                'request_keys' => array_keys($request->all()),
            ]);

            // Obtener el archivo - intentar múltiples métodos
            $file = $request->file('photo');

            // Si no se encuentra como archivo, verificar si está en allFiles()
            if (!$file) {
                $allFiles = $request->allFiles();
                if (!empty($allFiles)) {
                    Log::info('PalletBasePhotoController@store: Archivo encontrado en allFiles', [
                        'keys' => array_keys($allFiles),
                    ]);
                    // Intentar obtener el primer archivo si existe
                    $file = reset($allFiles);
                }
            }

            // Validar el archivo
            if (!$file) {
                Log::error('PalletBasePhotoController@store: No se pudo obtener el archivo', [
                    'has_file' => $request->hasFile('photo'),
                    'all_files' => array_keys($request->allFiles()),
                    'content_type' => $request->header('Content-Type'),
                    'content_length' => $request->header('Content-Length'),
                    'request_all' => $request->all(),
                ]);
                return response()->json([
                    'message' => 'No se recibió ningún archivo',
                    'debug' => [
                        'has_file' => $request->hasFile('photo'),
                        'all_files' => array_keys($request->allFiles()),
                        'content_type' => $request->header('Content-Type'),
                        'content_length' => $request->header('Content-Length'),
                    ]
                ], 422);
            }

            // Verificar que el archivo es válido antes de la validación
            if (!$file->isValid()) {
                Log::error('Archivo no válido antes de validación:', [
                    'error' => $file->getError(),
                    'error_message' => $file->getErrorMessage(),
                    'pathname' => $file->getPathname(),
                    'real_path' => $file->getRealPath(),
                ]);
                return response()->json([
                    'message' => 'El archivo no es válido: ' . $file->getErrorMessage(),
                    'error_code' => $file->getError(),
                ], 422);
            }

            // Validar el archivo (sin la regla 'image' que puede fallar con archivos temporales)
            try {
                $data = $request->validate([
                    'photo' => [
                        'required',
                        'file',
                        'mimes:jpeg,jpg,png,webp', // Validar por extensión en lugar de 'image'
                        'max:20480', // 20MB
                    ],
                    'note' => ['nullable', 'string', 'max:1000'],
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Error de validación al subir foto:', [
                    'errors' => $e->errors(),
                    'file_size' => $file->getSize(),
                    'file_mime' => $file->getMimeType(),
                    'file_name' => $file->getClientOriginalName(),
                    'pathname' => $file->getPathname(),
                    'real_path' => $file->getRealPath(),
                ]);
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $e->errors(),
                    'file_size' => $file->getSize(),
                    'file_mime' => $file->getMimeType(),
                ], 422);
            }

            $file = $data['photo'];

            // Log información del archivo
            Log::info('Intentando subir foto:', [
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_mime' => $file->getMimeType(),
                'pathname'  => $file->getPathname(),
                'file_path' => $file->getRealPath(),
                'is_valid' => $file->isValid(),
            ]);

            // Convertir a WebP (solo se guarda WebP, no el original)
            try {
                $path = ImageConverter::convertToWebP(
                    $file,
                    "pallets/{$pallet->id}/bases/{$base->id}",
                    85, // Calidad de compresión (0-100)
                    4000, // Ancho máximo (opcional, null para no redimensionar)
                    4000  // Alto máximo (opcional, null para no redimensionar)
                );
            } catch (\Exception $e) {
                Log::error('Error al convertir imagen a WebP:', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                ]);
                return response()->json([
                    'message' => 'Error al procesar la imagen: ' . $e->getMessage(),
                    'error_type' => get_class($e),
                ], 422);
            }

            $photo = \App\Models\PalletBasePhoto::create([
                'base_id' => $base->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'note' => $data['note'] ?? null,
            ]);

            \App\Helpers\ActivityLogger::log(
                action: 'base_photo_uploaded',
                entityType: 'pallet_base_photo',
                entityId: $photo->id,
                description: "Foto agregada a la base '" . ($base->name ?? "Base #{$base->id}") . "' del pallet '{$pallet->code}': {$file->getClientOriginalName()}",
                palletId: $pallet->id,
                newValues: ['base_id' => $base->id, 'base_name' => $base->name, 'photo_id' => $photo->id, 'original_name' => $file->getClientOriginalName()],
            );

            return response()->json([
                'photo' => $photo,
                'url' => $photo->url,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error general al subir foto:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'message' => 'Error al subir foto: ' . $e->getMessage(),
                'error_type' => get_class($e),
            ], 422);
        }
    }

    public function destroy(Pallet $pallet, $baseId, $photoId)
    {
        // Resolver la base manualmente
        $base = PalletBase::findOrFail($baseId);

        // Verificar que la base pertenece al pallet
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        $photo = $base->photos()->find($photoId);
        if (!$photo) {
            return response()->json(['message' => 'Foto no encontrada'], 404);
        }

        $photoPath = $photo->path;
        $photoName = $photo->original_name;

        // Eliminar archivo del storage
        Storage::disk(config('filesystems.default', 'public'))->delete($photoPath);
        $photo->delete();

        \App\Helpers\ActivityLogger::log(
            action: 'base_photo_deleted',
            entityType: 'pallet_base_photo',
            entityId: null,
            description: "Foto eliminada de la base '" . ($base->name ?? "Base #{$base->id}") . "' del pallet '{$pallet->code}': {$photoName}",
            palletId: $pallet->id,
            oldValues: ['base_id' => $base->id, 'base_name' => $base->name, 'photo_id' => $photo->id, 'original_name' => $photoName],
        );

        return response()->json(['message' => 'Foto eliminada'], 200);
    }
}
