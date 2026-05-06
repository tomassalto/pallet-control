<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Jobs\ProcessTicketOcr;
use App\Services\TicketOcrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Helpers\ImageConverter;
use App\Helpers\ActivityLogger;

class OrderTicketController extends Controller
{
    // GET /orders/{order}/tickets
    public function index(Order $order)
    {
        $tickets = $order->tickets()->with('photos')->get();

        return response()->json($tickets);
    }

    // POST /orders/{order}/tickets
    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket = OrderTicket::create([
            'order_id' => $order->id,
            'code' => $data['code'],
            'note' => $data['note'] ?? null,
        ]);

        ActivityLogger::log(
            action: 'order_ticket_created',
            entityType: 'order_ticket',
            entityId: $ticket->id,
            description: "Ticket agregado al pedido '{$order->code}': " . ($ticket->code ?? 'Sin código'),
            newValues: ['ticket_id' => $ticket->id, 'code' => $ticket->code],
            orderId: $order->id,
        );

        return response()->json($ticket->load('photos'), 201);
    }

    // POST /orders/{order}/tickets/{ticket}/photos
    public function storePhoto(Request $request, Order $order, OrderTicket $ticket)
    {
        try {
            // Verificar que el ticket pertenece al pedido
            if ($ticket->order_id !== $order->id) {
                return response()->json(['message' => 'Ticket no encontrado'], 404);
            }

            $data = $request->validate([
                'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:20480'], // 20MB
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            $file = $data['photo'];

            // Convertir a WebP
            $path = ImageConverter::convertToWebP(
                $file,
                "orders/{$order->id}/tickets/{$ticket->id}",
                95,
                6000,
                6000
            );

            // Obtener el siguiente índice de orden
            $maxIndex = $ticket->photos()->max('order_index') ?? -1;
            $nextIndex = $maxIndex + 1;

            $photo = OrderTicketPhoto::create([
                'ticket_id' => $ticket->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'note' => $data['note'] ?? null,
                'order_index' => $nextIndex,
            ]);

            ActivityLogger::log(
                action: 'order_ticket_photo_uploaded',
                entityType: 'order_ticket_photo',
                entityId: $photo->id,
                description: "Foto agregada al ticket del pedido '{$order->code}': {$file->getClientOriginalName()}",
                newValues: ['ticket_id' => $ticket->id, 'photo_id' => $photo->id, 'original_name' => $file->getClientOriginalName()],
                orderId: $order->id,
            );

            // NO despacha OCR automáticamente — el usuario lo dispara
            // manualmente desde el frontend con el botón "Escanear ticket".

            return response()->json([
                'photo' => $photo->fresh(),
                'url' => $photo->fresh()->url,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('OrderTicketController storePhoto: excepción no controlada.', [
                'order_id' => $order->id ?? null,
                'ticket_id' => $ticket->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error al subir la foto del ticket.',
                'detail'  => $e->getMessage(),
            ], 500);
        }
    }

    // GET /orders/{order}/tickets/{ticket}/photos/{photo}/ocr-status
    // Devuelve el estado actual del OCR para polling del frontend.
    public function photoOcrStatus(Order $order, OrderTicket $ticket, OrderTicketPhoto $photo)
    {
        if ($ticket->order_id !== $order->id || $photo->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        return response()->json([
            'id'               => $photo->id,
            'ocr_processed_at' => $photo->ocr_processed_at,
            'ocr_log'          => $photo->ocr_log,
            'ocr_eans_count'   => count(($photo->ocr_data['eans'] ?? [])),
        ]);
    }

    // GET /orders/{order}/tickets/{ticket}/photos/{photo}/highlights
    // Mapa de distribución del pedido: EANs detectados por OCR coloreados por pallet.
    // Precondiciones (validadas aquí, no en el frontend):
    //   1. La foto tiene ocr_data (fue escaneada)
    //   2. Todas las unidades del pedido están distribuidas en bases
    //   3. El pedido tiene unidades en 2+ pallets distintos
    public function photoHighlights(Order $order, OrderTicket $ticket, OrderTicketPhoto $photo)
    {
        if ($ticket->order_id !== $order->id || $photo->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        if (! $photo->ocr_data) {
            return response()->json(['ready' => false, 'reason' => 'Esta foto no ha sido escaneada aún.']);
        }

        // Cargar ítems del pedido
        $order->load('items');
        $orderItems = $order->items->keyBy('ean');
        $orderEans  = $orderItems->keys()->all();

        // Distribución completa: por EAN, por pallet, por base
        $rows = DB::table('pallet_base_order_items as pboi')
            ->join('pallet_bases as pb', 'pb.id', '=', 'pboi.base_id')
            ->join('pallets as p',       'p.id',  '=', 'pb.pallet_id')
            ->join('order_items as oi',  'oi.id', '=', 'pboi.order_item_id')
            ->where('oi.order_id', $order->id)
            ->select(
                'oi.ean',
                'p.id as pallet_id',
                'p.code as pallet_code',
                'pb.id as base_id',
                'pb.name as base_name',
                DB::raw('SUM(pboi.qty) as qty')
            )
            ->groupBy('oi.ean', 'p.id', 'p.code', 'pb.id', 'pb.name')
            ->get();

        // Agrupar por EAN y acumular metadata de pallets
        $distributionByEan = [];
        $palletMeta = [];   // pallet_id => [id, code, total_qty, product_eans, base_ids]

        foreach ($rows as $row) {
            $distributionByEan[$row->ean][] = [
                'pallet_id'   => $row->pallet_id,
                'pallet_code' => $row->pallet_code,
                'base_name'   => $row->base_name ?? 'Base',
                'qty'         => (int) $row->qty,
            ];

            if (! isset($palletMeta[$row->pallet_id])) {
                $palletMeta[$row->pallet_id] = [
                    'id'           => $row->pallet_id,
                    'code'         => $row->pallet_code,
                    'total_qty'    => 0,
                    'product_eans' => [],
                    'base_ids'     => [],
                ];
            }
            $palletMeta[$row->pallet_id]['total_qty']              += (int) $row->qty;
            $palletMeta[$row->pallet_id]['product_eans'][$row->ean] = true;
            $palletMeta[$row->pallet_id]['base_ids'][$row->base_id] = true;
        }

        // Precondición 1: todas las unidades del pedido distribuidas
        foreach ($orderItems as $ean => $item) {
            $distQty = array_sum(array_column($distributionByEan[$ean] ?? [], 'qty'));
            if ($distQty < $item->qty) {
                return response()->json([
                    'ready'  => false,
                    'reason' => "Hay productos sin organizar en bases. Por ejemplo: \"{$item->description}\" tiene {$distQty} de {$item->qty} u. distribuidas.",
                ]);
            }
        }

        // Precondición 2: 2+ pallets con unidades del pedido
        $palletCount = count($palletMeta);
        if ($palletCount < 2) {
            return response()->json([
                'ready'  => false,
                'reason' => $palletCount === 0
                    ? 'Ningún producto está organizado en bases aún.'
                    : 'Todo el pedido está en un solo pallet. Usá la vista pública QR del pallet para ver los highlights.',
            ]);
        }

        // Asignar color_index estable (ordenado por pallet_id)
        ksort($palletMeta);
        $colorIndex    = 0;
        $palletColorMap = [];  // pallet_id => color_index
        $palletsResponse = [];

        foreach ($palletMeta as $palletId => $meta) {
            $palletColorMap[$palletId] = $colorIndex;
            $palletsResponse[] = [
                'id'            => $palletId,
                'code'          => $meta['code'],
                'color_index'   => $colorIndex,
                'base_count'    => count($meta['base_ids']),
                'product_count' => count($meta['product_eans']),
                'total_qty'     => $meta['total_qty'],
            ];
            $colorIndex++;
        }

        // Extraer mejores detecciones del ocr_data (fuzzy-matching guiado por orderEans)
        $detections = TicketOcrService::extractBestDetections($photo->ocr_data, $orderEans);

        // Construir highlights enriquecidos con datos de distribución
        $highlights = [];

        foreach ($detections as $ean => $detection) {
            $orderItem = $orderItems->get($ean);

            if (! $orderItem) {
                // EAN detectado por OCR que no pertenece a este pedido
                $highlights[] = [
                    'ean'               => $ean,
                    'detected_ean'      => $detection['detected_ean'],
                    'description'       => null,
                    'qty_order'         => null,
                    'is_split'          => false,
                    'pallet_color_index'=> null,
                    'not_in_order'      => true,
                    'pallet_breakdown'  => [],
                    'bbox'              => $detection['bbox'],
                    'ean_bbox'          => $detection['ean_bbox'],
                    'img_w'             => $detection['img_w'],
                    'img_h'             => $detection['img_h'],
                ];
                continue;
            }

            $breakdown    = $distributionByEan[$ean] ?? [];
            $palletIdsHere = array_unique(array_column($breakdown, 'pallet_id'));
            $isSplit       = count($palletIdsHere) > 1;

            // Añadir color_index a cada entrada del breakdown
            $breakdownWithColors = array_map(fn ($b) => array_merge($b, [
                'color_index' => $palletColorMap[$b['pallet_id']] ?? null,
            ]), $breakdown);

            $highlights[] = [
                'ean'               => $ean,
                'detected_ean'      => $detection['detected_ean'],
                'description'       => $orderItem->description,
                'qty_order'         => $orderItem->qty,
                'is_split'          => $isSplit,
                'pallet_color_index'=> $isSplit ? null : ($palletColorMap[$palletIdsHere[0]] ?? null),
                'not_in_order'      => false,
                'pallet_breakdown'  => $breakdownWithColors,
                'bbox'              => $detection['bbox'],
                'ean_bbox'          => $detection['ean_bbox'],
                'img_w'             => $detection['img_w'],
                'img_h'             => $detection['img_h'],
            ];
        }

        return response()->json([
            'ready'      => true,
            'pallets'    => $palletsResponse,
            'highlights' => $highlights,
            'img_w'      => $photo->ocr_data['img_w'] ?? null,
            'img_h'      => $photo->ocr_data['img_h'] ?? null,
        ]);
    }

    // DELETE /orders/{order}/tickets/{ticket}
    public function destroy(Order $order, OrderTicket $ticket)
    {
        // Verificar que el ticket pertenece al pedido
        if ($ticket->order_id !== $order->id) {
            return response()->json(['message' => 'Ticket no encontrado'], 404);
        }

        $ticketCode = $ticket->code ?? 'Sin código';
        $ticketId = $ticket->id;

        // Eliminar todas las fotos del ticket
        foreach ($ticket->photos as $photo) {
            Storage::disk(config('filesystems.default', 'public'))->delete($photo->path);
        }

        $ticket->delete();

        ActivityLogger::log(
            action: 'order_ticket_deleted',
            entityType: 'order_ticket',
            entityId: null,
            description: "Ticket eliminado del pedido '{$order->code}': {$ticketCode}",
            oldValues: ['ticket_id' => $ticketId, 'code' => $ticketCode],
            orderId: $order->id,
        );

        return response()->json(['message' => 'Ticket eliminado'], 200);
    }

    // POST /orders/{order}/tickets/{ticket}/photos/{photo}/trigger-ocr
    // El usuario confirma manualmente que quiere escanear (consume 1 request de Azure).
    public function triggerOcr(Order $order, OrderTicket $ticket, OrderTicketPhoto $photo)
    {
        if ($ticket->order_id !== $order->id || $photo->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        if ($photo->ocr_processed_at !== null) {
            return response()->json(['message' => 'Esta foto ya fue escaneada anteriormente.'], 409);
        }

        // Marcar inmediatamente como "encolado" para que el frontend
        // pueda distinguir "nunca iniciado" de "en proceso".
        $photo->ocr_log = '[' . date('H:i:s') . '] Escaneo encolado. Iniciando en breve…';
        $photo->saveQuietly();

        ProcessTicketOcr::dispatch($photo->id);

        ActivityLogger::log(
            action: 'order_ticket_ocr_triggered',
            entityType: 'order_ticket_photo',
            entityId: $photo->id,
            description: "OCR disparado manualmente para foto del ticket del pedido '{$order->code}'",
            orderId: $order->id,
        );

        return response()->json(['message' => 'OCR iniciado', 'photo' => $photo->fresh()]);
    }

    // DELETE /orders/{order}/tickets/{ticket}/photos/{photo}
    public function destroyPhoto(Order $order, OrderTicket $ticket, OrderTicketPhoto $photo)
    {
        // Verificar que el ticket pertenece al pedido
        if ($ticket->order_id !== $order->id) {
            return response()->json(['message' => 'Ticket no encontrado'], 404);
        }

        // Verificar que la foto pertenece al ticket
        if ($photo->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Foto no encontrada'], 404);
        }

        $photoPath = $photo->path;
        $photoName = $photo->original_name;

        Storage::disk(config('filesystems.default', 'public'))->delete($photoPath);
        $photo->delete();

        ActivityLogger::log(
            action: 'order_ticket_photo_deleted',
            entityType: 'order_ticket_photo',
            entityId: null,
            description: "Foto eliminada del ticket del pedido '{$order->code}': {$photoName}",
            oldValues: ['ticket_id' => $ticket->id, 'photo_id' => $photo->id, 'original_name' => $photoName],
            orderId: $order->id,
        );

        return response()->json(['message' => 'Foto eliminada'], 200);
    }
}
