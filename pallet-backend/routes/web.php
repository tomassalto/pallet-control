<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;


// Ruta para verificar configuración PHP (ejecuta el script directamente)
Route::get('/check-php-config.php', function () {
    ob_start();
    include public_path('check-php-config.php');
    return ob_get_clean();
});

// PWA: Service Worker - debe estar en la raíz para controlar scope /
Route::get('/sw.js', function () {
    $path = public_path('app/sw.js');
    if (!File::exists($path)) {
        return response('// service worker not built yet', 200)
            ->header('Content-Type', 'application/javascript');
    }
    return response(File::get($path), 200)
        ->header('Content-Type', 'application/javascript')
        ->header('Service-Worker-Allowed', '/');
});

// PWA: Web App Manifest
Route::get('/manifest.webmanifest', function () {
    $path = public_path('app/manifest.webmanifest');
    if (!File::exists($path)) {
        abort(404);
    }
    return response(File::get($path), 200)
        ->header('Content-Type', 'application/manifest+json');
});

// PWA: Iconos en la raíz
Route::get('/pallet-icon-{size}.png', function (string $size) {
    $path = public_path("app/pallet-icon-{$size}.png");
    if (!File::exists($path)) {
        abort(404);
    }
    return response(File::get($path), 200)
        ->header('Content-Type', 'image/png')
        ->header('Cache-Control', 'public, max-age=31536000');
});

Route::get('/{any?}', function () {
    return File::get(public_path('app/index.html'));
})->where('any', '^(?!api|storage|check-php-config|sw\.js|manifest\.webmanifest).*$');

// Ruta alternativa para servir imágenes con headers CORS correctos
Route::get('/storage/{path}', function ($path) {
    $filePath = storage_path('app/public/' . $path);

    if (!file_exists($filePath)) {
        abort(404);
    }

    $file = file_get_contents($filePath);
    $mimeType = mime_content_type($filePath);

    return response($file, 200)
        ->header('Content-Type', $mimeType)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type')
        ->header('Cache-Control', 'public, max-age=31536000');
})->where('path', '.*');
