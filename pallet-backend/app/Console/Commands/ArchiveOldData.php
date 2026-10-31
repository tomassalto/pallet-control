<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ArchiveOldData extends Command
{
    protected $signature = 'pallet:archive
                            {--dry-run : Solo muestra el inventario, no modifica nada}';

    protected $description = 'Inventario de solo lectura de los datos que el archivado legado afectaría. El modo destructivo está bloqueado hasta resolver GAP-RETENTION (H-048).';

    public function handle(): int
    {
        $corteOcr = now()->subMonths(3);
        $corteFotosYLogs = now()->subMonths(6);

        $this->info('Inventario de solo lectura con el criterio legado: orders.status = done + orders.updated_at.');
        $this->warn('Ojo: orders.updated_at no es una fecha de finalización; cualquier actualización la mueve.');
        $this->line('Corte OCR (3 meses): ' . $corteOcr->toDateString());
        $this->line('Corte fotos/logs (6 meses): ' . $corteFotosYLogs->toDateString());
        $this->line('');

        $filtroOrdenVieja = function ($q) use ($corteFotosYLogs) {
            $q->where('status', 'done')
                ->where('updated_at', '<', $corteFotosYLogs);
        };

        $conteos = [
            'ocr_logs' => OrderTicketPhoto::whereNotNull('ocr_log')
                ->whereHas('ticket.order', function ($q) use ($corteOcr) {
                    $q->where('status', 'done')
                        ->where('updated_at', '<', $corteOcr);
                })
                ->count(),
            'ordenes' => Order::where('status', 'done')
                ->where('updated_at', '<', $corteFotosYLogs)
                ->count(),
            'fotos' => OrderTicketPhoto::whereHas('ticket.order', $filtroOrdenVieja)
                ->count(),
            'archivos' => OrderTicketPhoto::whereHas('ticket.order', $filtroOrdenVieja)
                ->whereNotNull('path')
                ->where('path', '!=', '')
                ->count(),
            'tickets_vaciados' => OrderTicket::whereHas('order', $filtroOrdenVieja)
                ->whereHas('photos')
                ->count(),
            'activity_logs' => ActivityLog::where('created_at', '<', $corteFotosYLogs)->count(),
        ];

        $this->table(['Dato', 'Criterio legado', 'Cantidad'], [
            ['ocr_log a limpiar', 'fotos de orders done con updated_at < -3 meses', $conteos['ocr_logs']],
            ['orders finalizadas', 'status = done con updated_at < -6 meses', $conteos['ordenes']],
            ['fotos de tickets a borrar', 'fotos de tickets de esas orders', $conteos['fotos']],
            ['objetos de storage a borrar', 'fotos de esas orders con path no vacío', $conteos['archivos']],
            ['tickets que quedarían sin fotos', 'tickets de esas orders con al menos 1 foto', $conteos['tickets_vaciados']],
            ['activity_logs a purgar', 'created_at < -6 meses', $conteos['activity_logs']],
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry-run: no se modificó nada.');

            return self::SUCCESS;
        }

        $this->error('Modo destructivo deshabilitado hasta resolver GAP-RETENTION (H-048). No se modificó nada.');
        Log::warning('pallet:archive bloqueado: modo destructivo deshabilitado hasta GAP-RETENTION', $conteos);

        return self::FAILURE;
    }
}
