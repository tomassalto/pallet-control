<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Endpoints usados por el scraper local de imágenes de Carrefour.
 * Protegidos por X-Scraper-Secret (igual al X-Bot-Secret del bot de WhatsApp).
 */
class ProductImageController extends Controller
{
    /**
     * Actualizar image_url de un lote de productos.
     *
     * Body: { "products": [{ "ean": "...", "image_url": "..." }, ...] }
     */
    public function bulkUpdate(Request $request)
    {
        $data = $request->validate([
            'products'              => ['required', 'array', 'min:1', 'max:100'],
            'products.*.ean'        => ['required', 'string'],
            'products.*.image_url'  => ['required', 'url'],
            'products.*.name'       => ['nullable', 'string', 'max:255'],
        ]);

        $upserted = 0;

        foreach ($data['products'] as $row) {
            $ean   = preg_replace('/\D+/', '', $row['ean']);
            $last4 = strlen($ean) >= 4 ? substr($ean, -4) : null;
            $name  = trim($row['name'] ?? '') ?: $ean; // fallback al EAN si no hay nombre

            Product::updateOrCreate(
                ['ean' => $ean],
                ['name' => $name, 'ean_last4' => $last4, 'image_url' => $row['image_url']]
            );
            $upserted++;
        }

        return response()->json([
            'updated' => $upserted,
            'sent'    => count($data['products']),
        ]);
    }

    /**
     * Devuelve qué EANs del CSV ya tienen imagen (para no re-scrapear).
     * Body: { "eans": ["...", "..."] }
     */
    public function checkExisting(Request $request)
    {
        $data = $request->validate([
            'eans'   => ['required', 'array'],
            'eans.*' => ['required', 'string'],
        ]);

        $done = Product::whereIn('ean', $data['eans'])
            ->whereNotNull('image_url')
            ->pluck('ean')
            ->values();

        return response()->json(['done' => $done]);
    }
}
