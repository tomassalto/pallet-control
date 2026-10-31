<?php

namespace Tests\Integration\Postgres;

use App\Models\PendingFileDeletion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Integration\Postgres\Concerns\RunsConcurrentRequests;
use Tests\TestCase;

/**
 * Acredita que el reclamo por lease serializa dos barridos concurrentes:
 * con una compuerta FOR UPDATE ambos procesos `storage:retry-pending-deletions`
 * llegan al UPDATE condicional al mismo tiempo y exactamente uno gana.
 * Cada escenario corre 3 iteraciones con datos frescos sobre el disco
 * `public` real.
 */
#[Group('postgres')]
class StorageCleanupConcurrencyTest extends TestCase
{
    use RunsConcurrentRequests;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('La extensión pdo_pgsql no está instalada en este runtime.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Este gate sólo acredita concurrencia cuando usa PostgreSQL.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        $this->stopServersAndProcesses();

        parent::tearDown();
    }

    public function test_dos_barridos_concurrentes_una_sola_intencion(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $path = "concurrency/fresh-{$i}-" . Str::random(8) . '.webp';
            Storage::disk('public')->put($path, 'bytes');
            $intent = $this->intencion($path);

            [$salidaA, $salidaB] = $this->dosBarridosEnCompuerta($intent->id);

            $this->assertUnoCompletaYOtroOmite($salidaA, $salidaB, $intent, $path);

            Storage::disk('public')->delete($path);
        }
    }

    public function test_dos_barridos_concurrentes_lease_vencido_de_worker_muerto(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $path = "concurrency/lease-{$i}-" . Str::random(8) . '.webp';
            Storage::disk('public')->put($path, 'bytes');
            $intent = $this->intencion($path);
            $intent->forceFill([
                'claim_token' => (string) Str::uuid(),
                'claimed_until' => now()->subMinute(),
            ])->save();

            [$salidaA, $salidaB] = $this->dosBarridosEnCompuerta($intent->id);

            $this->assertUnoCompletaYOtroOmite($salidaA, $salidaB, $intent, $path);

            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Lanza dos barridos hijos que quedan bloqueados por la compuerta
     * (FOR UPDATE lateral sobre la intención) hasta que ambos esperan
     * el lock; al liberarla compiten por el reclamo.
     *
     * @return array{0: string, 1: string}
     */
    private function dosBarridosEnCompuerta(int $intentId): array
    {
        $gate = $this->openGateLock(
            'SELECT id FROM pending_file_deletions WHERE id = ? FOR UPDATE',
            $intentId,
        );

        $procesos = [
            $this->barridoHijo(),
            $this->barridoHijo(),
        ];

        try {
            $this->waitForLockWaits(2);
        } finally {
            $gate->commit();
        }

        $salidas = [];
        foreach ($procesos as $proceso) {
            $proceso->wait();
            $salidas[] = $proceso->getOutput() . $proceso->getErrorOutput();
            $this->assertSame(0, $proceso->getExitCode(), $salidas[count($salidas) - 1]);
        }

        return [$salidas[0], $salidas[1]];
    }

    private function barridoHijo(): Process
    {
        $proceso = new Process([
            PHP_BINARY,
            'artisan',
            'storage:retry-pending-deletions',
            '--limit=1',
        ], base_path(), $this->entornoHijo());
        $proceso->setTimeout(60);
        $proceso->start();
        $this->processes[] = $proceso;

        return $proceso;
    }

    /** @return array<string, string> */
    private function entornoHijo(): array
    {
        [$host, $port, $database, $user, $password] = $this->pgsqlParams();

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
            'FILESYSTEM_DISK' => 'public',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
        ];
    }

    private function intencion(string $path): PendingFileDeletion
    {
        return PendingFileDeletion::create([
            'disk' => 'public',
            'path' => $path,
            'reason' => 'pallet_photo_deleted',
            'available_at' => now(),
        ]);
    }

    private function assertUnoCompletaYOtroOmite(
        string $salidaA,
        string $salidaB,
        PendingFileDeletion $intent,
        string $path,
    ): void {
        $completadas = (int) str_contains($salidaA, 'completadas=1')
            + (int) str_contains($salidaB, 'completadas=1');
        $omitidas = (int) str_contains($salidaA, 'omitidas=1')
            + (int) str_contains($salidaB, 'omitidas=1');

        $this->assertSame(1, $completadas, "Salidas:\n{$salidaA}\n{$salidaB}");
        $this->assertSame(1, $omitidas, "Salidas:\n{$salidaA}\n{$salidaB}");

        $this->assertFalse(Storage::disk('public')->exists($path));

        $intent->refresh();
        $this->assertNotNull($intent->completed_at);
        $this->assertSame(0, $intent->attempts);
        $this->assertNull($intent->claim_token);
        $this->assertNull($intent->claimed_until);
    }
}
