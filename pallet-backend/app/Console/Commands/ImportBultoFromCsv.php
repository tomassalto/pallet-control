<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class ImportBultoFromCsv extends Command
{
    protected $signature = 'app:import-bulto
                            {file=imports/uxbulto.csv : Ruta dentro de storage/app}';

    protected $description = 'Importa units_per_bulto desde CSV semicolon-separated (EAN;DESCRIPCION;UM BULTO)';

    public function handle(): int
    {
        $path = storage_path('app/' . $this->argument('file'));

        if (! file_exists($path)) {
            $this->error("Archivo no encontrado: $path");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            $this->error("No se pudo abrir el archivo.");
            return self::FAILURE;
        }

        // Saltar encabezado
        fgetcsv($handle, 0, ';');

        $matched  = 0;
        $notFound = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $eanRaw = $row[0] ?? null;
            $bulto  = isset($row[2]) ? (int) trim($row[2]) : null;

            // Normalizar EAN: solo dígitos
            $ean = preg_replace('/\D+/', '', (string) $eanRaw);

            if ($ean === '' || ! $bulto || $bulto <= 0) {
                $skipped++;
                continue;
            }

            $updated = Product::where('ean', $ean)
                ->update(['units_per_bulto' => $bulto]);

            if ($updated > 0) {
                $matched++;
            } else {
                $notFound++;
                if ($this->getOutput()->isVerbose()) {
                    $desc = trim($row[1] ?? '');
                    $this->line("  Sin coincidencia: EAN={$ean}  {$desc}");
                }
            }
        }

        fclose($handle);

        $this->info("✅ Actualizados:             {$matched}");
        $this->warn("⚠️  Sin coincidencia en DB:  {$notFound}");

        if ($skipped > 0) {
            $this->line("   Saltados (datos vacíos):  {$skipped}");
        }

        return self::SUCCESS;
    }
}
