<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
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

        // Agregar URLs a las fotos
        $tickets->each(function ($ticket) {
            $ticket->photos->each(function ($photo) {
                $photo->url = Storage::disk('public')->url($photo->path);
            });
        });

        return response()->json($tickets);
    }

    // POST /orders/{order}/tickets
    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket = OrderTicket::create([
            'order_id' => $order->id,
            'code' => $data['code'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        ActivityLogger::log(
            'order_ticket_created',
            'order_ticket',
            $ticket->id,
            "Ticket agregado al pedido '{$order->code}': " . ($ticket->code ?? 'Sin código'),
            null, // palletId
            null, // oldValues
            ['ticket_id' => $ticket->id, 'code' => $ticket->code], // newValues
            $order->id // orderId
        );

        return response()->json($ticket->load('photos'), 201);
    }

    // POST /orders/{order}/tickets/{ticket}/photos
    public function storePhoto(Request $request, Order $order, OrderTicket $ticket)
    {
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
        try {
            $path = ImageConverter::convertToWebP(
                $file,
                "orders/{$order->id}/tickets/{$ticket->id}",
                85,
                4000,
                4000
            );
        } catch (\Exception $e) {
            Log::error('Error al convertir imagen a WebP:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Error al procesar la imagen: ' . $e->getMessage(),
            ], 422);
        }

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
            'order_ticket_photo_uploaded',
            'order_ticket_photo',
            $photo->id,
            "Foto agregada al ticket del pedido '{$order->code}': {$file->getClientOriginalName()}",
            null, // palletId
            null, // oldValues
            ['ticket_id' => $ticket->id, 'photo_id' => $photo->id, 'original_name' => $file->getClientOriginalName()], // newValues
            $order->id // orderId
        );

        return response()->json([
            'photo' => $photo,
            'url' => Storage::disk('public')->url($path),
        ], 201);
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
            'order_ticket_deleted',
            'order_ticket',
            null,
            "Ticket eliminado del pedido '{$order->code}': {$ticketCode}",
            null, // palletId
            ['ticket_id' => $ticketId, 'code' => $ticketCode], // oldValues
            null, // newValues
            $order->id // orderId
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
            'order_ticket_photo_deleted',
            'order_ticket_photo',
            null,
            "Foto eliminada del ticket del pedido '{$order->code}': {$photoName}",
            null, // palletId
            ['ticket_id' => $ticket->id, 'photo_id' => $photo->id, 'original_name' => $photoName], // oldValues
            null, // newValues
            $order->id // orderId
        );

        return response()->json(['message' => 'Foto eliminada'], 200);
    }
}
