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

            // Obtener el archivo — intentar campo 'photo' primero, luego primer archivo disponible
            $file = $request->file('photo') ?? (($all = $request->allFiles()) ? reset($all) : null);

            if (!$file) {
                return response()->json(['message' => 'No se recibió ningún archivo'], 422);
            }

            if (!$file->isValid()) {
                return response()->json([
                    'message' => 'El archivo no es válido: ' . $file->getErrorMessage(),
                    'error_code' => $file->getError(),
                ], 422);
            }

            $data = $request->validate([
                'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:20480'],
                'note'  => ['nullable', 'string', 'max:1000'],
            ]);

            $file = $data['photo'];

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
