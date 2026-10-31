<?php

namespace Tests\Feature\Console;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\PendingFileDeletion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveOldDataTest extends TestCase
{
    use RefreshDatabase;

    private string $pathViejo = 'tickets/viejo.webp';

    private string $pathReciente = 'tickets/reciente.webp';

    private Order $ordenVieja;

    private Order $ordenReciente;

    private Order $ordenAbiertaVieja;

    private OrderTicketPhoto $fotoVieja;

    private OrderTicketPhoto $fotoReciente;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        // Una order "done" vieja (7 meses), una "done" reciente (1 mes) y una
        // abierta vieja: el criterio legado solo alcanza a la primera.
        $this->ordenVieja = Order::factory()->done()->create();
        $this->ordenReciente = Order::factory()->done()->create();
        $this->ordenAbiertaVieja = Order::factory()->create();

        $ticketViejo = OrderTicket::create(['order_id' => $this->ordenVieja->id, 'code' => 'T-V']);
        $ticketReciente = OrderTicket::create(['order_id' => $this->ordenReciente->id, 'code' => 'T-R']);

        Storage::disk('public')->put($this->pathViejo, 'bytes-viejos');
        $this->fotoVieja = OrderTicketPhoto::create([
            'ticket_id' => $ticketViejo->id,
            'path' => $this->pathViejo,
            'original_name' => 'viejo.webp',
            'ocr_log' => 'log ocr viejo',
        ]);

        Storage::disk('public')->put($this->pathReciente, 'bytes-recientes');
        $this->fotoReciente = OrderTicketPhoto::create([
            'ticket_id' => $ticketReciente->id,
            'path' => $this->pathReciente,
            'original_name' => 'reciente.webp',
            'ocr_log' => 'log ocr reciente',
        ]);

        $logViejo = ActivityLog::create([
            'action' => 'order_assigned',
            'entity_type' => 'order',
            'entity_id' => $this->ordenVieja->id,
            'description' => 'log viejo',
        ]);
        $logViejo->created_at = now()->subMonths(7);
        $logViejo->save();

        ActivityLog::create([
            'action' => 'order_assigned',
            'entity_type' => 'order',
            'entity_id' => $this->ordenReciente->id,
            'description' => 'log reciente',
        ]);

