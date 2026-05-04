<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Jobs\ProcessTicketOcr;
use Illuminate\Http\Request;
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

            // Despacha el job a la cola de base de datos (insert instantáneo).
            // El worker separado (supervisord) lo procesa en background.
            ProcessTicketOcr::dispatch($photo->id);

            return response()->json([
                'photo' => $photo->fresh(),
                'url' => '/storage/' . $path,
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
            Storage::disk('public')->delete($photo->path);
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

        Storage::disk('public')->delete($photoPath);
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
