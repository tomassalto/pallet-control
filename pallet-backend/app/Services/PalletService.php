<?php

namespace App\Services;

use App\Models\Pallet;

class PalletService
{
    /**
     * Verifica si un pallet puede ser finalizado.
     *
     * Reglas:
     *  - Al menos 2 bases
     *  - Al menos 2 fotos en total entre todas las bases
     *  - Todas las bases deben tener al menos 1 producto asignado
     *
     * @param Pallet $pallet Debe tener cargadas las relaciones: bases.photos, bases.orderItems
     * @return array{can: bool, reason: string|null, details: array}
     */
    public static function canFinalize(Pallet $pallet): array
    {
        $basesCount = $pallet->bases->count();
        $totalPhotos = $pallet->bases->sum(fn ($base) => $base->photos->count());
        $basesWithProducts = $pallet->bases->filter(fn ($base) => $base->orderItems->count() >= 1)->count();

        return self::evaluateFinalization($basesCount, $totalPhotos, $basesWithProducts);
    }

    /**
     * Evaluador puro de las reglas de finalización de un pallet.
     *
     * @return array{can: bool, reason: string|null, details: array}
     */
    public static function evaluateFinalization(int $basesCount, int $totalPhotos, int $basesWithProducts): array
    {
        $allBasesHaveProducts = $basesWithProducts === $basesCount;

        $can = $basesCount >= 2 && $totalPhotos >= 2 && $allBasesHaveProducts;

        return [
            'can' => $can,
            'reason' => $can ? null : 'Se requieren al menos 2 bases, 2 fotos en total, y todas las bases deben tener al menos 1 producto asignado.',
            'details' => [
                'bases_count' => $basesCount,
                'total_photos' => $totalPhotos,
                'bases_with_products' => $basesWithProducts,
                'all_bases_have_products' => $allBasesHaveProducts,
                'requirements' => [
                    'bases' => ['required' => 2,           'current' => $basesCount],
                    'photos' => ['required' => 2,           'current' => $totalPhotos],
                    'bases_with_products' => ['required' => $basesCount, 'current' => $basesWithProducts],
                ],
            ],
        ];
    }
}
