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
    private const EAN_LENGTH = 13;

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

    /**
     * Escala la imagen para que Tesseract pueda leer texto pequeño.
     * Tesseract necesita al menos ~150px de altura por línea de texto.
     * Si la imagen es pequeña, la multiplicamos para mejorar el OCR.
     * Retorna la ruta de la imagen preparada (puede ser un tmp nuevo).
     */
    private function prepareImageForOcr(string $imagePath): string
    {
        $info = @getimagesize($imagePath);
        if (! $info) return $imagePath;

        [$w, $h, $type] = $info;

        if (config('app.debug')) {
            Log::info('TicketOcrService: dimensiones originales', ['w' => $w, 'h' => $h]);
        }

        // Si ya es grande, no escalar
        if ($w >= 2000 || $h >= 2000) return $imagePath;

        // Escalar para que el lado mayor quede en ~3000px
        $scale = max(2, (int) ceil(3000 / max($w, $h)));
        $newW  = $w * $scale;
        $newH  = $h * $scale;

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($imagePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($imagePath),
            default        => null,
        };

        if (! $src) return $imagePath;

        $dst = imagecreatetruecolor($newW, $newH);
        // Fondo blanco (para WebPs con transparencia)
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);

        // ── Preprocessing para mejorar OCR ────────────────────────────────
        // 1. Escala de grises (elimina ruido de color)
        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        // 2. Aumentar contraste para texto negro sobre fondo claro
        imagefilter($dst, IMG_FILTER_BRIGHTNESS, 10);
        imagefilter($dst, IMG_FILTER_CONTRAST, -40); // negativo = más contraste
        // 3. Nitidez con convolución (IMG_FILTER_SHARPEN no existe en GD)
        imageconvolution($dst, [[-1,-1,-1],[-1,16,-1],[-1,-1,-1]], 8, 0);

        $tmpPng = tempnam(sys_get_temp_dir(), 'pallet_ocr_img_') . '.png';
        imagepng($dst, $tmpPng, 0); // sin compresión para velocidad

        $debugPath = null;
        // Guardar copia de debug solo en modo debug para evitar ruido en producción.
        if (config('app.debug')) {
            $debugPath = storage_path('logs/ocr_debug_scaled.png');
            imagepng($dst, $debugPath, 0);
        }

        imagedestroy($dst);

        if (config('app.debug')) {
            Log::info('TicketOcrService: imagen escalada', [
                'scale'      => $scale,
                'new_w'      => $newW,
                'new_h'      => $newH,
                'debug_img'  => $debugPath,
            ]);
        }

        return $tmpPng;
    }

    private function prepareImageVariantsForOcr(string $imagePath): array
    {
        $info = @getimagesize($imagePath);
        if (! $info) {
            return [['label' => 'original', 'path' => $imagePath, 'temporary' => false]];
        }

        [$w, $h, $type] = $info;
        if (config('app.debug')) {
            Log::info('TicketOcrService: preparando variantes OCR', ['w' => $w, 'h' => $h]);
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($imagePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($imagePath),
            default        => null,
        };

        if (! $src) {
            return [['label' => 'original', 'path' => $imagePath, 'temporary' => false]];
        }

        $scale = ($w >= 2000 || $h >= 2000) ? 1 : max(2, (int) ceil(3000 / max($w, $h)));
        $newW = $w * $scale;
        $newH = $h * $scale;
        $variants = [];

        $recipes = [
            ['label' => 'scaled', 'gray' => false, 'contrast' => false, 'sharp' => false, 'rotate' => 0],
            ['label' => 'gray', 'gray' => true, 'contrast' => false, 'sharp' => false, 'rotate' => 0],
            ['label' => 'contrast', 'gray' => true, 'contrast' => true, 'sharp' => false, 'rotate' => 0],
            ['label' => 'sharp', 'gray' => true, 'contrast' => true, 'sharp' => true, 'rotate' => 0],
            ['label' => 'rot90_sharp', 'gray' => true, 'contrast' => true, 'sharp' => true, 'rotate' => 90],
            ['label' => 'rot270_sharp', 'gray' => true, 'contrast' => true, 'sharp' => true, 'rotate' => 270],
        ];

        foreach ($recipes as $recipe) {
            $label = $recipe['label'];
            $dst = imagecreatetruecolor($newW, $newH);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

            if ($recipe['gray']) {
                imagefilter($dst, IMG_FILTER_GRAYSCALE);
            }

            if ($recipe['contrast']) {
                imagefilter($dst, IMG_FILTER_BRIGHTNESS, 8);
                imagefilter($dst, IMG_FILTER_CONTRAST, -28);
            }

            if ($recipe['sharp']) {
                imageconvolution($dst, [[-1,-1,-1],[-1,16,-1],[-1,-1,-1]], 8, 0);
            }

            if ($recipe['rotate'] !== 0) {
                $rotated = imagerotate($dst, $recipe['rotate'], 255);
                if ($rotated !== false) {
                    imagedestroy($dst);
                    $dst = $rotated;
                }
            }

            $tmpBase = tempnam(sys_get_temp_dir(), 'pallet_ocr_img_');
            if ($tmpBase === false) {
                imagedestroy($dst);
                continue;
            }

            @unlink($tmpBase);
            $tmpPng = $tmpBase . '.png';
            imagepng($dst, $tmpPng, 0);

            if ($label === 'sharp' && config('app.debug')) {
                imagepng($dst, storage_path('logs/ocr_debug_scaled.png'), 0);
            }

            imagedestroy($dst);

            $variants[] = [
                'label' => $label,
                'path' => $tmpPng,
                'temporary' => true,
            ];
        }

        imagedestroy($src);

        if (config('app.debug')) {
            Log::info('TicketOcrService: variantes OCR listas', [
                'scale' => $scale,
                'new_w' => $newW,
                'new_h' => $newH,
                'variants' => array_column($variants, 'label'),
            ]);
        }

        return $variants ?: [['label' => 'original', 'path' => $imagePath, 'temporary' => false]];
    }

    public function extractEans(string $imagePath): ?array
    {
        $provider = strtolower((string) env('OCR_PROVIDER', 'tesseract'));

        if ($provider === 'paddle') {
            $result = $this->extractEansWithPaddle($imagePath);
            if ($result !== null) {
                return $result;
            }

            if (filter_var(env('OCR_FALLBACK_TESSERACT', true), FILTER_VALIDATE_BOOL)) {
                Log::warning('TicketOcrService: PaddleOCR falló, usando Tesseract como fallback.');
                return $this->extractEansWithTesseract($imagePath);
            }

            return null;
        }

        return $this->extractEansWithTesseract($imagePath);
    }

    private function extractEansWithTesseract(string $imagePath): ?array
    {
        if (config('app.debug')) {
            Log::info('TicketOcrService: iniciando OCR', [
                'image' => $imagePath,
                'exists' => file_exists($imagePath),
                'size'  => file_exists($imagePath) ? filesize($imagePath) : 0,
            ]);
        }

        $variants = $this->prepareImageVariantsForOcr($imagePath);
        $configs = [
            ['psm' => 6,  'whitelist' => true],
            ['psm' => 11, 'whitelist' => true],
            ['psm' => 12, 'whitelist' => true],
            ['psm' => 6,  'whitelist' => false],
        ];

        $merged = ['img_w' => 0, 'img_h' => 0, 'eans' => []];
        $seen = [];
        $ranAny = false;

        try {
            $bin = $this->tesseractBin();
            if (config('app.debug')) {
                Log::info('TicketOcrService: Tesseract bin detectado', ['bin' => $bin]);
            }

            foreach ($variants as $variant) {
                foreach ($configs as $config) {
                    $tmpBase = tempnam(sys_get_temp_dir(), 'pallet_ocr_');
                    if ($tmpBase === false) {
                        continue;
                    }

                    try {
                        $extra = $config['whitelist']
                            ? ' -c tessedit_char_whitelist=0123456789'
                            : '';

                        $cmd = sprintf(
                            '%s %s %s --psm %d --oem 1%s hocr 2>&1',
                            escapeshellarg($bin),
                            escapeshellarg($variant['path']),
                            escapeshellarg($tmpBase),
                            $config['psm'],
                            $extra
                        );

                        if (config('app.debug')) {
                            Log::info('TicketOcrService: ejecutando comando', [
                                'cmd' => $cmd,
                                'variant' => $variant['label'],
                                'psm' => $config['psm'],
                                'whitelist' => $config['whitelist'],
                            ]);
                        }

                        exec($cmd, $output, $returnCode);
                        $hocrFile = $tmpBase . '.hocr';

                        if (config('app.debug')) {
                            Log::info('TicketOcrService: resultado exec', [
                                'code' => $returnCode,
                                'hocr_exists' => file_exists($hocrFile),
                                'variant' => $variant['label'],
                                'psm' => $config['psm'],
                                'output' => implode("\n", $output),
                            ]);
                        }

                        if ($returnCode !== 0 || ! file_exists($hocrFile)) {
                            continue;
                        }

                        $ranAny = true;
                        $parsed = $this->parseHocr(file_get_contents($hocrFile));

                        if ($merged['img_w'] === 0 && $parsed['img_w'] > 0) {
                            $merged['img_w'] = $parsed['img_w'];
                            $merged['img_h'] = $parsed['img_h'];
                        }

                        foreach ($parsed['eans'] as $ean) {
                            $key = $ean['ean'] . '|' . (int) floor($ean['bbox']['left'] / 20) . '|' . (int) floor($ean['bbox']['top'] / 20);
                            if (isset($seen[$key])) {
                                continue;
                            }

                            $seen[$key] = true;
                            $ean['ocr_variant'] = $variant['label'];
                            $ean['ocr_psm'] = $config['psm'];
                            $merged['eans'][] = $ean;
                        }
                    } finally {
                        @unlink($tmpBase);
                        @unlink($tmpBase . '.hocr');
                    }
                }
            }
        } finally {
            foreach ($variants as $variant) {
                if (! empty($variant['temporary'])) {
                    @unlink($variant['path']);
                }
            }
        }

        if (! $ranAny) {
            Log::warning('TicketOcrService: Tesseract no disponible o falló en todas las variantes.');
            return null;
        }

        if (config('app.debug')) {
            Log::info('TicketOcrService: OCR fusionado', [
                'eans' => array_column($merged['eans'], 'ean'),
                'count' => count($merged['eans']),
            ]);
        }

        return $merged;
    }

    private function extractEansWithPaddle(string $imagePath): ?array
    {
        $script = (string) env(
            'PADDLE_OCR_SCRIPT',
            base_path('scripts/paddle_ocr_ticket.py')
        );
        $python = (string) env('OCR_PYTHON_BIN', 'python');
        $lang = (string) env('PADDLE_OCR_LANG', 'en');
        $timeout = max(10, (int) env('OCR_PROCESS_TIMEOUT', 120));

        if (! is_file($script)) {
            Log::warning('TicketOcrService: script PaddleOCR no encontrado.', ['script' => $script]);
            return null;
        }

        $cmd = sprintf(
            '%s %s %s --lang %s',
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($imagePath),
            escapeshellarg($lang)
        );

        if (config('app.debug')) {
            Log::info('TicketOcrService: ejecutando PaddleOCR', [
                'cmd' => $cmd,
                'image' => $imagePath,
            ]);
        }

        $started = microtime(true);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (! is_resource($process)) {
            Log::warning('TicketOcrService: no se pudo iniciar PaddleOCR.');
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if ((microtime(true) - $started) > $timeout) {
                proc_terminate($process);
                $timedOut = true;
                break;
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            Log::warning('TicketOcrService: PaddleOCR excedió timeout.', ['timeout' => $timeout]);
            return null;
        }

        $json = trim($stdout);
        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            $start = strpos($json, '{"ok"');
            $end = strrpos($json, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $payload = json_decode(substr($json, $start, $end - $start + 1), true);
            }
        }

        if ($exitCode !== 0 || ! is_array($payload) || ! ($payload['ok'] ?? false)) {
            Log::warning('TicketOcrService: PaddleOCR falló.', [
                'exit_code' => $exitCode,
                'json_error' => json_last_error_msg(),
                'stdout_length' => strlen($stdout),
                'stdout' => mb_substr($stdout, 0, 2000),
                'stderr' => mb_substr($stderr, 0, 2000),
                'payload' => is_array($payload) ? $payload : null,
            ]);
            return null;
        }

        $result = $this->parsePaddleLines($payload);

        if (config('app.debug')) {
            Log::info('TicketOcrService: PaddleOCR finalizado', [
                'line_count' => count($payload['lines'] ?? []),
                'ean_count' => count($result['eans']),
                'eans' => array_column($result['eans'], 'ean'),
            ]);
        }

        return $result;
    }

    private function parsePaddleLines(array $payload): array
    {
        $eans = [];

        foreach (($payload['lines'] ?? []) as $line) {
            $text = (string) ($line['text'] ?? '');
            $digits = preg_replace('/\D+/', '', $text);
            if (strlen($digits) < self::EAN_LENGTH) {
                continue;
            }

            $bbox = $line['bbox'] ?? null;
            if (! $this->isValidBbox($bbox)) {
                continue;
            }

            foreach ($this->validEanWindows($digits) as $candidate) {
                $eans[] = [
                    'ean' => $candidate,
                    'bbox' => [
                        'left' => (int) $bbox['left'],
                        'top' => (int) $bbox['top'],
                        'right' => (int) $bbox['right'],
                        'bottom' => (int) $bbox['bottom'],
                    ],
                    'source' => 'paddle_line',
                    'text' => $text,
                    'confidence' => $line['confidence'] ?? null,
                    'polygon' => $line['polygon'] ?? null,
                ];
            }
        }

        $bestByEan = [];
        foreach ($eans as $ean) {
            $key = $ean['ean'];
            if (! isset($bestByEan[$key])) {
                $bestByEan[$key] = $ean;
                continue;
            }

            $currentScore = $this->scorePaddleDetection($bestByEan[$key]);
            $nextScore = $this->scorePaddleDetection($ean);
            if ($nextScore > $currentScore) {
                $bestByEan[$key] = $ean;
            }
        }

        return [
            'engine' => 'paddleocr',
            'img_w' => (int) ($payload['img_w'] ?? 0),
            'img_h' => (int) ($payload['img_h'] ?? 0),
            'lines' => $payload['lines'] ?? [],
            'eans' => array_values($bestByEan),
        ];
    }

    private function scorePaddleDetection(array $detection): float
    {
        $bbox = $detection['bbox'] ?? [];
        $area = max(1, ((int) ($bbox['right'] ?? 0) - (int) ($bbox['left'] ?? 0)))
            * max(1, ((int) ($bbox['bottom'] ?? 0) - (int) ($bbox['top'] ?? 0)));

        return ((float) ($detection['confidence'] ?? 0)) * 100000 + $area;
    }

    private function isValidBbox(mixed $bbox): bool
    {
        return is_array($bbox)
            && isset($bbox['left'], $bbox['top'], $bbox['right'], $bbox['bottom'])
            && $bbox['right'] > $bbox['left']
            && $bbox['bottom'] > $bbox['top'];
    }

    /**
     * Parsea el hOCR generado por Tesseract y extrae:
     *   - Dimensiones de la imagen
     *   - EANs (13 dígitos) con sus bounding boxes
     */
    /**
     * Valida checksum EAN-13.
     * Elimina casi todos los falsos positivos (probabilidad ~1/10 de acertar al azar).
     */
    private function isValidEan13(string $ean): bool
    {
        if (strlen($ean) !== 13) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $ean[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return ((10 - ($sum % 10)) % 10) === (int) $ean[12];
    }

    private function parseHocr(string $hocr): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $hocr);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // ── Dimensiones ───────────────────────────────────────────────────
        $imgW = 0; $imgH = 0;
        $pages = $xpath->query("//*[contains(@class,'ocr_page')]");
        if ($pages && $pages->length > 0) {
            $title = $pages->item(0)->getAttribute('title');
            if (preg_match('/bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $m)) {
                $imgW = (int) $m[3];
                $imgH = (int) $m[4];
            }
        }

        if (config('app.debug')) {
            Log::info('TicketOcrService: dimensiones detectadas', ['img_w' => $imgW, 'img_h' => $imgH]);
        }

        // ── Recopilar tokens de dígitos con sus bboxes ────────────────────
        // Con imagen torcida, Tesseract puede leer cada dígito como palabra
        // separada. Los agrupamos por proximidad espacial para reconstruir
        // los strings numéricos completos.
        $words  = $xpath->query("//*[contains(@class,'ocrx_word')]");
        $tokens = [];

        if ($words) {
            foreach ($words as $word) {
                $clean = preg_replace('/[^0-9]/', '', trim($word->textContent));
                if ($clean === '') continue;

                $title = $word->getAttribute('title');
                if (! preg_match('/bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $m)) continue;

                $tokens[] = [
                    'digits' => $clean,
                    'left'   => (int) $m[1],
                    'top'    => (int) $m[2],
                    'right'  => (int) $m[3],
                    'bottom' => (int) $m[4],
                ];
            }
        }

        // ── Leer líneas completas (ocr_line) ─────────────────────────────
        // En vez de words individuales, tomamos cada línea entera → sacamos
        // solo dígitos → buscamos secuencias de 13 con checksum EAN válido.
        // Esto es más robusto que agrupar words cuando la imagen está torcida.
        $lines    = $xpath->query("//*[contains(@class,'ocr_line')]");
        $lineData = [];

        if ($lines) {
            foreach ($lines as $line) {
                $fullText = trim($line->textContent);
                $digits   = preg_replace('/[^0-9]/', '', $fullText);
                if (strlen($digits) < 10) continue;

                $title = $line->getAttribute('title');
                if (! preg_match('/bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $m)) continue;

                $lineData[] = [
                    'text'   => $fullText,
                    'digits' => $digits,
                    'left'   => (int) $m[1],
                    'top'    => (int) $m[2],
                    'right'  => (int) $m[3],
                    'bottom' => (int) $m[4],
                ];
            }
        }

        if (config('app.debug')) {
            Log::info('TicketOcrService: líneas con 10+ dígitos', [
                'count' => count($lineData),
                'lines' => array_map(fn ($l) => [
                    'text'   => $l['text'],
                    'digits' => $l['digits'],
                    'len'    => strlen($l['digits']),
                ], $lineData),
            ]);
        }

        // ── Extraer EAN-13 válidos de cada línea ─────────────────────────
        $eans = [];
        foreach ($lineData as $line) {
            $digits = $line['digits'];
            foreach ($this->validEanWindows($digits) as $candidate) {
                $eans[] = [
                    'ean'  => $candidate,
                    'bbox' => [
                        'left'   => $line['left'],
                        'top'    => $line['top'],
                        'right'  => $line['right'],
                        'bottom' => $line['bottom'],
                    ],
                    'source' => 'line',
                ];
            }
        }

        $eans = array_merge($eans, $this->extractEansFromDigitTokens($tokens));

        if (config('app.debug')) {
            Log::info('TicketOcrService: EANs con checksum válido', [
                'count' => count($eans),
                'eans'  => array_column($eans, 'ean'),
                'token_count' => count($tokens),
            ]);
        }

        // Deduplicar
        $seen = []; $unique = [];
        foreach ($eans as $e) {
            $key = $e['ean'] . '|' . (int) floor($e['bbox']['left'] / 20) . '|' . (int) floor($e['bbox']['top'] / 20);
            if (! isset($seen[$key])) { $seen[$key] = true; $unique[] = $e; }
        }

        return ['img_w' => $imgW, 'img_h' => $imgH, 'eans' => $unique];
    }

    /**
     * Tesseract puede devolver un EAN como 13 tokens de 1 digito cuando la
     * foto esta inclinada o tiene poca resolucion. Esta reconstruccion no rota
     * la imagen: rota virtualmente los centros de los bboxes en angulos chicos
     * para encontrar filas coherentes, concatena digitos cercanos y valida por
     * checksum EAN-13.
     */
    private function extractEansFromDigitTokens(array $tokens): array
    {
        if (count($tokens) < self::EAN_LENGTH) {
            return [];
        }

        $tokens = array_values(array_filter($tokens, fn ($t) => preg_match('/^\d+$/', $t['digits'])));
        if (count($tokens) < self::EAN_LENGTH) {
            return [];
        }

        $heights = array_values(array_filter(array_map(
            fn ($t) => max(1, $t['bottom'] - $t['top']),
            $tokens
        )));
        $widths = array_values(array_filter(array_map(
            fn ($t) => max(1, ($t['right'] - $t['left']) / max(1, strlen($t['digits']))),
            $tokens
        )));

        $medianHeight = $this->median($heights) ?: 20.0;
        $medianDigitWidth = $this->median($widths) ?: 12.0;
        $bestByEan = [];
        $debugCandidates = [];

        foreach (range(-8, 8) as $angle) {
            $lines = $this->clusterTokensByVirtualDeskew($tokens, $angle, $medianHeight);

            foreach ($lines as $line) {
                if (count($line) < 4) {
                    continue;
                }

                usort($line, fn ($a, $b) => $a['left'] <=> $b['left']);

                foreach ($this->lineSegments($line, $medianDigitWidth) as $segment) {
                    $digits = implode('', array_column($segment, 'digits'));
                    if (strlen($digits) < self::EAN_LENGTH) {
                        continue;
                    }

                    $debugCandidates[] = [
                        'angle' => $angle,
                        'digits' => $digits,
                        'len' => strlen($digits),
                    ];

                    foreach ($this->validEanWindows($digits) as $ean) {
                        $score = $this->scoreSegment($segment, $angle);

                        if (! isset($bestByEan[$ean]) || $score > $bestByEan[$ean]['score']) {
                            $bestByEan[$ean] = [
                                'ean' => $ean,
                                'bbox' => $this->bboxForSegment($segment),
                                'source' => 'tokens',
                                'score' => $score,
                            ];
                        }
                    }
                }
            }
        }

        usort($debugCandidates, fn ($a, $b) => $b['len'] <=> $a['len']);
        if (config('app.debug')) {
            Log::info('TicketOcrService: candidatos reconstruidos desde tokens', [
                'count' => count($debugCandidates),
                'top' => array_slice($debugCandidates, 0, 15),
                'valid_eans' => array_keys($bestByEan),
            ]);
        }

        return array_map(function ($item) {
            unset($item['score']);
            return $item;
        }, array_values($bestByEan));
    }

    private function clusterTokensByVirtualDeskew(array $tokens, int $angleDeg, float $medianHeight): array
    {
        $theta = deg2rad($angleDeg);
        $cos = cos($theta);
        $sin = sin($theta);
        $prepared = [];

        foreach ($tokens as $token) {
            $cx = ($token['left'] + $token['right']) / 2;
            $cy = ($token['top'] + $token['bottom']) / 2;
            $token['deskew_y'] = ($cy * $cos) - ($cx * $sin);
            $prepared[] = $token;
        }

        usort($prepared, fn ($a, $b) => $a['deskew_y'] <=> $b['deskew_y']);

        $lines = [];
        $lineTolerance = max(10, $medianHeight * 0.72);

        foreach ($prepared as $token) {
            $bestIndex = null;
            $bestDistance = INF;

            foreach ($lines as $idx => $line) {
                $distance = abs($token['deskew_y'] - $line['avg_y']);
                if ($distance < $bestDistance && $distance <= $lineTolerance) {
                    $bestDistance = $distance;
                    $bestIndex = $idx;
                }
            }

            if ($bestIndex === null) {
                $lines[] = [
                    'avg_y' => $token['deskew_y'],
                    'count' => 1,
                    'tokens' => [$token],
                ];
                continue;
            }

            $line = &$lines[$bestIndex];
            $line['tokens'][] = $token;
            $line['count']++;
            $line['avg_y'] += ($token['deskew_y'] - $line['avg_y']) / $line['count'];
            unset($line);
        }

        return array_map(fn ($line) => $line['tokens'], $lines);
    }

    private function lineSegments(array $line, float $medianDigitWidth): array
    {
        $segments = [];
        $current = [];
        $maxGap = max(12, $medianDigitWidth * 2.8);

        foreach ($line as $token) {
            if ($current === []) {
                $current[] = $token;
                continue;
            }

            $prev = $current[count($current) - 1];
            $gap = $token['left'] - $prev['right'];
            $prevHeight = max(1, $prev['bottom'] - $prev['top']);
            $tokenHeight = max(1, $token['bottom'] - $token['top']);
            $verticalOverlap = max(0, min($prev['bottom'], $token['bottom']) - max($prev['top'], $token['top']));
            $overlapRatio = $verticalOverlap / max(1, min($prevHeight, $tokenHeight));

            if ($gap <= $maxGap || ($gap <= $medianDigitWidth * 4.2 && $overlapRatio >= 0.25)) {
                $current[] = $token;
                continue;
            }

            $segments[] = $current;
            $current = [$token];
        }

        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    private function validEanWindows(string $digits): array
    {
        $digits = preg_replace('/\D+/', '', $digits);
        $valid = [];
        $len = strlen($digits);

        for ($i = 0; $i <= $len - self::EAN_LENGTH; $i++) {
            $candidate = substr($digits, $i, self::EAN_LENGTH);
            if ($this->isValidEan13($candidate)) {
                $valid[$candidate] = $candidate;
            }
        }

        return array_values($valid);
    }

    private function bboxForSegment(array $segment): array
    {
        return [
            'left' => min(array_column($segment, 'left')),
            'top' => min(array_column($segment, 'top')),
            'right' => max(array_column($segment, 'right')),
            'bottom' => max(array_column($segment, 'bottom')),
        ];
    }

    private function scoreSegment(array $segment, int $angle): float
    {
        $digits = strlen(implode('', array_column($segment, 'digits')));
        $width = max(1, max(array_column($segment, 'right')) - min(array_column($segment, 'left')));
        $height = max(1, max(array_column($segment, 'bottom')) - min(array_column($segment, 'top')));

        return ($digits * 100) + ($width / $height) - abs($angle);
    }

    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($v) => is_numeric($v)));
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
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
        $bestHighlightByEan = [];
        $palletEans = array_keys($palletEanMap);
        $detections = $ocrData['eans'] ?? [];

        // PaddleOCR puede leer mal 1-2 dígitos y romper checksum EAN-13.
        // Agregamos ventanas de 13 dígitos "crudas" desde lines para poder
        // reconciliarlas luego contra los EANs reales del pallet.
        foreach (($ocrData['lines'] ?? []) as $line) {
            $text = (string) ($line['text'] ?? '');
            $digits = preg_replace('/\D+/', '', $text);
            if (strlen($digits) < self::EAN_LENGTH) {
                continue;
            }

            $bbox = $line['bbox'] ?? null;
            if (! is_array($bbox)
                || ! isset($bbox['left'], $bbox['top'], $bbox['right'], $bbox['bottom'])
                || $bbox['right'] <= $bbox['left']
                || $bbox['bottom'] <= $bbox['top']) {
                continue;
            }

            foreach (self::allEanWindows($digits) as $candidate) {
                $detections[] = [
                    'ean' => $candidate,
                    'bbox' => [
                        'left' => (int) $bbox['left'],
                        'top' => (int) $bbox['top'],
                        'right' => (int) $bbox['right'],
                        'bottom' => (int) $bbox['bottom'],
                    ],
                    'source' => 'line_raw',
                    'text' => $text,
                ];
            }
        }

        // Matching guiado por EAN esperado del pedido/pallet:
        // intenta encontrar cada EAN objetivo dentro de líneas OCR aunque
        // falte/sobre algún dígito (Levenshtein sobre ventanas cercanas).
        $guidedDetections = self::findGuidedExpectedEanDetections(
            $ocrData['lines'] ?? [],
            $palletEans
        );
        foreach ($guidedDetections as $guided) {
            $detections[] = $guided;
        }

        $detections = self::deduplicateDetections($detections);

        foreach ($detections as $detected) {
            $detectedEan = (string) ($detected['ean'] ?? '');
            if ($detectedEan === '') {
                continue;
            }

            $ean = self::resolveDetectedEan($detectedEan, $palletEans);

            if (! isset($palletEanMap[$ean])) {
                continue; // EAN no está en el pallet → no resaltar
            }

            $palletInfo  = $palletEanMap[$ean];
            $eanHeight   = max(1, $detected['bbox']['bottom'] - $detected['bbox']['top']);
            $eanWidth    = max(1, $detected['bbox']['right'] - $detected['bbox']['left']);

            // Overlay más chico y centrado en la línea detectada para evitar
            // cuadros "gigantes" cuando el ticket es compacto.
            $verticalPad = max(2, (int) round($eanHeight * 0.35));
            $horizontalPad = max(2, (int) round(min(10, $eanWidth * 0.08)));
            $expandedTop = max(0, $detected['bbox']['top'] - $verticalPad);
            $expandedBottom = min($ocrData['img_h'], $detected['bbox']['bottom'] + $verticalPad);

            $highlight = [
                'ean'          => $ean,
                'detected_ean' => $detectedEan,
                'description'  => $palletInfo['description'],
                'qty_in_pallet'=> $palletInfo['total_qty'],
                'orders'       => $palletInfo['orders'],
                'img_w'        => $ocrData['img_w'],
                'img_h'        => $ocrData['img_h'],
                // Bbox original del EAN (para tooltip)
                'ean_bbox'     => $detected['bbox'],
                // Bbox expandido para el overlay visual
                'bbox'         => [
                    'left'   => max(0, $detected['bbox']['left'] - $horizontalPad),
                    'top'    => $expandedTop,
                    'right'  => min($ocrData['img_w'], $detected['bbox']['right'] + $horizontalPad),
                    'bottom' => $expandedBottom,
                ],
            ];

            $score = self::highlightScore($detected, $detectedEan, $ean);
            $current = $bestHighlightByEan[$ean] ?? null;
            if ($current === null || $score > $current['score']) {
                $bestHighlightByEan[$ean] = [
                    'score' => $score,
                    'data' => $highlight,
                ];
            }
        }

        return array_values(array_map(
            fn ($row) => $row['data'],
            $bestHighlightByEan
        ));
    }

    private static function highlightScore(array $detected, string $detectedEan, string $resolvedEan): int
    {
        $source = (string) ($detected['source'] ?? '');
        $sourceWeight = match ($source) {
            'line' => 5000,
            'tokens' => 4500,
            'paddle_line' => 4000,
            'line_guided_expected' => 3500,
            'line_raw' => 3000,
            default => 2000,
        };

        $exactMatchBonus = ($detectedEan === $resolvedEan) ? 1000 : 0;
        $editDistancePenalty = (int) ($detected['edit_distance'] ?? 0) * 120;
        $confidence = (float) ($detected['confidence'] ?? 0);
        $confidenceScore = (int) round($confidence * 100);

        return $sourceWeight + $exactMatchBonus + $confidenceScore - $editDistancePenalty;
    }

    private static function allEanWindows(string $digits): array
    {
        $digits = preg_replace('/\D+/', '', $digits);
        $len = strlen($digits);
        $windows = [];

        for ($i = 0; $i <= $len - self::EAN_LENGTH; $i++) {
            $candidate = substr($digits, $i, self::EAN_LENGTH);
            if (strlen($candidate) === self::EAN_LENGTH) {
                $windows[$candidate] = $candidate;
            }
        }

        return array_values($windows);
    }

    private static function deduplicateDetections(array $detections): array
    {
        $seen = [];
        $result = [];

        foreach ($detections as $detection) {
            $ean = (string) ($detection['ean'] ?? '');
            $bbox = $detection['bbox'] ?? null;
            if ($ean === '' || ! is_array($bbox)) {
                continue;
            }

            $leftBucket = (int) floor(((int) ($bbox['left'] ?? 0)) / 20);
            $topBucket = (int) floor(((int) ($bbox['top'] ?? 0)) / 20);
            $key = $ean . '|' . $leftBucket . '|' . $topBucket;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $detection;
        }

        return $result;
    }

    private static function findGuidedExpectedEanDetections(array $lines, array $expectedEans): array
    {
        $bestByExpected = [];

        foreach ($expectedEans as $expected) {
            if (strlen($expected) !== self::EAN_LENGTH || ! ctype_digit($expected)) {
                continue;
            }
            $bestByExpected[$expected] = null;
        }

        foreach ($lines as $line) {
            $text = (string) ($line['text'] ?? '');
            $digits = preg_replace('/\D+/', '', $text);
            if (strlen($digits) < 8) {
                continue;
            }

            $bbox = $line['bbox'] ?? null;
            if (! is_array($bbox)
                || ! isset($bbox['left'], $bbox['top'], $bbox['right'], $bbox['bottom'])
                || $bbox['right'] <= $bbox['left']
                || $bbox['bottom'] <= $bbox['top']) {
                continue;
            }

            foreach (array_keys($bestByExpected) as $expected) {
                // Prefiltro: compartir prefijo de 3 dígitos reduce ruido.
                if (substr($digits, 0, 1) !== substr($expected, 0, 1) && ! str_contains($digits, substr($expected, 0, 3))) {
                    continue;
                }

                $bestWindow = self::bestLevenshteinWindow($expected, $digits);
                if ($bestWindow === null) {
                    continue;
                }

                // Umbral conservador para evitar falsos positivos.
                if ($bestWindow['distance'] > 2) {
                    continue;
                }

                $currentBest = $bestByExpected[$expected];
                if ($currentBest === null || $bestWindow['distance'] < $currentBest['distance']) {
                    $bestByExpected[$expected] = [
                        'distance' => $bestWindow['distance'],
                        'detection' => [
                            'ean' => $expected,
                            'bbox' => [
                                'left' => (int) $bbox['left'],
                                'top' => (int) $bbox['top'],
                                'right' => (int) $bbox['right'],
                                'bottom' => (int) $bbox['bottom'],
                            ],
                            'source' => 'line_guided_expected',
                            'text' => $text,
                            'matched_window' => $bestWindow['window'],
                            'edit_distance' => $bestWindow['distance'],
                        ],
                    ];
                }
            }
        }

        $result = [];
        foreach ($bestByExpected as $expected => $best) {
            if ($best !== null) {
                $result[] = $best['detection'];
            }
        }

        return $result;
    }

    private static function bestLevenshteinWindow(string $expected, string $digits): ?array
    {
        $len = strlen($digits);
        if ($len < 8) {
            return null;
        }

        $bestDistance = PHP_INT_MAX;
        $bestWindow = null;

        // Ventanas cercanas al largo EAN-13 para tolerar drop/extra dígitos.
        for ($windowLen = 11; $windowLen <= 15; $windowLen++) {
            if ($len < $windowLen) {
                continue;
            }

            for ($i = 0; $i <= $len - $windowLen; $i++) {
                $window = substr($digits, $i, $windowLen);
                $distance = levenshtein($expected, $window);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestWindow = $window;
                }
            }
        }

        if ($bestWindow === null) {
            return null;
        }

        return [
            'window' => $bestWindow,
            'distance' => $bestDistance,
        ];
    }

    /**
     * Corrige pequeños errores OCR contra los EANs reales del pallet.
     * Estrategia:
     * 1) match exacto;
     * 2) fallback por distancia de Hamming <= 1 (solo 13 dígitos);
     * 3) exige candidato único para evitar falsos positivos.
     */
    private static function resolveDetectedEan(string $detectedEan, array $palletEans): string
    {
        if (in_array($detectedEan, $palletEans, true)) {
            return $detectedEan;
        }

        if (strlen($detectedEan) !== self::EAN_LENGTH || ! ctype_digit($detectedEan)) {
            return $detectedEan;
        }

        $best = self::pickBestUniqueCandidate($detectedEan, $palletEans, 1, null);
        if ($best !== null) {
            return $best;
        }

        // Fallback controlado: permite hasta 2 diferencias, pero con prefijos
        // estrictos para evitar matches falsos.
        // Caso típico: OCR confunde 1-2 dígitos de la mitad/final del código.
        $best = self::pickBestUniqueCandidate(
            $detectedEan,
            $palletEans,
            2,
            static function (string $detected, string $candidate): bool {
                // Mantener prefijo país/empresa (ej: 779...).
                if (substr($detected, 0, 3) !== substr($candidate, 0, 3)) {
                    return false;
                }

                // Al menos 7 primeros dígitos iguales.
                if (substr($detected, 0, 7) !== substr($candidate, 0, 7)) {
                    return false;
                }

                return true;
            }
        );
        if ($best !== null) {
            return $best;
        }

        // Debug cuando no hubo match: loguear los vecinos más cercanos.
        $nearest = [];
        foreach ($palletEans as $candidate) {
            if (strlen($candidate) !== self::EAN_LENGTH || ! ctype_digit($candidate)) {
                continue;
            }
            $nearest[] = [
                'candidate' => $candidate,
                'distance' => self::hammingDistance($detectedEan, $candidate),
            ];
        }
        usort($nearest, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        if ($nearest !== [] && config('app.debug')) {
            Log::info('TicketOcrService: detected EAN without pallet match', [
                'detected_ean' => $detectedEan,
                'nearest' => array_slice($nearest, 0, 5),
            ]);
        }

        return $detectedEan;
    }

    private static function pickBestUniqueCandidate(
        string $detectedEan,
        array $palletEans,
        int $maxDistance,
        ?callable $extraFilter
    ): ?string {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $isAmbiguous = false;

        foreach ($palletEans as $candidate) {
            if (strlen($candidate) !== self::EAN_LENGTH || ! ctype_digit($candidate)) {
                continue;
            }

            if ($extraFilter && ! $extraFilter($detectedEan, $candidate)) {
                continue;
            }

            $distance = self::hammingDistance($detectedEan, $candidate);
            if ($distance > $maxDistance) {
                continue;
            }

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
                $isAmbiguous = false;
                continue;
            }

            if ($distance === $bestDistance && $candidate !== $best) {
                $isAmbiguous = true;
            }
        }

        if ($best !== null && ! $isAmbiguous) {
            return $best;
        }

        return null;
    }

    private static function hammingDistance(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return PHP_INT_MAX;
        }

        $distance = 0;
        $len = strlen($a);
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                $distance++;
            }
        }

        return $distance;
    }
}
