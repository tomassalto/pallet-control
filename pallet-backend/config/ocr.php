<?php

return [

    /*
    | Proveedor OCR principal: 'tesseract' | 'azure' | 'paddle'
    | En producción con config:cache activo, env() devuelve null.
    | SIEMPRE usar config('ocr.*') en el código de aplicación.
    */
    'provider' => env('OCR_PROVIDER', 'tesseract'),

    /*
    | Ruta al ejecutable de Tesseract (null = auto-detect desde PATH o rutas de Windows).
    */
    'tesseract_path' => env('TESSERACT_PATH'),

    /*
    | Si PaddleOCR falla, intentar con Tesseract como fallback.
    */
    'fallback_tesseract' => env('OCR_FALLBACK_TESSERACT', true),

    /*
    | Timeout en segundos para procesos OCR externos (Tesseract, PaddleOCR).
    */
    'process_timeout' => env('OCR_PROCESS_TIMEOUT', 120),

    /*
    | Azure Computer Vision (Image Analysis 4.0).
    | Free tier: 5.000 requests/mes.
    */
    'azure' => [
        'endpoint' => env('AZURE_VISION_ENDPOINT', ''),
        'key'      => env('AZURE_VISION_KEY', ''),
    ],

    /*
    | PaddleOCR (proceso Python externo).
    */
    'paddle' => [
        'script'     => env('PADDLE_OCR_SCRIPT', base_path('scripts/paddle_ocr_ticket.py')),
        'python_bin' => env('OCR_PYTHON_BIN', 'python'),
        'lang'       => env('PADDLE_OCR_LANG', 'en'),
    ],

];
