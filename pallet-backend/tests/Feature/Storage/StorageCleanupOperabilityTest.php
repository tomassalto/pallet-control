<?php

namespace Tests\Feature\Storage;

use App\Jobs\DeleteStoredFile;
use App\Jobs\ProcessTicketOcr;
use App\Models\Pallet;
use App\Models\PalletPhoto;
use App\Models\PendingFileDeletion;
use App\Models\User;
use App\Services\StorageCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class StorageCleanupOperabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    // ── Consistencia cola ↔ worker supervisado ───────────────────────────

    public function test_retry_after_supera_al_timeout_del_worker_y_de_cada_job(): void
    {
        $conf = file_get_contents(dirname(base_path()) . DIRECTORY_SEPARATOR . 'supervisord.conf');
        $this->assertIsString($conf);

        // El worker corre con --timeout=N en supervisord.conf.
        $this->assertSame(
            1,
            preg_match('/queue:work .*--timeout=(\d+)/', $conf, $m),
            'No se encontró --timeout en el comando del worker de supervisord.conf',
        );
        $workerTimeout = (int) $m[1];

        $retryAfter = (int) config('queue.connections.database.retry_after');

        // Un trabajo reservado no debe relanzarse mientras sigue corriendo.
        $this->assertGreaterThan($workerTimeout, $retryAfter);
        $this->assertLessThan($retryAfter, (new DeleteStoredFile(1))->timeout);
        $this->assertLessThan($workerTimeout, (new DeleteStoredFile(1))->timeout);
        $this->assertLessThan($retryAfter, (new ProcessTicketOcr(1))->timeout);
        $this->assertLessThan($workerTimeout, (new ProcessTicketOcr(1))->timeout);

        // Comportamiento de reintento explícito en ambos jobs.
        $this->assertSame(3, (new DeleteStoredFile(1))->tries);
        $this->assertSame([30, 120], (new DeleteStoredFile(1))->backoff);
        $this->assertSame(2, (new ProcessTicketOcr(1))->tries);
        $this->assertSame([30], (new ProcessTicketOcr(1))->backoff);
    }

    // ── Reintento y recuperación ─────────────────────────────────────────

    public function test_reintento_tras_fallo_de_storage_completa_la_intencion(): void
    {
        $path = 'pallets/1/a.webp';
        Storage::disk('public')->put($path, 'bytes');
        $intent = $this->intencion($path);

        $discoReal = Storage::disk('public');
        $doble = Mockery::mock($discoReal)->makePartial();
        $doble->shouldReceive('delete')->andThrow(new RuntimeException('storage caído'));
        Storage::set('public', $doble);

        try {
            app(StorageCleanupService::class)->process($intent->id);
            $this->fail('process debía lanzar con storage caído.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $intent->fresh()->attempts);
        $this->assertNull($intent->fresh()->completed_at);

        Storage::set('public', $discoReal);
        $this->assertTrue(app(StorageCleanupService::class)->process($intent->id));
        $this->assertNotNull($intent->fresh()->completed_at);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_job_duplicado_es_idempotente_y_borra_una_sola_vez(): void
    {
        $path = 'pallets/1/dup.webp';
        Storage::disk('public')->put($path, 'bytes');
        $intent = $this->intencion($path);

        $borrados = 0;
        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andReturnUsing(function () use (&$borrados) {
            $borrados++;

            return true;
        });
        Storage::set('public', $doble);

        // La cola pudo entregar el mismo job dos veces (retry_after,
        // redispatch manual): el segundo es un no-op que ni toca storage.
        DeleteStoredFile::dispatchSync($intent->id);
        DeleteStoredFile::dispatchSync($intent->id);

        $this->assertSame(1, $borrados);
        $this->assertNotNull($intent->fresh()->completed_at);
    }

    public function test_intencion_ya_completada_no_llama_a_storage(): void
    {
        $intent = PendingFileDeletion::create([
            'disk' => 'public',
            'path' => 'pallets/1/hecha.webp',
            'reason' => 'pallet_photo_deleted',
            'available_at' => now(),
            'completed_at' => now(),
        ]);

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldNotReceive('delete');
        $doble->shouldNotReceive('exists');
        Storage::set('public', $doble);

        $this->assertTrue(app(StorageCleanupService::class)->process($intent->id));
    }

    public function test_crash_antes_del_worker_lo_recupera_el_barrido(): void
    {
        Queue::fake(); // el job nunca corre: simula crash entre commit y dispatch

        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $photo = PalletPhoto::create([
            'pallet_id' => $pallet->id,
            'path' => "pallets/{$pallet->id}/a.webp",
            'original_name' => 'a.webp',
        ]);
        Storage::disk('public')->put($photo->path, 'bytes');

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertOk();

        $intent = PendingFileDeletion::sole();
        $this->assertNull($intent->completed_at);
        Storage::disk('public')->assertExists($photo->path);

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $this->assertNotNull($intent->fresh()->completed_at);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_crash_tras_borrar_antes_de_marcar_lo_recupera_el_barrido(): void
    {
        // El objeto ya no existe pero la intención quedó pendiente
        // (crash entre delete() y el save de completed_at).
        $intent = $this->intencion('pallets/1/ya-borrado.webp');

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $this->assertNotNull($intent->fresh()->completed_at);
    }

    // ── Saneamiento de errores ───────────────────────────────────────────

    public function test_excepcion_de_storage_no_filtra_mensaje_ni_previa(): void
    {
        $path = 'pallets/1/archivo.webp';
        Storage::disk('public')->put($path, 'bytes');
        $intent = $this->intencion($path);

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andThrow(
            new RuntimeException('https://r2.example/x?X-Amz-Signature=secreto'),
        );
        Storage::set('public', $doble);

        Log::spy();

        try {
            app(StorageCleanupService::class)->process($intent->id);
            $this->fail('process debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('secreto', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringContainsString(RuntimeException::class, $e->getMessage());
            $this->assertStringContainsString((string) $intent->id, $e->getMessage());
        }

        // Ningún log lleva el mensaje crudo: sólo la clase del error.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ! str_contains($message . json_encode($context), 'secreto'),
        );
    }

    public function test_failed_de_ocr_no_registra_el_mensaje(): void
    {
        Log::spy();

        (new ProcessTicketOcr(42))->failed(new RuntimeException('secreto'));

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context = []) => ! str_contains($message . json_encode($context), 'secreto')
                && $context['photo_id'] === 42
                && $context['error'] === RuntimeException::class,
        );
    }

    // ── Fallo terminal auditable ─────────────────────────────────────────

    public function test_agotamiento_deja_auditoria_terminal_una_sola_vez(): void
    {
        Storage::disk('public')->put('pallets/1/max.webp', 'bytes');
        $intent = $this->intencion('pallets/1/max.webp', StorageCleanupService::MAX_ATTEMPTS - 1);

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andThrow(new RuntimeException('sigue caído'));
        Storage::set('public', $doble);

        try {
            app(StorageCleanupService::class)->process($intent->id);
            $this->fail('process debía lanzar.');
        } catch (Throwable) {
        }

        $intent->refresh();
        $this->assertSame(StorageCleanupService::MAX_ATTEMPTS, $intent->attempts);

        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'file_deletion_exhausted',
            'entity_type' => 'pending_file_deletion',
            'entity_id' => $intent->id,
        ]);

        // Reprocesar la agotada no duplica la auditoría terminal; sí deja
        // constancia del reprocesamiento manual.
        $intent->forceFill(['available_at' => now()->subMinute()])->save();

        $this->artisan('storage:retry-pending-deletions', ['--include-exhausted' => true])
            ->assertExitCode(1); // volvió a fallar: failed > 0

        $this->assertSame(
            1,
            DB::table('activity_logs')->where('action', 'file_deletion_exhausted')->count(),
        );
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'file_deletion_exhausted_retried',
            'entity_type' => 'pending_file_deletion',
        ]);
    }

    public function test_fallo_de_auditoria_en_agotamiento_revierte_el_intento(): void
    {
        Storage::disk('public')->put('pallets/1/max.webp', 'bytes');
        $intent = $this->intencion('pallets/1/max.webp', StorageCleanupService::MAX_ATTEMPTS - 1);

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andThrow(new RuntimeException('sigue caído'));
        Storage::set('public', $doble);

        $this->failActivityLogs();

        try {
            app(StorageCleanupService::class)->process($intent->id);
            $this->fail('process debía lanzar.');
        } catch (Throwable) {
        }

        // El save del intento y el activity log viven en una misma
        // transacción: al fallar la auditoría, la intención no avanzó.
        $intent->refresh();
        $this->assertSame(StorageCleanupService::MAX_ATTEMPTS - 1, $intent->attempts);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'file_deletion_exhausted']);
    }

    // ── Comando de estado ────────────────────────────────────────────────

    public function test_cleanup_status_sin_intenciones_sale_ok(): void
    {
        $this->artisan('storage:cleanup-status')->assertExitCode(0);
    }

    public function test_cleanup_status_con_pendiente_fresco_sale_ok(): void
    {
        $this->intencion('pallets/1/fresco.webp');

        $this->artisan('storage:cleanup-status')->assertExitCode(0);
    }

    public function test_cleanup_status_con_pendiente_viejo_alerta(): void
    {
        $this->intencion('pallets/1/viejo.webp');

        $this->travel(61)->minutes();

        $this->artisan('storage:cleanup-status')->assertExitCode(1);
    }

    public function test_cleanup_status_con_agotada_alerta(): void
    {
        $this->intencion('pallets/1/agotada.webp', StorageCleanupService::MAX_ATTEMPTS);

        $this->artisan('storage:cleanup-status')->assertExitCode(1);
    }

    public function test_cleanup_status_cuenta_jobs_fallidos_de_borrado(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid-1',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\DeleteStoredFile', 'data' => []]),
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid-2',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessTicketOcr', 'data' => []]),
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        $status = app(StorageCleanupService::class)->status(60);

        $this->assertSame(1, $status['failed_jobs']);
        $this->assertFalse($status['alert']);

        // Un job fallido por sí solo no alerta: la intención es autoritativa.
        $this->artisan('storage:cleanup-status')->assertExitCode(0);
    }

    public function test_barrido_loguea_critico_con_pendiente_viejo(): void
    {
        // Pendiente vieja que el barrido no puede completar aún
        // (available_at futuro): señal de worker o barrido detenidos.
        $intent = PendingFileDeletion::create([
            'disk' => 'public',
            'path' => 'pallets/1/atascada.webp',
            'reason' => 'pallet_photo_deleted',
            'available_at' => now()->addMinutes(120),
        ]);

        $this->travel(61)->minutes();
        Log::spy();

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        Log::shouldHaveReceived('critical')->withArgs(
            fn (string $message, array $context = []) => ($context['stale_pending'] ?? 0) === 1
                && ($context['oldest_pending_minutes'] ?? 0) >= 61,
        );
        $this->assertNull($intent->fresh()->completed_at);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function intencion(string $path, int $attempts = 0): PendingFileDeletion
    {
        return PendingFileDeletion::create([
            'disk' => 'public',
            'path' => $path,
            'reason' => 'pallet_photo_deleted',
            'attempts' => $attempts,
            'available_at' => now(),
        ]);
    }

    private function failActivityLogs(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);
    }
}
