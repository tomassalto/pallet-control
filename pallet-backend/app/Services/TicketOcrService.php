<?php

namespace App\Services;

use App\Models\OrderTicketPhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Servicio de OCR para fotos de tickets.
 *
 * Usa Tesseract (CLI) para extraer texto + bounding boxes de imágenes de
 * tickets escaneados o fotografiados. Detecta EAN codes de 13 dígitos y
 * devuelve sus coordenadas exactas para generar overlays en el frontend.
 */
class TicketOcrService
{
    /**
     * Procesa una foto de ticket: ejecuta OCR y guarda el resultado en el modelo.
     * Retorna true si se procesó correctamente, false si Tesseract no está disponible
     * o la imagen no pudo procesarse.
     */
    public function processPhoto(OrderTicketPhoto $photo): bool
    {
        $imagePath = Storage::disk('public')->path($photo->path);

        if (! file_exists($imagePath)) {
            Log::warning("TicketOcrService: imagen no encontrada: {$photo->path}");
            return false;
        }

        $result = $this->extractEans($imagePath);

        $photo->update([
            'ocr_data'         => $result,   // null si Tesseract falló, array si procesó
            'ocr_processed_at' => now(),
        ]);

        return $result !== null;
    }

    /**
     * Extrae EANs de 13 dígitos con sus bounding boxes desde una imagen.
     * Retorna null si Tesseract no está disponible o hay un error.
     *
     * Formato de retorno:
     * [
     *   'img_w' => 2000,
     *   'img_h' => 3000,
     *   'eans'  => [
     *     ['ean' => '7792798003716', 'bbox' => ['left'=>100,'top'=>200,'right'=>300,'bottom'=>230]],
     *     ...
     *   ]
     * ]
     */
    /**
     * Detecta la ruta del ejecutable de Tesseract.
     * Prioridad: env TESSERACT_PATH → tesseract en PATH → rutas conocidas de Windows.
     */
    private function tesseractBin(): string
    {
        // Variable de entorno configurable en .env
        if ($envPath = env('TESSERACT_PATH')) {
            return $envPath;
        }

        // Rutas conocidas en Windows (cuando PHP no hereda el PATH del usuario)
        $windowsPaths = [
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ];
        foreach ($windowsPaths as $p) {
            if (file_exists($p)) return $p;
        }

        // Asumir que está en el PATH (Linux / Docker / Railway / Render)
        return 'tesseract';
    }

    public function extractEans(string $imagePath): ?array
    {
        // Archivo temporal para la salida de Tesseract
        $tmpBase = tempnam(sys_get_temp_dir(), 'pallet_ocr_');

        try {
            $bin = $this->tesseractBin();

            // Ejecutar Tesseract con salida hOCR
            // PSM 6: bloque de texto uniforme (ideal para tickets/facturas)
            // Nota: escapeshellarg() en el bin maneja rutas con espacios en Windows
            $cmd = sprintf(
                '%s %s %s --psm 6 hocr 2>&1',
                escapeshellarg($bin),
                escapeshellarg($imagePath),
                escapeshellarg($tmpBase)
            );

            exec($cmd, $output, $returnCode);

            $hocrFile = $tmpBase . '.hocr';

            if ($returnCode !== 0 || ! file_exists($hocrFile)) {
                Log::info('TicketOcrService: Tesseract no disponible o falló.', [
                    'cmd'    => $cmd,
                    'output' => implode("\n", $output),
                    'code'   => $returnCode,
                ]);
                return null;
            }

            $hocr = file_get_contents($hocrFile);

        } finally {
            // Limpiar archivos temporales siempre
            @unlink($tmpBase);
            @unlink($tmpBase . '.hocr');
        }

        return $this->parseHocr($hocr);
    }

