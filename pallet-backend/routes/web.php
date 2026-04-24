<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Ruta para verificar configuración PHP
Route::get('/check-php-config.php', function () {
    ob_start();
    include public_path('check-php-config.php');
    return ob_get_clean();
});

// Ruta alternativa para servir imágenes del storage con headers CORS correctos
Route::get('/storage/{path}', function (string $path) {
    $filePath = storage_path('app/public/' . $path);
    if (!file_exists($filePath)) abort(404);

    $mimeType = mime_content_type($filePath);
    return response(file_get_contents($filePath), 200)
        ->header('Content-Type', $mimeType)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type')
        ->header('Cache-Control', 'public, max-age=31536000');
})->where('path', '.*');

// Redirige /app/pallet-view/* → /pallet-view/* para quienes añaden /app/ por error
Route::get('/app/pallet-view/{code}', function (string $code) {
    return redirect("/pallet-view/{$code}", 301);
});

// Catch-all: sirve assets del build de Vite o el index.html de la SPA
Route::get('/{any?}', function (string $any = '') {
    // Si la URL corresponde a un archivo en public/app/, servirlo directamente
    if ($any !== '') {
        $filePath = public_path("app/{$any}");
        if (File::exists($filePath) && is_file($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimes = [
                'js'           => 'application/javascript',
                'css'          => 'text/css',
                'png'          => 'image/png',
                'svg'          => 'image/svg+xml',
                'webp'         => 'image/webp',
                'ico'          => 'image/x-icon',
                'woff'         => 'font/woff',
                'woff2'        => 'font/woff2',
                'ttf'          => 'font/ttf',
                'json'         => 'application/json',
                'webmanifest'  => 'application/manifest+json',
            ];
            $mime = $mimes[$ext] ?? mime_content_type($filePath);

            $response = response(File::get($filePath), 200)
                ->header('Content-Type', $mime)
                ->header('Cache-Control', 'public, max-age=31536000');

            // El service worker necesita este header para controlar scope /
            if ($ext === 'js' && str_ends_with($any, 'sw.js')) {
                $response->header('Service-Worker-Allowed', '/');
            }

            return $response;
        }
    }

    // Todo lo demás → SPA
    return File::get(public_path('app/index.html'));
})->where('any', '^(?!api|storage|check-php-config).*$');
