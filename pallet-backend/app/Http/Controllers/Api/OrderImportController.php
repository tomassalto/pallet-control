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
 *  2  Cant Pedida        ← qty (lo que el cliente pidió)
 *  3  Cant Real          ← done_qty (lo que realmente se encontró/entregó)
 *  4  Precio Unitario    ← price
 *  5  Precio Base        ← ignorar
 *  6  Desc. Base         ← ignorar
 *  7  Desc. Medio Pago   ← desc_medio_pago (10.00 = lunes/viernes, vacío = no)
 *  8  Controlado         ← is_controlled  (1 = sí, vacío = no)
 *
 * Lógica de estado al importar:
 *   - Cant Real > 0  →  status = 'done',    done_qty = Cant Real  ✓ se importa
 *   - Cant Real = 0  →  se OMITE (el pedido ya está cerrado, sin stock = no viene)
 *   - Cant Pedida = 0 y Cant Real > 0 (producto extra no pedido originalmente)
 *                    →  qty = Cant Real  (para que el conteo tenga sentido)
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
            $cantPedidaRaw = str_replace(',', '.', trim($parts[2] ?? '0'));
            $cantPedidaRaw = preg_replace('/[^0-9.]/', '', $cantPedidaRaw);
            $cantPedida    = (int) floor((float) $cantPedidaRaw);

            // ── Columna 3: Cant Real (lo que realmente se encontró) ────────
            $cantRealRaw = str_replace(',', '.', trim($parts[3] ?? '0'));
            $cantRealRaw = preg_replace('/[^0-9.]/', '', $cantRealRaw);
            $cantReal    = (int) floor((float) $cantRealRaw);

            // Solo importar productos que realmente se entregaron (Cant Real > 0)
            // El pedido ya está cerrado: si no vino, no se importa
            if ($cantReal <= 0) continue;

            // Si Cant Pedida = 0 pero Cant Real > 0 (producto extra no pedido),
            // usamos Cant Real como qty para que el conteo tenga sentido
            $qty = $cantPedida > 0 ? $cantPedida : $cantReal;

            // Todos los ítems importados son done (Cant Real > 0 garantizado)
            $doneQty = $cantReal;
            $status  = 'done';

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
                'ean'             => $ean,
                'ean_last4'       => strlen($ean) >= 4 ? substr($ean, -4) : null,
                'description'     => $desc,
                'qty'             => $qty,
                'done_qty'        => $doneQty,
                'status'          => $status,
                'price'           => $price,
                'desc_medio_pago' => $descMedioPago,
                'is_controlled'   => $isControlled,
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
                    'done_qty'        => $row['done_qty'],
                    'status'          => $row['status'],
                    'price'           => $row['price'],
                    'desc_medio_pago' => $row['desc_medio_pago'],
                    'is_controlled'   => $row['is_controlled'],
                ]);
            }
        });

        return response()->json([
            'message' => 'Pedido importado: ' . count($parsed) . ' productos encontrados.',
            'count'   => count($parsed),
            'done'    => count($parsed),
            'pending' => 0,
        ], 201);
    }
}
