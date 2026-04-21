<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PalletController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\OrderImportController;
use App\Http\Controllers\Api\OrderItemController;
use App\Http\Controllers\Api\PalletPhotoController;
use App\Http\Controllers\Api\PalletBaseController;
use App\Http\Controllers\Api\PalletBasePhotoController;
use App\Http\Controllers\Api\PhotoAnnotationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\OrderTicketController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\Api\UserController;

Route::prefix('v1')->group(function () {
    // Ruta del bot de WhatsApp (sin auth Sanctum, usa X-Bot-Secret)
    Route::post('/bot/upload', [BotController::class, 'uploadPhoto']);

    // Telegram webhook (sin auth Sanctum, valida X-Telegram-Bot-Api-Secret-Token)
    Route::post('/telegram/webhook', [TelegramBotController::class, 'webhook']);

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/auth/verify/{id}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify');

    // Reenvío de verificación: no requiere auth (el usuario no puede estar logueado aún)
    Route::post('/auth/email/resend', [AuthController::class, 'resendVerification']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // ── Rutas con control de acceso por rol ───────────────────────────────
        // has.role: GET/HEAD libres para todos; POST/PATCH/DELETE requieren rol.
        Route::middleware('has.role')->group(function () {

            // Customers
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::post('/customers', [CustomerController::class, 'store']);

            // Orders - IMPORTANTE: las rutas específicas deben ir antes de las dinámicas
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/last-open', [OrderController::class, 'lastOpen']);
            Route::post('/orders/can-finalize-batch', [OrderController::class, 'canFinalizeBatch']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::patch('/orders/{order}', [OrderController::class, 'updateStatus']);
            Route::post('/orders/{order}/attach-pallet', [OrderController::class, 'attachPallet']);
            Route::get('/orders/{order}/activity-logs', [OrderController::class, 'activityLogs']);
            Route::get('/orders/{order}/can-finalize', [OrderController::class, 'canFinalize']);
            Route::post('/orders/{order}/finalize', [OrderController::class, 'finalize']);
            Route::delete('/orders/{order}/detach-pallet/{pallet}', [OrderController::class, 'detachPallet']);

            // Order Tickets
            Route::get('/orders/{order}/tickets', [OrderTicketController::class, 'index']);
            Route::post('/orders/{order}/tickets', [OrderTicketController::class, 'store']);
            Route::delete('/orders/{order}/tickets/{ticket}', [OrderTicketController::class, 'destroy']);
            Route::post('/orders/{order}/tickets/{ticket}/photos', [OrderTicketController::class, 'storePhoto']);
            Route::delete('/orders/{order}/tickets/{ticket}/photos/{photo}', [OrderTicketController::class, 'destroyPhoto']);

            // Pallets
            Route::get('/pallets', [PalletController::class, 'index']);
            Route::post('/pallets', [PalletController::class, 'store']);
            Route::get('/pallets/{pallet}', [PalletController::class, 'show']);
            Route::patch('/pallets/{pallet}', [PalletController::class, 'updateStatus']);
            Route::delete('/pallets/{pallet}', [PalletController::class, 'destroy']);
            Route::get('/pallets/last-open', [PalletController::class, 'lastOpen']);
            Route::get('/pallets/{pallet}/activity-logs', [PalletController::class, 'activityLogs']);
            Route::get('/pallets/{pallet}/can-finalize', [PalletController::class, 'canFinalize']);
            Route::post('/pallets/{pallet}/finalize', [PalletController::class, 'finalize']);
            Route::post('/pallets/{pallet}/reopen', [PalletController::class, 'reopen']);

            // Link order <-> pallet
            Route::post('/pallets/{pallet}/attach-order', [PalletController::class, 'attachOrder']);

            // Products
            Route::post('/products', [ProductController::class, 'store']);
            Route::get('/products/by-ean/{ean}', [ProductController::class, 'showByEan']);

            // Movements
            Route::get('/pallets/{pallet}/movements', [MovementController::class, 'index']);
            Route::post('/pallets/{pallet}/movements', [MovementController::class, 'store']);

            Route::post('/orders/{order}/import', [OrderImportController::class, 'import']);

            Route::post('/orders/{order}/items', [OrderItemController::class, 'store']);
            Route::patch('/order-items/{item}', [OrderItemController::class, 'update']);

            Route::get('/pallets/{pallet}/photos', [PalletPhotoController::class, 'index']);
            Route::post('/pallets/{pallet}/photos', [PalletPhotoController::class, 'store']);
            Route::delete('/pallets/{pallet}/photos/{photo}', [PalletPhotoController::class, 'destroy']);

            // Bases
            Route::get('/pallets/{pallet}/bases', [PalletBaseController::class, 'index']);
            Route::post('/pallets/{pallet}/bases', [PalletBaseController::class, 'store']);
            Route::patch('/pallets/{pallet}/bases/{base}', [PalletBaseController::class, 'update']);
            Route::delete('/pallets/{pallet}/bases/{base}', [PalletBaseController::class, 'destroy']);

            // Fotos de bases
            Route::post('/pallets/{pallet}/bases/{base}/photos', [PalletBasePhotoController::class, 'store']);
            Route::delete('/pallets/{pallet}/bases/{base}/photos/{photo}', [PalletBasePhotoController::class, 'destroy']);

            // Anotaciones de fotos
            Route::get('/pallets/{pallet}/bases/{base}/photos/{photo}/annotations', [PhotoAnnotationController::class, 'index']);
            Route::post('/pallets/{pallet}/bases/{base}/photos/{photo}/annotations', [PhotoAnnotationController::class, 'store']);

            // Activity Logs
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        }); // end has.role

        // Admin — gestión de usuarios (requiere rol admin o superadmin)
        Route::middleware('admin')->prefix('admin')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
            Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
        });
    });
});