    /**
     * Parsea el hOCR generado por Tesseract y extrae:
     *   - Dimensiones de la imagen
     *   - EANs (13 dígitos) con sus bounding boxes
     */
    private function parseHocr(string $hocr): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $hocr);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // ── Dimensiones de la página desde el elemento ocr_page ───────────
        $imgW = 0;
        $imgH = 0;
        $pages = $xpath->query("//*[contains(@class,'ocr_page')]");
        if ($pages && $pages->length > 0) {
            $title = $pages->item(0)->getAttribute('title');
            // Formato: "image "/path"; bbox 0 0 2480 3508; ppageno 0"
            if (preg_match('/bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $m)) {
                $imgW = (int) $m[3];
                $imgH = (int) $m[4];
            }
        }

        // ── Extraer palabras con bounding boxes ───────────────────────────
        $words = $xpath->query("//*[contains(@class,'ocrx_word')]");
        $eans  = [];

        if ($words) {
            foreach ($words as $word) {
                $text = trim($word->textContent);

                // Limpiar ruido tipográfico que Tesseract puede introducir
                $clean = preg_replace('/[^0-9]/', '', $text);

                // EAN: exactamente 13 dígitos
                if (strlen($clean) !== 13) {
                    continue;
                }

                $title = $word->getAttribute('title');
                // bbox left top right bottom
                if (! preg_match('/bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $m)) {
                    continue;
                }

                $eans[] = [
                    'ean'  => $clean,
                    'bbox' => [
                        'left'   => (int) $m[1],
                        'top'    => (int) $m[2],
                        'right'  => (int) $m[3],
                        'bottom' => (int) $m[4],
                    ],
                ];
            }
        }

        // Eliminar EANs duplicados (misma posición + mismo código)
        $seen  = [];
        $unique = [];
        foreach ($eans as $e) {
            $key = $e['ean'] . '|' . $e['bbox']['left'] . '|' . $e['bbox']['top'];
            if (! isset($seen[$key])) {
                $seen[$key]  = true;
                $unique[]    = $e;
            }
        }

        return [
            'img_w' => $imgW,
            'img_h' => $imgH,
            'eans'  => $unique,
        ];
    }

    /**
     * Construye los highlights para el frontend cruzando los EANs detectados
     * contra los productos presentes en el pallet.
     *
     * $ocrData: resultado de extractEans() guardado en ocr_data
     * $palletEanMap: [ ean => ['description'=>..., 'total_qty'=>..., 'orders'=>[...]] ]
     *
     * Retorna array de highlights listos para el frontend.
     */
    public static function buildHighlights(array $ocrData, array $palletEanMap): array
    {
        $highlights = [];

        foreach ($ocrData['eans'] as $detected) {
            $ean = $detected['ean'];

            if (! isset($palletEanMap[$ean])) {
                continue; // EAN no está en el pallet → no resaltar
            }

            $palletInfo  = $palletEanMap[$ean];
            $eanHeight   = $detected['bbox']['bottom'] - $detected['bbox']['top'];

            // Expandir el highlight hacia arriba para cubrir descripción + cantidad
            // (~2 líneas sobre el EAN = descripción + línea de cantidades)
            $expandedTop = max(0, $detected['bbox']['top'] - (int) ($eanHeight * 3.2));

            $highlights[] = [
                'ean'          => $ean,
                'description'  => $palletInfo['description'],
                'qty_in_pallet'=> $palletInfo['total_qty'],
                'orders'       => $palletInfo['orders'],
                'img_w'        => $ocrData['img_w'],
                'img_h'        => $ocrData['img_h'],
                // Bbox original del EAN (para tooltip)
                'ean_bbox'     => $detected['bbox'],
                // Bbox expandido para el overlay visual
                'bbox'         => [
                    'left'   => max(0, $detected['bbox']['left'] - 8),
                    'top'    => $expandedTop,
                    'right'  => min($ocrData['img_w'], $detected['bbox']['right'] + 8),
                    'bottom' => $detected['bbox']['bottom'] + 4,
                ],
            ];
        }

        return $highlights;
    }
}
