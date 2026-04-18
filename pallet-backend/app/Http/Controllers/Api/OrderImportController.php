<?php

// app/Http/Controllers/OrderImportController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderImportController extends Controller
{
    public function import(Request $request, Order $order)
    {
        $request->validate([
            'raw' => ['required', 'string'],
        ]);

        $raw = $request->string('raw')->toString();
        $lines = preg_split("/\r\n|\n|\r/", trim($raw));

        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts = preg_split("/\t+/", $line);
            $parts = array_values(array_filter($parts, fn($x) => trim((string)$x) !== ''));

            // Si hay una columna adicional al final con valor "1", ignorarla
            $lastPart = trim((string) end($parts));
            $hasExternalFlag = ($lastPart === '1' && count($parts) > 4);
            if ($hasExternalFlag) {
                array_pop($parts); // Eliminar la última columna
            }

            // EAN | DESC | precio | algo | qty | [flag externo opcional]
            // Si hay flag externo: EAN | DESC | precio | algo | qty | 1
            // Si no hay flag: EAN | DESC | precio | qty
            if (count($parts) < 4) continue;

            $ean = preg_replace('/\D+/', '', (string) $parts[0]);
            $desc = trim((string) $parts[1]);

            // La cantidad está en la última columna (después de eliminar el flag si existe)
            // o en la columna 3 si no hay flag y solo hay 4 columnas
            $qtyIndex = count($parts) - 1; // Última columna
            $qtyRaw = trim((string) $parts[$qtyIndex]);
            $qtyRaw = str_replace(',', '.', $qtyRaw);
            $qtyRaw = preg_replace('/[^0-9.]/', '', $qtyRaw);
            $qty = (int) floor((float) $qtyRaw);

            if ($ean === '' || $desc === '' || $qty <= 0) continue;

            $parsed[] = [
                'ean' => $ean,
                'ean_last4' => strlen($ean) >= 4 ? substr($ean, -4) : null,
                'description' => $desc,
                'qty' => $qty,
            ];
        }

        if (count($parsed) === 0) {
            return response()->json(['message' => 'No se pudo interpretar el texto.'], 422);
        }

        DB::transaction(function () use ($order, $parsed) {
            OrderItem::where('order_id', $order->id)->delete();

            foreach ($parsed as $row) {
                Product::updateOrCreate(
                    ['ean' => $row['ean']],
                    ['name' => $row['description'], 'ean_last4' => $row['ean_last4']]
                );

                OrderItem::create([
                    'order_id' => $order->id,
                    'ean' => $row['ean'],
                    'ean_last4' => $row['ean_last4'],
                    'description' => $row['description'],
                    'qty' => $row['qty'],
                    'status' => 'pending',
                    'done_qty' => 0,
                ]);
            }
        });

        return response()->json([
            'message' => 'Pedido importado (reemplazado).',
            'count' => count($parsed),
        ], 201);
    }
}
