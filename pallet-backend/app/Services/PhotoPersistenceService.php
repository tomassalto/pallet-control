<?php

namespace App\Services;

use App\Helpers\ImageConverter;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PhotoPersistenceService
{
    /**
     * Guarda el archivo antes de abrir la transacción y lo elimina si falla
     * cualquier escritura relacionada en la base de datos.
     */
    public function persist(
        UploadedFile $file,
        string $directory,
        int $quality,
        ?int $maxWidth,
        ?int $maxHeight,
        Closure $persistDatabaseChanges,
    ): mixed {
        $path = ImageConverter::convertToWebP(
            $file,
            $directory,
            $quality,
            $maxWidth,
            $maxHeight,
        );

        try {
            return DB::transaction(
                fn (): mixed => $persistDatabaseChanges($path)
            );
        } catch (Throwable $exception) {
            $this->compensateStoredFile($path, $exception);

            throw $exception;
        }
    }

    private function compensateStoredFile(string $path, Throwable $originalException): void
    {
        $diskName = config('filesystems.default', 'public');

        try {
            $disk = Storage::disk($diskName);
            $deleted = $disk->delete($path);

            if (! $deleted && $disk->exists($path)) {
                Log::critical('No se pudo compensar una foto sin registro en la base de datos.', [
                    'disk' => $diskName,
                    'path' => $path,
                    'original_exception' => $originalException::class,
                ]);
            }
        } catch (Throwable $cleanupException) {
            Log::critical('Falló la compensación de una foto sin registro en la base de datos.', [
                'disk' => $diskName,
                'path' => $path,
                'original_exception' => $originalException::class,
                'cleanup_exception' => $cleanupException::class,
                'cleanup_message' => $cleanupException->getMessage(),
            ]);
        }
    }
}
