<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Services\PhotoPersistenceService;
use App\Services\StorageCleanupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PalletBasePhotoController extends Controller
{
    public function __construct(
        private readonly PhotoPersistenceService $photoPersistence,
        private readonly StorageCleanupService $storageCleanup,
    ) {}

    public function store(Request $request, Pallet $pallet, $baseId)
    {
        // Resolver la base manualmente
        $base = PalletBase::findOrFail($baseId);

        // Verificar que la base pertenece al pallet
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        // Obtener el archivo — intentar campo 'photo' primero, luego primer archivo disponible
        $file = $request->file('photo') ?? (($all = $request->allFiles()) ? reset($all) : null);

        if (! $file) {
            return response()->json(['message' => 'No se recibió ningún archivo'], 422);
        }

        if (! $file->isValid()) {
            return response()->json([
                'message' => 'El archivo no es válido: ' . $file->getErrorMessage(),
                'error_code' => $file->getError(),
            ], 422);
        }

        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:20480'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $data['photo'];

        // Convertir a WebP (solo se guarda WebP, no el original)
        $photo = $this->photoPersistence->persist(
            $file,
            "pallets/{$pallet->id}/bases/{$base->id}",
            85,
            4000,
            4000,
            function (string $path) use ($base, $data, $file, $pallet): PalletBasePhoto {
                $photo = PalletBasePhoto::create([
                    'base_id' => $base->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'note' => $data['note'] ?? null,
                ]);

                ActivityLogger::log(
                    action: 'base_photo_uploaded',
                    entityType: 'pallet_base_photo',
                    entityId: $photo->id,
                    description: "Foto agregada a la base '" . ($base->name ?? "Base #{$base->id}") . "' del pallet '{$pallet->code}': {$file->getClientOriginalName()}",
                    palletId: $pallet->id,
                    newValues: ['base_id' => $base->id, 'base_name' => $base->name, 'photo_id' => $photo->id, 'original_name' => $file->getClientOriginalName()],
                );

                return $photo;
            },
        );

        return response()->json([
            'photo' => $photo,
            'url' => $photo->url,
        ], 201);
    }

    public function destroy(Pallet $pallet, $baseId, $photoId)
    {
        // Resolver la base manualmente
        $base = PalletBase::findOrFail($baseId);

        // Verificar que la base pertenece al pallet
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        return DB::transaction(function () use ($base, $pallet, $photoId) {
            $photo = $base->photos()->lockForUpdate()->find($photoId);
            if (! $photo) {
                return response()->json(['message' => 'Foto no encontrada'], 404);
            }

            $photoName = $photo->original_name;
            $paths = [$photo->path];

            $photo->delete();

            ActivityLogger::log(
                action: 'base_photo_deleted',
                entityType: 'pallet_base_photo',
                entityId: null,
                description: "Foto eliminada de la base '" . ($base->name ?? "Base #{$base->id}") . "' del pallet '{$pallet->code}': {$photoName}",
                palletId: $pallet->id,
                oldValues: ['base_id' => $base->id, 'base_name' => $base->name, 'photo_id' => $photo->id, 'original_name' => $photoName],
            );

            $this->storageCleanup->scheduleDeletion($paths, 'base_photo_deleted');

            return response()->json(['message' => 'Foto eliminada'], 200);
        });
    }
}
