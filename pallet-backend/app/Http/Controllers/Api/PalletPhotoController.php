<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\PalletPhoto;
use App\Services\PhotoPersistenceService;
use App\Services\StorageCleanupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PalletPhotoController extends Controller
{
    public function __construct(
        private readonly PhotoPersistenceService $photoPersistence,
        private readonly StorageCleanupService $storageCleanup,
    ) {}

    public function index(Pallet $pallet)
    {
        $photos = $pallet->photos()->latest()->get();

        return response()->json($photos);
    }

    public function store(Request $request, Pallet $pallet)
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'max:20480'], // 20MB
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $data['photo'];

        // Convertir a WebP (solo se guarda WebP, no el original)
        // Opcional: redimensionar si la imagen es muy grande (máximo 4000px en el lado más largo)
        $photo = $this->photoPersistence->persist(
            $file,
            "pallets/{$pallet->id}",
            85,
            4000,
            4000,
            function (string $path) use ($data, $file, $pallet): PalletPhoto {
                $photo = PalletPhoto::create([
                    'pallet_id' => $pallet->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'note' => $data['note'] ?? null,
                ]);

                ActivityLogger::log(
                    action: 'pallet_photo_uploaded',
                    entityType: 'pallet_photo',
                    entityId: $photo->id,
                    description: "Foto agregada al pallet '{$pallet->code}': {$file->getClientOriginalName()}",
                    palletId: $pallet->id,
                    newValues: ['photo_id' => $photo->id, 'original_name' => $file->getClientOriginalName()],
                );

                return $photo;
            },
        );

        return response()->json([
            'photo' => $photo,
            'url' => $photo->url,
        ], 201);
    }

    public function destroy(Pallet $pallet, $photoId)
    {
        return DB::transaction(function () use ($pallet, $photoId) {
            $photo = $pallet->photos()->lockForUpdate()->find($photoId);
            if (! $photo) {
                return response()->json(['message' => 'Foto no encontrada'], 404);
            }

            $photoName = $photo->original_name;
            $paths = [$photo->path];

            $photo->delete();

            ActivityLogger::log(
                action: 'pallet_photo_deleted',
                entityType: 'pallet_photo',
                entityId: null,
                description: "Foto eliminada del pallet '{$pallet->code}': {$photoName}",
                palletId: $pallet->id,
                oldValues: ['photo_id' => $photo->id, 'original_name' => $photoName],
            );

            // El borrado físico es eventual: queda una intención durable
            // que el job DeleteStoredFile y el barrido confirman.
            $this->storageCleanup->scheduleDeletion($paths, 'pallet_photo_deleted');

            return response()->json(['message' => 'Foto eliminada'], 200);
        });
    }
}
