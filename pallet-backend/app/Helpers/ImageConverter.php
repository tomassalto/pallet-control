<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageConverter
{
    public static function convertToWebP(
        UploadedFile $file,
        string $dir,
        int $quality = 85,
        ?int $maxWidth = null,
        ?int $maxHeight = null
    ): string {
        [$tmpPath, $useTempFile] = self::resolveReadablePath($file);

        try {
            $manager = new ImageManager(new Driver());

            $image = $manager->read($tmpPath);

            // ✅ CLAVE: reasignar (evita “no hace nada” en algunos entornos)
            if (method_exists($image, 'orient')) {
                $image = $image->orient();
            }

            // Escala manteniendo ratio (si alguno es null, no limita ese eje)
            if ($maxWidth || $maxHeight) {
                $w = $maxWidth ?? 10000;
                $h = $maxHeight ?? 10000;
                $image = $image->scaleDown($w, $h);
            }

            $filename = Str::uuid() . '.webp';
            $path = trim($dir, '/') . '/' . $filename;

            $webpContent = $image->toWebp($quality)->toString();
            Storage::disk('public')->put($path, $webpContent);

            return $path;
        } finally {
            if ($useTempFile && is_string($tmpPath) && file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private static function resolveReadablePath(UploadedFile $file): array
    {
        $tmpPath = $file->getPathname();
        if (!$tmpPath || !is_file($tmpPath) || !is_readable($tmpPath)) {
            $tmpPath = $file->getRealPath();
        }

        if (!$tmpPath || !is_file($tmpPath) || !is_readable($tmpPath)) {
            $content = null;

            try {
                $stream = $file->getStream();
                if ($stream && is_resource($stream)) {
                    $content = stream_get_contents($stream);
                    $meta = stream_get_meta_data($stream);
                    if (!empty($meta['seekable'])) rewind($stream);
                }
            } catch (\Throwable $e) {
            }

            if (!$content) {
                throw new \RuntimeException('No se pudo leer el archivo subido.');
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'upload_');
            if ($tempFile === false) {
                throw new \RuntimeException('No se pudo crear un archivo temporal.');
            }

            if (!file_put_contents($tempFile, $content)) {
                @unlink($tempFile);
                throw new \RuntimeException('No se pudo escribir el archivo temporal.');
            }

            return [$tempFile, true];
        }

        return [$tmpPath, false];
    }
}
