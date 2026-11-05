<?php

namespace Tests\Integration\Postgres;

use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Models\PhotoAnnotation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * H-050 — contrato objetivo en PostgreSQL de las resoluciones manuales
 * de segmentos escalares {base} y {photo} sobre PKs bigint
 * (PalletBasePhotoController, PhotoAnnotationController y
 * PalletPhotoController), gobernadas por la API pública del trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (extensión de
 * D-033).
 *
 * Antes del cambio, el segmento crudo llegaba a la comparación
 * `id = '...'` contra bigint y PostgreSQL abortaba con
 * SQLSTATE[22P02]/[22003] → 500 genérico (caracterización previa en
 * BigintRouteBindingCharacterizationTest). Estos tests acreditan que
 * ahora el segmento se normaliza/rechaza antes de la consulta:
 * ModelNotFoundException para {base} (findOrFailRouteId) y la respuesta
 * 404 "Foto no encontrada" para {photo} (normalizeRouteId + find por
 * relación), sin emitir la consulta por PK.
 *
 * La matriz exhaustiva vive en el espejo SQLite
 * (tests/Feature/Routing/ManualPhotoParameterContractTest); acá se
 * cubren rutas representativas de cada tipo en ambos motores.
 */
#[Group('postgres')]
class ManualPhotoParameterContractTest extends TestCase
{
    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '1.5', '-1', '01', '99999999999999999999', '9223372036854775808'];

    private const SEGMENTOS_NUMERICOS = ['0', '99999', '9223372036854775807'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('La extensión pdo_pgsql no está instalada en este runtime.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Este gate sólo corre contra PostgreSQL.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    /**
     * @return array{Pallet, PalletBase, PalletBasePhoto, PalletPhoto}
     */
    private function palletConJerarquia(string $palletStatus = 'open'): array
    {
        $pallet = Pallet::factory()->create(['status' => $palletStatus]);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $basePhoto = PalletBasePhoto::create([
            'base_id' => $base->id,
            'path' => 'bases/b.webp',
            'original_name' => 'b.webp',
        ]);
        $palletPhoto = PalletPhoto::create([
            'pallet_id' => $pallet->id,
            'path' => 'pallets/p.webp',
            'original_name' => 'p.webp',
        ]);

        return [$pallet, $base, $basePhoto, $palletPhoto];
    }

    private function payloadAnotaciones(): array
    {
        return ['annotations' => [
            ['text' => '1', 'x' => 10, 'y' => 20],
            ['text' => '2', 'x' => 30, 'y' => 40],
        ]];
    }

    /**
     * Ejecuta el request sin el handler global para observar la
     * excepción real (restaura el handler al salir).
     */
    private function excepcionDe(callable $request): ?Throwable
    {
        $this->withoutExceptionHandling();
        try {
            $request();

            return null;
        } catch (Throwable $e) {
            return $e;
        } finally {
            $this->withExceptionHandling();
        }
    }

    /**
     * @param array<int, string> $consultas
     */
    private function capturarConsultas(array &$consultas): void
    {
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });
    }

    /**
     * @param array<int, string> $consultas
     */
    private function assertSinConsultasA(array $consultas, string $tabla): void
    {
        $filtradas = array_values(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, '"' . $tabla . '"')
        ));

        $this->assertSame([], $filtradas, "Se emitieron consultas sobre {$tabla}: " . implode(' | ', $filtradas));
    }

    /**
     * @param array<int, string> $consultas
     */
    private function assertConConsultasA(array $consultas, string $tabla): void
    {
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, '"' . $tabla . '"')
        ), "No se emitió ninguna consulta sobre {$tabla}");
    }

    // ── {base}: segmentos inválidos → 404, sin query ni 22P02 ──────────────

    #[Test]
    public function base_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'POST foto de base' => fn (string $s) => $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$s}/photos"),
            'DELETE foto de base' => fn (string $s) => $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$s}/photos/{$basePhoto->id}"),
            'GET anotaciones' => fn (string $s) => $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$s}/photos/{$basePhoto->id}/annotations"),
            'POST anotaciones' => fn (string $s) => $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$s}/photos/{$basePhoto->id}/annotations", $this->payloadAnotaciones()),
        ];

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            foreach ($rutas as $contexto => $request) {
                // La excepción real es ModelNotFoundException: ninguna
                // QueryException de clase 22 llega a producirse.
                $e = $this->excepcionDe(fn () => $request($seg));
                $this->assertInstanceOf(ModelNotFoundException::class, $e, "{$contexto} {$seg}");

                $request($seg)->assertNotFound("{$contexto} {$seg}");
            }
        }

        // El binding de {pallet} sí consultó pallets; la resolución
        // manual de {base} nunca tocó pallet_bases ni las fotos.
        $this->assertConConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');
        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
        $this->assertSinConsultasA($consultas, 'photo_annotations');

        $this->assertNotNull($base->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
    }

    // ── {photo}: segmentos inválidos → 404, sin query ni 22P02 ─────────────

    #[Test]
    public function photo_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto, $palletPhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'DELETE foto de pallet' => fn (string $s) => $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$s}"),
            'DELETE foto de base' => fn (string $s) => $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$s}"),
            'GET anotaciones' => fn (string $s) => $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$s}/annotations"),
            'POST anotaciones' => fn (string $s) => $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$s}/annotations", $this->payloadAnotaciones()),
        ];

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            foreach ($rutas as $contexto => $request) {
                // La respuesta es un 404 limpio del controlador: no hay
                // excepción — mucho menos QueryException de clase 22.
                $e = $this->excepcionDe(fn () => $request($seg));
                $this->assertNull($e, "{$contexto} {$seg}: se esperaba respuesta 404 sin excepción");

                $request($seg)
                    ->assertNotFound("{$contexto} {$seg}")
                    ->assertJsonPath('message', 'Foto no encontrada');
            }
        }

        // Los padres válidos sí se consultan; ninguna tabla de fotos ni
        // de anotaciones recibe la comparación con el segmento crudo.
        $this->assertConConsultasA($consultas, 'pallets');
        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertSinConsultasA($consultas, 'pallet_photos');
        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
        $this->assertSinConsultasA($consultas, 'photo_annotations');

        $this->assertNotNull($basePhoto->fresh());
        $this->assertNotNull($palletPhoto->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
    }

    // ── Ids numéricos: contraste con el segmento inválido ─────────────────

    #[Test]
    public function segmentos_numericos_inexistentes_consultan_y_devuelven_404(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto, $palletPhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_NUMERICOS as $seg) {
            $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos")->assertNotFound();
            $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$seg}")
                ->assertNotFound()
                ->assertJsonPath('message', 'Foto no encontrada');
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$seg}/annotations")
                ->assertNotFound()
                ->assertJsonPath('message', 'Foto no encontrada');
        }

        // Un id bien formado sí emite la consulta por PK (bigint
        // válido) y la resolución devuelve 404 — nunca 22P02/22003.
        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertConConsultasA($consultas, 'pallet_photos');
        $this->assertConConsultasA($consultas, 'pallet_base_photos');
        $this->assertNotNull($basePhoto->fresh());
        $this->assertNotNull($palletPhoto->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Ownership y precedencias en PostgreSQL ─────────────────────────────

    #[Test]
    public function ownership_y_precedencias_se_conservan(): void
    {
        [$palletA] = $this->palletConJerarquia();
        [$palletB, $baseB, $fotoBaseB] = $this->palletConJerarquia();
        [, , , $fotoPalletB] = $this->palletConJerarquia();

        // Anónimo: auth:sanctum corta antes de todo → 401.
        $this->getJson("/api/v1/pallets/{$palletA->id}/bases/abc/photos/1/annotations")->assertUnauthorized();
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/photos/abc")->assertUnauthorized();

        Sanctum::actingAs($this->usuario());

        // Base de otro pallet → "Base no encontrada"; fotos de otro
        // pallet → "Foto no encontrada" (o "Base no encontrada" cuando
        // el corte es la base).
        $this->getJson("/api/v1/pallets/{$palletA->id}/bases/{$baseB->id}/photos/{$fotoBaseB->id}/annotations")
            ->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada');
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/photos/{$fotoPalletB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');

        // Inactivo: la resolución manual corre dentro de la acción,
        // después de user.active → 401 aunque el segmento sea inválido
        // (a diferencia del binding implícito, que daría 404).
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $this->getJson("/api/v1/pallets/{$palletA->id}/bases/abc/photos/1/annotations")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/photos/abc")->assertUnauthorized();

        // Sin rol: has.role corta las escrituras → 403; el GET pasa y
        // el segmento inválido llega a la resolución → 404.
        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/photos/abc")->assertForbidden();
        $this->postJson("/api/v1/pallets/{$palletA->id}/bases/abc/photos")->assertForbidden();
        $this->getJson("/api/v1/pallets/{$palletA->id}/bases/abc/photos/1/annotations")
            ->assertNotFound();

        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
    }

    #[Test]
    public function pallet_finalizado_y_flujos_validos_conservan_su_contrato(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto, $palletPhoto] = $this->palletConJerarquia('done');

        // `done` gana al segmento de foto inválido en los DELETE (el
        // lock del pallet precede a la búsqueda) y a las escrituras
        // válidas; la lectura sigue abierta y la resolución inválida de
        // anotaciones (previa a la transacción) da 404.
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/abc")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc/annotations", $this->payloadAnotaciones())
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations")
            ->assertOk();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$palletPhoto->id}")->assertConflict();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 50, 50),
        ])->assertConflict();
        // La compensación del upload no dejó archivos.
        $this->assertCount(0, Storage::disk('public')->allFiles());

        // Pallet abierto: flujos válidos representativos.
        [$palletOk, $baseOk, $fotoBaseOk, $fotoPalletOk] = $this->palletConJerarquia();

        $this->postJson("/api/v1/pallets/{$palletOk->id}/bases/{$baseOk->id}/photos", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100),
        ])->assertCreated()
            ->assertJsonStructure(['photo', 'url']);

        $this->postJson("/api/v1/pallets/{$palletOk->id}/bases/{$baseOk->id}/photos/{$fotoBaseOk->id}/annotations", $this->payloadAnotaciones())
            ->assertCreated()
            ->assertJsonCount(2, 'annotations');
        $this->assertSame(2, PhotoAnnotation::where('photo_id', $fotoBaseOk->id)->count());

        $this->deleteJson("/api/v1/pallets/{$palletOk->id}/photos/{$fotoPalletOk->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Foto eliminada');
        $this->assertDatabaseMissing('pallet_photos', ['id' => $fotoPalletOk->id]);
        $this->assertDatabaseHas('pending_file_deletions', ['reason' => 'pallet_photo_deleted']);

        $this->assertGreaterThan(0, DB::table('activity_logs')->count());
    }
}
