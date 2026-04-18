<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PhotoAnnotation;
use Illuminate\Http\Request;

class PhotoAnnotationController extends Controller
{
    // GET /pallets/{pallet}/bases/{base}/photos/{photo}/annotations
    public function index(Pallet $pallet, $baseId, $photoId)
    {
        $base = PalletBase::findOrFail($baseId);
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        $photo = $base->photos()->find($photoId);
        if (!$photo) {
            return response()->json(['message' => 'Foto no encontrada'], 404);
        }

        $annotations = $photo->annotations()->with('orderItem')->orderBy('created_at')->get();

        return response()->json([
            'annotations' => $annotations,
        ]);
    }

    // POST /pallets/{pallet}/bases/{base}/photos/{photo}/annotations
    public function store(Request $request, Pallet $pallet, $baseId, $photoId)
    {
        $base = PalletBase::findOrFail($baseId);
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        $photo = $base->photos()->find($photoId);
        if (!$photo) {
            return response()->json(['message' => 'Foto no encontrada'], 404);
        }

        $data = $request->validate([
            'annotations' => ['required', 'array'],
            'annotations.*.text' => ['required', 'string', 'max:50'],
            'annotations.*.x' => ['required', 'numeric'],
            'annotations.*.y' => ['required', 'numeric'],
            'annotations.*.fontSize' => ['nullable', 'integer', 'min:10', 'max:200'],
            'annotations.*.color' => ['nullable', 'string', 'max:20'],
            'annotations.*.orderItemId' => ['nullable', 'integer', 'exists:order_items,id'],
        ]);

        // Contar anotaciones existentes antes de eliminarlas
        $existingCount = $photo->annotations()->count();

        // Eliminar anotaciones existentes
        $photo->annotations()->delete();

        // Crear nuevas anotaciones
        $created = [];
        foreach ($data['annotations'] as $annotationData) {
            $annotation = PhotoAnnotation::create([
                'photo_id' => $photo->id,
                'order_item_id' => $annotationData['orderItemId'] ?? null,
                'text' => $annotationData['text'],
                'x' => $annotationData['x'],
                'y' => $annotationData['y'],
                'font_size' => $annotationData['fontSize'] ?? 36,
                'color' => $annotationData['color'] ?? '#ffffff',
            ]);
            $created[] = $annotation;
        }

        $annotationsCount = count($created);
        $baseName = $base->name ?? "Base #{$base->id}";

        \App\Helpers\ActivityLogger::log(
            'photo_annotations_saved',
            'photo_annotation',
            $photo->id,
            "Anotaciones guardadas en foto de la base '{$baseName}' del pallet '{$pallet->code}': {$annotationsCount} anotación(es)" . ($existingCount > 0 ? " (reemplazaron {$existingCount} anterior(es))" : ""),
            $pallet->id,
            $existingCount > 0 ? ['annotations_count' => $existingCount] : null,
            ['annotations_count' => $annotationsCount]
        );

        return response()->json([
            'message' => 'Anotaciones guardadas',
            'annotations' => $created,
        ], 201);
    }
}
