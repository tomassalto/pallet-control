<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdateProductLast4 extends Command
{
    protected $signature = 'products:update-last4';
    protected $description = 'Actualiza el campo ean_last4 para productos que no lo tienen';

    public function handle(): int
    {
        $this->info('Actualizando ean_last4 para productos existentes...');

        $total = Product::whereNull('ean_last4')->orWhere('ean_last4', '')->count();
        $this->info("Productos a actualizar: $total");

        $updated = 0;
        $batchSize = 1000;

        Product::whereNull('ean_last4')
            ->orWhere('ean_last4', '')
            ->chunk($batchSize, function ($products) use (&$updated, $total) {
                foreach ($products as $product) {
                    if (strlen($product->ean) >= 4) {
                        $product->ean_last4 = substr($product->ean, -4);
                        $product->save();
                        $updated++;
                    }
                }

                $this->info("Actualizados: $updated / $total");
            });

        $this->info("✅ Completado. Total actualizados: $updated");
        return self::SUCCESS;
    }
}
