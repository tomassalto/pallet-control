<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Columnas fijas del archivo (separadas por TAB):
 *
 *  0  EAN
 *  1  Descripción
 *  2  Cant Pedida        ← qty
 *  3  Cant Real          ← ignorar
 *  4  Precio Unitario    ← price
 *  5  Precio Base        ← ignorar
 *  6  Desc. Base         ← ignorar
 *  7  Desc. Medio Pago   ← desc_medio_pago (10.00 = lunes/viernes, vacío = no)
 *  8  Controlado         ← is_controlled  (1 = sí, vacío = no)
 */
class OrderImportController extends Controller
{
    public function import(Request $request, Order $order)
    {
        $request->validate([
            'raw' => ['required', 'string'],
        ]);

        $raw   = $request->string('raw')->toString();
        $lines = preg_split("/\r\n|\n|\r/", trim($raw));

        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Dividir SOLO por TAB para preservar posiciones de columnas vacías
            $parts = explode("\t", $line);

            // Necesitamos al menos EAN, Descripción, Cant Pedida (índices 0-2)
            if (count($parts) < 3) continue;

            // ── Columna 0: EAN ─────────────────────────────────────────────
            $ean = preg_replace('/\D+/', '', trim($parts[0]));
            if ($ean === '') continue;

            // ── Columna 1: Descripción ─────────────────────────────────────
            $desc = trim($parts[1] ?? '');
            if ($desc === '') continue;

            // ── Columna 2: Cant Pedida ─────────────────────────────────────
            $qtyRaw = str_replace(',', '.', trim($parts[2] ?? '0'));
            $qtyRaw = preg_replace('/[^0-9.]/', '', $qtyRaw);
            $qty    = (int) floor((float) $qtyRaw);
            if ($qty <= 0) continue;

            // ── Columna 4: Precio Unitario ─────────────────────────────────
            $priceRaw = str_replace(',', '.', trim($parts[4] ?? ''));
            $priceRaw = preg_replace('/[^0-9.]/', '', $priceRaw);
            $price    = $priceRaw !== '' ? (float) $priceRaw : null;

            // ── Columna 7: Desc. Medio Pago ────────────────────────────────
            $dmpRaw = str_replace(',', '.', trim($parts[7] ?? ''));
            $dmpRaw = preg_replace('/[^0-9.]/', '', $dmpRaw);
            $descMedioPago = ($dmpRaw !== '' && (float) $dmpRaw > 0)
                ? (float) $dmpRaw
                : null;

            // ── Columna 8: Controlado ──────────────────────────────────────
            $isControlled = trim($parts[8] ?? '') === '1';

            $parsed[] = [
                'ean'            => $ean,
                'ean_last4'      => strlen($ean) >= 4 ? substr($ean, -4) : null,
                'description'    => $desc,
                'qty'            => $qty,
                'price'          => $price,
                'desc_medio_pago'=> $descMedioPago,
                'is_controlled'  => $isControlled,
            ];
        }

        if (count($parsed) === 0) {
            return response()->json([
                'message' => 'No se pudo interpretar el texto. Verificá que el formato sea el correcto (columnas separadas por TAB).',
            ], 422);
        }

        DB::transaction(function () use ($order, $parsed) {
            OrderItem::where('order_id', $order->id)->delete();

            foreach ($parsed as $row) {
                Product::updateOrCreate(
                    ['ean' => $row['ean']],
                    ['name' => $row['description'], 'ean_last4' => $row['ean_last4']]
                );

                OrderItem::create([
                    'order_id'        => $order->id,
                    'ean'             => $row['ean'],
                    'ean_last4'       => $row['ean_last4'],
                    'description'     => $row['description'],
                    'qty'             => $row['qty'],
                    'price'           => $row['price'],
                    'desc_medio_pago' => $row['desc_medio_pago'],
                    'is_controlled'   => $row['is_controlled'],
                    'status'          => 'pending',
                    'done_qty'        => 0,
                ]);
            }
        });

        return response()->json([
            'message' => 'Pedido importado correctamente.',
            'count'   => count($parsed),
        ], 201);
    }
}