        $this->ordenVieja->updated_at = now()->subMonths(7);
        $this->ordenVieja->save();
        $this->ordenReciente->updated_at = now()->subMonth();
        $this->ordenReciente->save();
        $this->ordenAbiertaVieja->updated_at = now()->subMonths(7);
        $this->ordenAbiertaVieja->save();
    }

    public function test_sin_confirmacion_no_modifica_nada(): void
    {
        Queue::fake();

        $this->artisan('pallet:archive')
            ->expectsOutputToContain('GAP-RETENTION')
            ->assertFailed();

        $this->assertDatosIntactos();
        $this->assertSame(0, PendingFileDeletion::count());
        $this->assertSame(2, ActivityLog::count());
        Queue::assertNothingPushed();
    }

    public function test_dry_run_no_modifica_y_reporta_conteos(): void
    {
        Queue::fake();

        $this->artisan('pallet:archive', ['--dry-run' => true])
            ->expectsOutputToContain('orders.updated_at no es una fecha de finalización')
            ->expectsTable(
                ['Dato', 'Criterio legado', 'Cantidad'],
                [
                    ['ocr_log a limpiar', 'fotos de orders done con updated_at < -3 meses', 1],
                    ['orders finalizadas', 'status = done con updated_at < -6 meses', 1],
                    ['fotos de tickets a borrar', 'fotos de tickets de esas orders', 1],
                    ['objetos de storage a borrar', 'fotos de esas orders con path no vacío', 1],
                    ['tickets que quedarían sin fotos', 'tickets de esas orders con al menos 1 foto', 1],
                    ['activity_logs a purgar', 'created_at < -6 meses', 1],
                ],
            )
            ->expectsOutputToContain('Dry-run: no se modificó nada.')
            ->assertSuccessful();

        $this->assertDatosIntactos();
        $this->assertSame(0, PendingFileDeletion::count());
        Queue::assertNothingPushed();
    }

    public function test_datos_fuera_del_cutoff_permanecen_y_logs_historicos_permanecen(): void
    {
        $this->artisan('pallet:archive')->assertFailed();
        $this->artisan('pallet:archive', ['--dry-run' => true])->assertSuccessful();

        // La order reciente y la abierta vieja quedan fuera del criterio.
        $this->assertNotNull($this->ordenReciente->fresh());
        $this->assertNotNull($this->ordenAbiertaVieja->fresh());
        $this->assertSame('log ocr reciente', $this->fotoReciente->fresh()->ocr_log);
        Storage::disk('public')->assertExists($this->pathReciente);

        // Los datos "viejos" tampoco se tocan: el comando es solo lectura.
        $this->assertSame('log ocr viejo', $this->fotoVieja->fresh()->ocr_log);
        Storage::disk('public')->assertExists($this->pathViejo);
        $this->assertSame(2, ActivityLog::count());
        $this->assertNotNull(ActivityLog::where('description', 'log viejo')->first());
    }

    public function test_comando_repetido_es_seguro(): void
    {
        $antes = $this->snapshot();

        $this->artisan('pallet:archive')->assertFailed();
        $this->artisan('pallet:archive', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('pallet:archive')->assertFailed();
        $this->artisan('pallet:archive', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($antes, $this->snapshot());
        Storage::disk('public')->assertExists($this->pathViejo);
        Storage::disk('public')->assertExists($this->pathReciente);
    }

    public function test_pallet_archive_no_esta_programado(): void
    {
        Artisan::call('schedule:list');
        $this->assertStringNotContainsString('pallet:archive', Artisan::output());

        foreach (app(Schedule::class)->events() as $evento) {
            $this->assertStringNotContainsString('pallet:archive', $evento->command ?? '');
        }
    }

    public function test_runtimes_versionados_conocidos_no_invocan_scheduler_ni_archive(): void
    {
        // Guardia sobre los archivos de runtime versionados conocidos; no es una
        // garantía sobre crons externos ni configuración de plataforma (p. ej.
        // el dashboard de Render).
        $raiz = dirname(base_path());

        foreach (['supervisord.conf', 'docker-entrypoint.sh', 'nixpacks.toml', 'Dockerfile'] as $archivo) {
            $contenido = file_get_contents($raiz . DIRECTORY_SEPARATOR . $archivo);
            $this->assertIsString($contenido);
            $this->assertStringNotContainsString('schedule:', $contenido, "runtime versionado $archivo referencia al scheduler");
            $this->assertStringNotContainsString('pallet:archive', $contenido, "runtime versionado $archivo referencia a pallet:archive");
        }
    }

    public function test_ninguna_variable_de_entorno_habilita_el_borrado(): void
    {
        $fuente = file_get_contents(app_path('Console/Commands/ArchiveOldData.php'));
        $this->assertIsString($fuente);

        foreach (['->delete(', '->update(', '->pluck(', 'Storage::', 'env('] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $fuente, "El comando no debe contener $prohibido");
        }
    }

    private function assertDatosIntactos(): void
    {
        $this->assertSame(3, Order::count());
        $this->assertSame(2, OrderTicket::count());
        $this->assertSame(2, OrderTicketPhoto::count());
        $this->assertSame('log ocr viejo', $this->fotoVieja->fresh()->ocr_log);
        $this->assertSame('log ocr reciente', $this->fotoReciente->fresh()->ocr_log);
        Storage::disk('public')->assertExists($this->pathViejo);
        Storage::disk('public')->assertExists($this->pathReciente);
        $this->assertSame(2, ActivityLog::count());
    }

    private function snapshot(): array
    {
        return [
            'orders' => Order::count(),
            'order_tickets' => OrderTicket::count(),
            'order_ticket_photos' => OrderTicketPhoto::count(),
            'activity_logs' => ActivityLog::count(),
            'pending_file_deletions' => PendingFileDeletion::count(),
        ];
    }
}
