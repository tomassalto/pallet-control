<?php

namespace Tests\Feature\Storage;

use App\Jobs\DeleteStoredFile;
use App\Models\PendingFileDeletion;
use App\Services\StorageCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StorageCleanupLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    // ── Reclamo exclusivo del lease ──────────────────────────────────────

    public function test_dos_workers_no_procesan_la_misma_intencion_a_la_vez(): void
    {
        $path = 'pallets/1/disputada.webp';
        Storage::disk('public')->put($path, 'bytes');
        $intent = $this->intencion($path);

        $service = app(StorageCleanupService::class);
        $borrados = 0;

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andReturnUsing(function () use (&$borrados, $service, $intent) {
            $borrados++;
            // Worker B intenta procesar la misma intención mientras A la
            // tiene reclamada: pierde el reclamo y no toca storage.
            $this->assertFalse($service->process($intent->id));

            return true;
        });
        Storage::set('public', $doble);

        $this->assertTrue($service->process($intent->id));

        $this->assertSame(1, $borrados);
        $intent->refresh();
        $this->assertNotNull($intent->completed_at);
        $this->assertSame(0, $intent->attempts);
        $this->assertNull($intent->claim_token);
        $this->assertNull($intent->claimed_until);
    }

    public function test_job_no_toca_storage_si_otro_worker_tiene_lease_activo(): void
    {
        Storage::disk('public')->put('pallets/1/reclamada.webp', 'bytes');
        $intent = $this->intencion('pallets/1/reclamada.webp');
        $intent->forceFill([
            'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->addSeconds(StorageCleanupService::LEASE_SECONDS),
        ])->save();

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldNotReceive('delete');
        $doble->shouldNotReceive('exists');
        Storage::set('public', $doble);

        DeleteStoredFile::dispatchSync($intent->id);

        $intent->refresh();
        $this->assertNull($intent->completed_at);
        $this->assertSame(0, $intent->attempts);
        $this->assertNotNull($intent->claim_token);
    }

    public function test_fallos_concurrentes_no_pierden_incrementos_de_attempts(): void
    {
        Storage::disk('public')->put('pallets/1/falla-doble.webp', 'bytes');
        $intent = $this->intencion($path = 'pallets/1/falla-doble.webp');

        $service = app(StorageCleanupService::class);
        $llamadas = 0;

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andReturnUsing(function () use (&$llamadas, $service, $intent) {
            $llamadas++;
            if ($llamadas === 1) {
                // Worker A sigue en su delete; su lease vence y worker B
                // reclama la intención, falla y registra su intento.
                $this->travel(StorageCleanupService::LEASE_SECONDS + 1)->seconds();
                try {
                    $service->process($intent->id);
                    $this->fail('El proceso de B debía fallar.');
                } catch (RuntimeException) {
                }
            }

            throw new RuntimeException('storage caído');
        });
        Storage::set('public', $doble);

        try {
            $service->process($intent->id);
            $this->fail('El proceso de A debía lanzar.');
        } catch (RuntimeException) {
        }

        $this->assertSame(2, $llamadas);
        $intent->refresh();
        // Ambos intentos contaron aunque A ya no era dueño del lease.
        $this->assertSame(2, $intent->attempts);
        // El reprogramado lo hizo el dueño vigente (B); el fallo tardío de
        // A no lo pisó ni limpió el reclamo de B (B ya lo había liberado).
        $this->assertNull($intent->claim_token);
        $this->assertNull($intent->claimed_until);
        $this->assertTrue($intent->available_at->greaterThan(now()));
    }

    public function test_lease_vencido_lo_recupera_el_barrido_con_aviso(): void
    {
        Storage::disk('public')->put('pallets/1/huerfana.webp', 'bytes');
        $intent = $this->intencion('pallets/1/huerfana.webp');
        $intent->forceFill([
            'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->addSeconds(StorageCleanupService::LEASE_SECONDS),
        ])->save();

        // Mientras el lease está activo el barrido ni la selecciona.
        $counts = app(StorageCleanupService::class)->retryPending(100, false);
        $this->assertSame(0, $counts['processed']);
        Storage::disk('public')->assertExists('pallets/1/huerfana.webp');
        $this->assertNull($intent->fresh()->completed_at);

        Log::spy();

        // El worker murió: vencido el lease, el barrido la reclama.
        $this->travel(StorageCleanupService::LEASE_SECONDS + 1)->seconds();
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $this->assertNotNull($intent->fresh()->completed_at);
        Storage::disk('public')->assertMissing('pallets/1/huerfana.webp');
        Log::shouldHaveReceived('warning')->with(
            'Intención de borrado reclamada tras lease vencido',
            Mockery::type('array'),
        );
    }

    public function test_crash_tras_borrar_con_lease_completa_sin_reintentos(): void
    {
        // El worker borró el objeto y murió antes de marcar completed_at.
        $intent = $this->intencion('pallets/1/ya-fue.webp');
        $intent->forceFill([
            'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->addSeconds(StorageCleanupService::LEASE_SECONDS),
        ])->save();

        $this->travel(StorageCleanupService::LEASE_SECONDS + 1)->seconds();
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $intent->refresh();
        $this->assertNotNull($intent->completed_at);
        $this->assertSame(0, $intent->attempts);
    }

    public function test_intencion_sobre_archivo_inexistente_se_completa_por_job(): void
    {
        $intent = $this->intencion('inexistente.webp');

        DeleteStoredFile::dispatchSync($intent->id);

        $this->assertNotNull($intent->fresh()->completed_at);
    }

    public function test_agotada_no_la_procesa_el_job_ni_el_barrido_sin_flag(): void
    {
        Storage::disk('public')->put('pallets/1/agotada.webp', 'bytes');
        $intent = PendingFileDeletion::create([
            'disk' => 'public',
            'path' => 'pallets/1/agotada.webp',
            'reason' => 'pallet_photo_deleted',
            'attempts' => StorageCleanupService::MAX_ATTEMPTS,
            'available_at' => now(),
        ]);

        $borrados = 0;
        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andReturnUsing(function () use (&$borrados) {
            $borrados++;

            return true;
        });
        Storage::set('public', $doble);

        DeleteStoredFile::dispatchSync($intent->id);
        $this->assertSame(0, $borrados);
        $this->assertNull($intent->fresh()->completed_at);

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);
        $this->assertSame(0, $borrados);
        $this->assertNull($intent->fresh()->completed_at);

        $this->artisan('storage:retry-pending-deletions', ['--include-exhausted' => true])
            ->assertExitCode(0);
        $this->assertSame(1, $borrados);
        $this->assertNotNull($intent->fresh()->completed_at);
    }

    // ── Retención y purga de completadas ─────────────────────────────────

    public function test_purga_solo_completadas_mas_viejas_que_la_retencion(): void
    {
        config(['queue.storage_cleanup_retention_days' => 30]);

        $reciente = $this->completada('pallets/1/reciente.webp', now()->subDay());
        $vieja = $this->completada('pallets/1/vieja.webp', now()->subDays(31));
        $pendienteVieja = $this->intencion('pallets/1/pendiente-vieja.webp');
        $pendienteVieja->forceFill(['created_at' => now()->subDays(40)])->save();
        $reintentandoVieja = $this->intencion('pallets/1/reintentando.webp', 3);
        $reintentandoVieja->forceFill(['created_at' => now()->subDays(40)])->save();
        $agotadaVieja = $this->intencion(
            'pallets/1/agotada-vieja.webp',
            StorageCleanupService::MAX_ATTEMPTS,
        );
        $agotadaVieja->forceFill(['created_at' => now()->subDays(40)])->save();
        $procesando = $this->intencion('pallets/1/procesando.webp');
        $procesando->forceFill([
            'created_at' => now()->subDays(40),
            'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->addMinute(),
        ])->save();

        $this->artisan('storage:prune-deletions')->assertExitCode(0);

        $this->assertDatabaseHas('pending_file_deletions', ['id' => $reciente->id]);
        $this->assertDatabaseMissing('pending_file_deletions', ['id' => $vieja->id]);
        $this->assertDatabaseHas('pending_file_deletions', ['id' => $pendienteVieja->id]);
        $this->assertDatabaseHas('pending_file_deletions', ['id' => $reintentandoVieja->id]);
        $this->assertDatabaseHas('pending_file_deletions', ['id' => $agotadaVieja->id]);
        $this->assertDatabaseHas('pending_file_deletions', ['id' => $procesando->id]);
    }

    public function test_dry_run_no_modifica_nada_ni_audita(): void
    {
        $this->completada('pallets/1/vieja.webp', now()->subDays(31));

        $this->artisan('storage:prune-deletions', ['--dry-run' => true])
            ->expectsOutputToContain('elegibles=1 eliminadas=0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('pending_file_deletions', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_purga_real_deja_auditoria_sin_paths_y_repite_con_cero(): void
    {
        $vieja = $this->completada('pallets/1/vieja-secreta.webp', now()->subDays(31));

        $this->artisan('storage:prune-deletions')->assertExitCode(0);
        $this->assertDatabaseMissing('pending_file_deletions', ['id' => $vieja->id]);

        $log = DB::table('activity_logs')->where('action', 'file_deletions_pruned')->sole();
        $this->assertSame('pending_file_deletion', $log->entity_type);
        $this->assertNull($log->entity_id);

        $valores = json_decode($log->new_values, true);
        $this->assertSame(1, $valores['deleted']);
        $this->assertSame(30, $valores['retention_days']);
        $this->assertArrayHasKey('cutoff', $valores);
        $this->assertStringNotContainsString('vieja-secreta', $log->description . $log->new_values);
        $this->assertStringNotContainsString('"disk"', $log->new_values);
        $this->assertStringNotContainsString('"path"', $log->new_values);

        // Una corrida real sin elegibles también queda auditada.
        $this->artisan('storage:prune-deletions')->assertExitCode(0);
        $logs = DB::table('activity_logs')->where('action', 'file_deletions_pruned')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(0, json_decode($logs[1]->new_values, true)['deleted']);
    }

    public function test_purga_rechaza_retencion_menor_al_minimo(): void
    {
        $this->completada('pallets/1/vieja.webp', now()->subDays(31));

        $this->artisan('storage:prune-deletions', ['--days' => 1])
            ->assertExitCode(1);

        $this->assertDatabaseCount('pending_file_deletions', 1);
        $this->assertDatabaseCount('activity_logs', 0);

        // Sin --days usa la config (30 por defecto).
        $this->artisan('storage:prune-deletions')->assertExitCode(0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_status_refleja_estados_derivados_antes_y_despues_de_purgar(): void
    {
        config(['queue.storage_cleanup_retention_days' => 30]);
        $service = app(StorageCleanupService::class);

        $this->intencion('pallets/1/pendiente.webp');
        $this->intencion('pallets/1/agotada.webp', StorageCleanupService::MAX_ATTEMPTS);
        $this->completada('pallets/1/reciente.webp', now()->subHours(2));
        $this->completada('pallets/1/purgable.webp', now()->subDays(31));
        $procesando = $this->intencion('pallets/1/procesando.webp');
        $procesando->forceFill([
            'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->addMinute(),
        ])->save();
        $leaseVencido = $this->intencion('pallets/1/lease-vencido.webp');
        $leaseVencido->forceFill([
            'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->subMinute(),
        ])->save();

        $antes = $service->status(60);
        // pending = completed_at nulo y sin agotar: incluye a la que
        // procesa otro worker y a la de lease vencido.
        $this->assertSame(3, $antes['pending']);
        $this->assertSame(1, $antes['exhausted']);
        $this->assertSame(1, $antes['completed_last_24h']);
        $this->assertSame(1, $antes['processing']);
        $this->assertSame(1, $antes['expired_leases']);
        $this->assertSame(2, $antes['completed_retained']);
        $this->assertSame(1, $antes['purgeable']);
        $this->assertSame(30, $antes['retention_days']);

        $this->artisan('storage:prune-deletions')->assertExitCode(0);

        $despues = $service->status(60);
        $this->assertSame(1, $despues['completed_retained']);
        $this->assertSame(0, $despues['purgeable']);
        // La métrica de 24h no se ve afectada por la purga.
        $this->assertSame(1, $despues['completed_last_24h']);

        // Los criterios de alerta del comando no cambiaron.
        $this->artisan('storage:cleanup-status')->assertExitCode(1); // agotada → alerta
    }

    // ── Guardas de consistencia del lease ────────────────────────────────

    public function test_lease_mayor_que_timeout_del_job_y_menor_que_intervalo_del_barrido(): void
    {
        $conf = file_get_contents(dirname(base_path()) . DIRECTORY_SEPARATOR . 'supervisord.conf');
        $this->assertIsString($conf);

        $this->assertSame(
            1,
            preg_match('/file-cleanup.*--interval=(\d+)/s', $conf, $m),
            'No se encontró --interval en el programa file-cleanup de supervisord.conf',
        );
        $intervaloBarrido = (int) $m[1];

        $this->assertGreaterThan((new DeleteStoredFile(1))->timeout, StorageCleanupService::LEASE_SECONDS);
        $this->assertLessThan($intervaloBarrido, StorageCleanupService::LEASE_SECONDS);
    }

    // ── Saneamiento en el camino con lease ───────────────────────────────

    public function test_excepcion_de_storage_no_filtra_secreto_en_last_error_ni_logs(): void
    {
        Storage::disk('public')->put('pallets/1/archivo.webp', 'bytes');
        $intent = $this->intencion('pallets/1/archivo.webp');

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
        }

        $this->assertSame(RuntimeException::class, $intent->fresh()->last_error);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ! str_contains($message . json_encode($context), 'secreto'),
        );
        Log::shouldNotHaveReceived('critical');
    }

    public function test_intencion_purgada_es_noop_para_job_tardio(): void
    {
        $intent = $this->completada('pallets/1/purgada.webp', now()->subDays(31));
        $id = $intent->id;

        $this->artisan('storage:prune-deletions')->assertExitCode(0);
        $this->assertDatabaseMissing('pending_file_deletions', ['id' => $id]);

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldNotReceive('delete');
        $doble->shouldNotReceive('exists');
        Storage::set('public', $doble);

        // Un job duplicado que llega después de la purga es un no-op.
        $this->assertTrue(app(StorageCleanupService::class)->process($id));
        DeleteStoredFile::dispatchSync($id);
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

    private function completada(string $path, $completedAt): PendingFileDeletion
    {
        return PendingFileDeletion::create([
            'disk' => 'public',
            'path' => $path,
            'reason' => 'pallet_photo_deleted',
            'available_at' => now(),
            'completed_at' => $completedAt,
        ]);
    }
}
