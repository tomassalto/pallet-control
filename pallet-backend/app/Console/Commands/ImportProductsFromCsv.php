<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;

class ImportProductsFromCsv extends Command
{
    protected $signature = 'products:import-csv {file : Ruta dentro de storage/app}';
    protected $description = 'Importa productos desde CSV (EAN + descripción) con upsert';

    public function handle(): int
    {
        $path = storage_path('app/' . $this->argument('file'));

        if (!file_exists($path)) {
            $this->error("Archivo no encontrado: $path");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            $this->error("No se pudo abrir el archivo.");
            return self::FAILURE;
        }

        $batch = [];
        $count = 0;



        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            // Si tu CSV usa coma en vez de ;, cambiá el separador
            // fgetcsv($handle, 0, ',');

            $eanRaw  = $row[0] ?? null;
            $descRaw = $row[1] ?? null;

            $ean  = preg_replace('/\D+/', '', (string) $eanRaw);
            $name = trim((string) $descRaw);

            if ($ean === '' || $name === '') continue;

            $last4 = strlen($ean) >= 4 ? substr($ean, -4) : null;

            $batch[] = [
                'ean' => $ean,
                'name' => $name,
                'ean_last4' => $last4,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= 1000) {
                Product::upsert($batch, ['ean'], ['name', 'ean_last4', 'updated_at']);
                $count += count($batch);
                $batch = [];
                $this->info("Importados: $count");
            }
        }

        fclose($handle);

        if ($batch) {
            Product::upsert($batch, ['ean'], ['name', 'ean_last4', 'updated_at']);
            $count += count($batch);
        }

        $this->info("✅ Listo. Total importados/actualizados: $count");
        return self::SUCCESS;
    }
}
