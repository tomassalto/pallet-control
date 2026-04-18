<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;

class ImportCustomersFromCsv extends Command
{
    protected $signature = 'customers:import-csv {file : Ruta dentro de storage/app}';
    protected $description = 'Importa clientes desde CSV (nombre + quit) con upsert';

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
            // Formato esperado: nombre,quit
            $nameRaw = $row[0] ?? null;
            $quitRaw = $row[1] ?? null;

            $name = trim((string) $nameRaw);
            $quit = trim((string) $quitRaw);

            if ($name === '' || $quit === '') continue;

            $batch[] = [
                'name' => $name,
                'quit' => $quit,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= 1000) {
                Customer::upsert($batch, ['quit'], ['name', 'updated_at']);
                $count += count($batch);
                $batch = [];
                $this->info("Importados: $count");
            }
        }

        fclose($handle);

        if ($batch) {
            Customer::upsert($batch, ['quit'], ['name', 'updated_at']);
            $count += count($batch);
        }

        $this->info("✅ Listo. Total importados/actualizados: $count");
        return self::SUCCESS;
    }
}
