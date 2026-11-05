<?php

namespace Tests\Feature\Routing;

use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Models\PhotoAnnotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * H-050 — contrato objetivo (SQLite) de las resoluciones manuales de
 * segmentos escalares {base} y {photo} sobre PKs bigint, gobernadas por
 * la API pública del trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (extensión de
 * D-033): normalizeRouteId() para lookups por relación y
 * findOrFailRouteId() para el findOrFail global de la base.
 *
 * Inventario (5 rutas únicas): 4 apariciones de {base} (store/destroy
 * de foto de base, index/store de anotaciones), 4 de {photo} (destroy
 * de foto de pallet, destroy de foto de base, index/store de
 * anotaciones) y 3 rutas que contienen ambos parámetros. En
 * PostgreSQL la comparación cruda contra bigint abortaba con
 * SQLSTATE[22P02]/[22003] → 500 (espejo en
 * tests/Integration/Postgres/ManualPhotoParameterContractTest).
 *
 * A diferencia de los bindings implícitos, la resolución manual ocurre
 * DENTRO de la acción, después de todo el middleware: la precedencia
 * vigente es auth:sanctum (401) → {pallet} binding (404) → user.active
 * (401) → has.role (403) → resolución manual (404) → Form Request/
 * validación (422) → dominio (409). Estos tests fijan esa precedencia:
 * inactivo y sin-rol nunca llegan al segmento, y pallet `done` conserva
 * su 409 en el punto exacto donde cada acción lo evaluaba antes.
 */
class ManualPhotoParameterContractTest extends TestCase
{
    use RefreshDatabase;

    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '1.5', '-1', '01', '99999999999999999999', '9223372036854775808'];

    private const SEGMENTOS_NUMERICOS = ['0', '99999', '9223372036854775807'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    /**
     * Pallet con base propia, una foto de base y una foto de pallet,
     * listos para las cinco rutas de la familia.
     *
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

    /**
     * Las 4 rutas que resuelven {base} a mano. $segBase es el segmento
     * crudo que ocupa el lugar del parámetro; $photoId el id real de la
     * foto de base (nunca se alcanza con {base} inválido).
     *
     * @return array<string, array{string, string, array}>
     */
    private function rutasConBase(Pallet $pallet, string $segBase, int $photoId): array
    {
        $prefijo = "/api/v1/pallets/{$pallet->id}/bases/{$segBase}";

        return [
            'POST foto de base' => ['post', "{$prefijo}/photos", []],
            'DELETE foto de base' => ['delete', "{$prefijo}/photos/{$photoId}", []],
            'GET anotaciones' => ['get', "{$prefijo}/photos/{$photoId}/annotations", []],
            'POST anotaciones' => ['post', "{$prefijo}/photos/{$photoId}/annotations", $this->payloadAnotaciones()],
        ];
    }

    /**
     * Las 4 rutas que resuelven {photo} a mano. $segPhoto es el
     * segmento crudo; en las rutas de base se usa la base real.
     *
     * @return array<string, array{string, string, array}>
     */
    private function rutasConPhoto(Pallet $pallet, PalletBase $base, string $segPhoto): array
    {
        $prefijoBase = "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$segPhoto}";

        return [
            'DELETE foto de pallet' => ['delete', "/api/v1/pallets/{$pallet->id}/photos/{$segPhoto}", []],
            'DELETE foto de base' => ['delete', $prefijoBase, []],
            'GET anotaciones' => ['get', "{$prefijoBase}/annotations", []],
            'POST anotaciones' => ['post', "{$prefijoBase}/annotations", $this->payloadAnotaciones()],
        ];
    }

    private function payloadAnotaciones(): array
    {
        return ['annotations' => [
            ['text' => '1', 'x' => 10, 'y' => 20],
            ['text' => '2', 'x' => 30, 'y' => 40],
        ]];
    }

    private function ejecutar(array $ruta): mixed
    {
        [$verbo, $uri, $payload] = $ruta;

        return $this->{$verbo . 'Json'}($uri, $payload);
    }

    /**
     * Registra un listener que vuelca el SQL emitido en $consultas.
     *
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

    /**
     * Nombre de clase del type-hint del parámetro de ruta, o null si es
     * escalar/ausente (resolución manual, fuera del binding implícito).
     */
    private function tipoParametro($route, string $parametro): ?string
    {
        $action = $route->getActionName();

        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action);

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parametroRef) {
            if ($parametroRef->getName() !== $parametro) {
                continue;
            }

            $tipo = $parametroRef->getType();

            return $tipo instanceof ReflectionNamedType && ! $tipo->isBuiltin()
                ? $tipo->getName()
                : null;
        }

        return null;
    }

    /**
     * Fuente de la acción controladora (método completo) para verificar
     * que usa la autoridad común de normalización.
     */
    private function fuenteAccion(string $actionName): string
    {
        [$class, $method] = explode('@', $actionName);
        $ref = new ReflectionMethod($class, $method);
        $lineas = file($ref->getFileName());

        return implode('', array_slice(
            $lineas,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1,
        ));
    }

    // ── Inventario automático y test estructural ───────────────────────────

    #[Test]
    public function las_rutas_manuales_estan_cubiertas_por_la_autoridad_comun(): void
    {
        foreach ([PalletBase::class, PalletBasePhoto::class, PalletPhoto::class] as $modelo) {
            $this->assertContains(
                RejectsInvalidIntegerRouteBindings::class,
                class_uses_recursive($modelo),
                "{$modelo} debe usar RejectsInvalidIntegerRouteBindings"
            );
        }

        // Las 5 rutas manuales esperadas (método + uri):
        // [uri, acción, tokens de la autoridad común en su fuente].
        $esperadas = [
            ['api/v1/pallets/{pallet}/bases/{base}/photos', 'App\Http\Controllers\Api\PalletBasePhotoController@store', ['findOrFailRouteId']],
            ['api/v1/pallets/{pallet}/bases/{base}/photos/{photo}', 'App\Http\Controllers\Api\PalletBasePhotoController@destroy', ['findOrFailRouteId', 'normalizeRouteId']],
            ['api/v1/pallets/{pallet}/bases/{base}/photos/{photo}/annotations', 'App\Http\Controllers\Api\PhotoAnnotationController@index', ['findOrFailRouteId', 'normalizeRouteId']],
            ['api/v1/pallets/{pallet}/bases/{base}/photos/{photo}/annotations', 'App\Http\Controllers\Api\PhotoAnnotationController@store', ['findOrFailRouteId', 'normalizeRouteId']],
            ['api/v1/pallets/{pallet}/photos/{photo}', 'App\Http\Controllers\Api\PalletPhotoController@destroy', ['normalizeRouteId']],
        ];

        $encontradas = [];
        $tipadas = ['base' => 0, 'photo' => 0];

        foreach (Route::getRoutes() as $route) {
            foreach (['base', 'photo'] as $parametro) {
                if (! in_array($parametro, $route->parameterNames(), true)) {
                    continue;
                }

                if ($this->tipoParametro($route, $parametro) !== null) {
                    $tipadas[$parametro]++;

                    continue;
                }

                $encontradas[$route->uri() . '|' . $route->getActionName()] = $route;
            }
        }

        // Exactamente 5 rutas (método + uri) con resolución manual:
        // 4 apariciones de {base}, 4 de {photo}, 3 rutas con ambos.
        $this->assertCount(5, $encontradas, 'Se esperaban 5 rutas con parámetros manuales');

        $conBase = 0;
        $conPhoto = 0;
        $conAmbos = 0;
        foreach ($encontradas as $route) {
            $tieneBase = in_array('base', $route->parameterNames(), true);
            $tienePhoto = in_array('photo', $route->parameterNames(), true);
            $conBase += $tieneBase ? 1 : 0;
            $conPhoto += $tienePhoto ? 1 : 0;
            $conAmbos += $tieneBase && $tienePhoto ? 1 : 0;
        }
        $this->assertSame(4, $conBase, 'Apariciones de {base} manuales');
        $this->assertSame(4, $conPhoto, 'Apariciones de {photo} manuales');
        $this->assertSame(3, $conAmbos, 'Rutas con ambos parámetros');

        // Los bindings implícitos no se confunden con manuales: 4 {base}
        // → PalletBase y 4 {photo} → OrderTicketPhoto siguen tipados.
        $this->assertSame(4, $tipadas['base'], 'Rutas con {base} tipado');
        $this->assertSame(4, $tipadas['photo'], 'Rutas con {photo} tipado');

        // Cada acción manual usa la autoridad común esperada.
        $verificadas = [];
        foreach ($esperadas as [$uri, $accion, $tokens]) {
            $clave = $uri . '|' . $accion;
            $this->assertArrayHasKey($clave, $encontradas, "Falta la ruta manual {$accion}");

            $route = $encontradas[$clave];
            if (in_array('base', $route->parameterNames(), true)) {
                $this->assertNull($this->tipoParametro($route, 'base'), "{$uri}: {base} no debe tener type-hint");
            }
            if (in_array('photo', $route->parameterNames(), true)) {
                $this->assertNull($this->tipoParametro($route, 'photo'), "{$uri}: {photo} no debe tener type-hint");
            }
            $this->assertSame(Pallet::class, $this->tipoParametro($route, 'pallet'), "{$uri}: {pallet} debe ser binding implícito");

            $fuente = $this->fuenteAccion($accion);
            foreach ($tokens as $token) {
                $this->assertStringContainsString($token, $fuente, "{$accion} debe usar {$token}");
            }
            $verificadas[] = $clave;
        }

        $this->assertCount(5, $verificadas);
    }

    // ── {base}: segmentos inválidos → 404 sin consultar ────────────────────

    #[Test]
    public function base_segmentos_invalidos_devuelven_404_sin_consultar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            foreach ($this->rutasConBase($pallet, $seg, $basePhoto->id) as $contexto => $ruta) {
                $this->ejecutar($ruta)->assertNotFound("{$contexto} {$seg}");
            }
        }

        // El rechazo ocurre dentro de la acción, antes de la consulta:
        // el binding de {pallet} sí consultó pallets, pero ninguna ruta
        // tocó pallet_bases ni las tablas de fotos/anotaciones.
        $this->assertConConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');
        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
        $this->assertSinConsultasA($consultas, 'pallet_photos');
        $this->assertSinConsultasA($consultas, 'photo_annotations');

        // Cero efectos laterales: entidades intactas, sin auditoría, sin
        // intenciones durables y sin archivos nuevos.
        $this->assertNotNull($base->fresh());
        $this->assertSame(1, PalletBasePhoto::count());
        $this->assertSame(0, PhotoAnnotation::count());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    // ── {photo}: segmentos inválidos → 404 sin consultar ───────────────────

    #[Test]
    public function photo_segmentos_invalidos_devuelven_404_sin_consultar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto, $palletPhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            foreach ($this->rutasConPhoto($pallet, $base, $seg) as $contexto => $ruta) {
                $this->ejecutar($ruta)
                    ->assertNotFound("{$contexto} {$seg}")
                    ->assertJsonPath('message', 'Foto no encontrada');
            }
        }

        // Los padres válidos sí se consultan (pallets, pallet_bases en
        // las rutas anidadas); ninguna tabla de fotos ni de anotaciones
        // recibe la comparación con el segmento crudo.
        $this->assertConConsultasA($consultas, 'pallets');
        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertSinConsultasA($consultas, 'pallet_photos');
        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
        $this->assertSinConsultasA($consultas, 'photo_annotations');

        // Cero efectos laterales.
        $this->assertNotNull($basePhoto->fresh());
        $this->assertNotNull($palletPhoto->fresh());
        $this->assertSame(0, PhotoAnnotation::count());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
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
            // {base}: un id bien formado sí emite la consulta por PK.
            $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos")->assertNotFound();
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos/{$basePhoto->id}/annotations")
                ->assertNotFound();

            // {photo}: igual, la relación consulta y no encuentra.
            $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$seg}")
                ->assertNotFound()
                ->assertJsonPath('message', 'Foto no encontrada');
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$seg}/annotations")
                ->assertNotFound()
                ->assertJsonPath('message', 'Foto no encontrada');
        }

        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertConConsultasA($consultas, 'pallet_photos');
        $this->assertConConsultasA($consultas, 'pallet_base_photos');
        $this->assertNotNull($basePhoto->fresh());
        $this->assertNotNull($palletPhoto->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Casos límite del filtro (contrato D-033) ───────────────────────────

    #[Test]
    public function segmentos_limite_del_filtro_conservan_su_semantica(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto, $palletPhoto] = $this->palletConJerarquia();

        // filter_var acepta signo '+' y espacios alrededor del número:
        // se normalizan al id y la resolución manual encuentra la entidad.
        foreach (['+' . $base->id, '%20' . $base->id] as $seg) {
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos/{$basePhoto->id}/annotations")
                ->assertOk("base aceptada {$seg}")
                ->assertJsonStructure(['annotations']);
        }
        foreach (['+' . $basePhoto->id, '%20' . $basePhoto->id] as $seg) {
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$seg}/annotations")
                ->assertOk("foto de base aceptada {$seg}")
                ->assertJsonStructure(['annotations']);
        }
        // La única ruta de foto de pallet es DELETE: para acreditar la
        // normalización de '+id'/espacios se crea una foto por segmento
        // y el 200 prueba que el id se resolvió al modelo real.
        foreach (['+', '%20'] as $prefijo) {
            $foto = PalletPhoto::create([
                'pallet_id' => $pallet->id,
                'path' => "pallets/{$prefijo}.webp",
                'original_name' => 'x.webp',
            ]);
            $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$prefijo}{$foto->id}")
                ->assertOk("foto de pallet aceptada {$prefijo}");
        }
        $this->assertSame(1, PalletPhoto::count());

        // Rechaza ceros a la izquierda → 404 sin consultar la tabla.
        $consultas = [];
        $this->capturarConsultas($consultas);
        foreach (['0' . $base->id, '00' . $base->id] as $seg) {
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos/{$basePhoto->id}/annotations")
                ->assertNotFound("base rechazada {$seg}");
        }
        $this->assertSinConsultasA($consultas, 'pallet_bases');

        // Para {photo} la base válida sí se consulta; la tabla de fotos
        // nunca recibe el segmento con ceros a la izquierda.
        $consultas = [];
        $this->capturarConsultas($consultas);
        foreach (['0' . $basePhoto->id, '00' . $basePhoto->id] as $seg) {
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$seg}/annotations")
                ->assertNotFound()
                ->assertJsonPath('message', 'Foto no encontrada');
        }
        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
    }

    // ── Ownership y nesting ────────────────────────────────────────────────

    #[Test]
    public function base_de_otro_pallet_conserva_404_sin_operar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$palletA] = $this->palletConJerarquia();
        [$palletB, $baseAjena, $basePhotoAjena] = $this->palletConJerarquia();

        // La base se resuelve por id global; la pertenencia la verifica
        // el controlador y responde 404 — una base válida nunca opera
        // bajo un pallet incorrecto.
        foreach ($this->rutasConBase($palletA, (string) $baseAjena->id, $basePhotoAjena->id) as $contexto => $ruta) {
            $this->ejecutar($ruta)
                ->assertNotFound("{$contexto}")
                ->assertJsonPath('message', 'Base no encontrada');
        }

        $this->assertNotNull($baseAjena->fresh());
        $this->assertNotNull($basePhotoAjena->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    #[Test]
    public function foto_de_otra_base_conserva_404_sin_operar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $basePropia, , $palletPhoto] = $this->palletConJerarquia();
        $baseAjena = PalletBase::factory()->for($pallet, 'pallet')->create();
        $fotoAjena = PalletBasePhoto::create([
            'base_id' => $baseAjena->id,
            'path' => 'bases/ajena.webp',
            'original_name' => 'ajena.webp',
        ]);

        // La foto existe pero cuelga de otra base del mismo pallet: la
        // relación $base->photos() no la alcanza → 404.
        foreach ($this->rutasConPhoto($pallet, $basePropia, (string) $fotoAjena->id) as $contexto => $ruta) {
            if ($contexto === 'DELETE foto de pallet') {
                continue; // esa ruta consulta pallet_photos, otra familia
            }
            $this->ejecutar($ruta)
                ->assertNotFound("{$contexto}")
                ->assertJsonPath('message', 'Foto no encontrada');
        }

        // Una foto de pallet (otra tabla) no se resuelve por la ruta de
        // base: se usa una base vacía para que el id no coincida con
        // ninguna pallet_base_photos propia.
        $baseVacia = PalletBase::factory()->for($pallet, 'pallet')->create();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$baseVacia->id}/photos/{$palletPhoto->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');

        $this->assertNotNull($fotoAjena->fresh());
        $this->assertNotNull($palletPhoto->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    #[Test]
    public function foto_de_otro_pallet_conserva_404_sin_operar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$palletA] = $this->palletConJerarquia();
        [$palletB, $baseB, , $fotoPalletB] = $this->palletConJerarquia();
        // Foto de base extra con id fresco: no colisiona con los ids de
        // pallet_photos, así el 404 prueba el scope por tabla/padre.
        $fotoBaseB = PalletBasePhoto::create([
            'base_id' => $baseB->id,
            'path' => 'bases/extra.webp',
            'original_name' => 'extra.webp',
        ]);

        // Fotos válidas de otro pallet: la relación con el padre las
        // excluye → 404 en las tres rutas que las alcanzan.
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/photos/{$fotoPalletB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/bases/{$baseB->id}/photos/{$fotoBaseB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada');
        $this->getJson("/api/v1/pallets/{$palletA->id}/bases/{$baseB->id}/photos/{$fotoBaseB->id}/annotations")
            ->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada');

        // Y una foto de base no se resuelve por la ruta de foto de pallet.
        $this->deleteJson("/api/v1/pallets/{$palletB->id}/photos/{$fotoBaseB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');

        $this->assertNotNull($fotoPalletB->fresh());
        $this->assertNotNull($fotoBaseB->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    // ── Precedencias vigentes ──────────────────────────────────────────────

    #[Test]
    public function anonimo_recibe_401_antes_de_la_resolucion_manual(): void
    {
        foreach (['abc', '1'] as $seg) {
            $this->postJson("/api/v1/pallets/1/bases/{$seg}/photos")->assertUnauthorized();
            $this->deleteJson("/api/v1/pallets/1/bases/{$seg}/photos/1")->assertUnauthorized();
            $this->getJson("/api/v1/pallets/1/bases/{$seg}/photos/1/annotations")->assertUnauthorized();
            $this->postJson("/api/v1/pallets/1/bases/{$seg}/photos/1/annotations", [])->assertUnauthorized();
            $this->deleteJson("/api/v1/pallets/1/photos/{$seg}")->assertUnauthorized();
        }
    }

    #[Test]
    public function inactivo_recibe_401_antes_de_la_resolucion_manual(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        // Diferencia clave con los bindings implícitos: la resolución
        // manual corre dentro de la acción, DESPUÉS de user.active —
        // inactivo recibe 401 tanto con segmento válido como inválido.
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos")->assertUnauthorized();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos")->assertUnauthorized();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/abc")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations")
            ->assertUnauthorized();
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos/{$basePhoto->id}/annotations")
            ->assertUnauthorized();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function sin_rol_recibe_403_en_escrituras_antes_del_segmento(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        // has.role corta las escrituras antes del controlador: segmento
        // inválido o válido da 403, nunca 404 ni 500.
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos")->assertForbidden();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos")->assertForbidden();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/abc")->assertForbidden();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc")->assertForbidden();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations", [])
            ->assertForbidden();

        // GET pasa has.role (lectura): el segmento inválido sí llega a
        // la resolución manual → 404.
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos/{$basePhoto->id}/annotations")
            ->assertNotFound();
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc/annotations")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');

        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_invalido_corta_antes_de_consultar_bases_y_fotos(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {pallet} es binding implícito y precede a todo: al fallar, ni
        // las bases ni las fotos se consultan en ninguna ruta.
        $this->postJson("/api/v1/pallets/abc/bases/{$base->id}/photos")->assertNotFound();
        $this->deleteJson("/api/v1/pallets/abc/bases/{$base->id}/photos/{$basePhoto->id}")->assertNotFound();
        $this->getJson("/api/v1/pallets/abc/bases/{$base->id}/photos/{$basePhoto->id}/annotations")->assertNotFound();
        $this->deleteJson("/api/v1/pallets/abc/photos/{$basePhoto->id}")->assertNotFound();

        $this->assertSinConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');
        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
        $this->assertSinConsultasA($consultas, 'pallet_photos');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function base_invalida_corta_antes_de_consultar_fotos(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // Con {base} inválido el 404 sale antes de mirar {photo}: las
        // tablas de fotos no se consultan aunque el segmento de foto
        // sea válido.
        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos/{$basePhoto->id}/annotations")
                ->assertNotFound();
            $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/photos/{$basePhoto->id}")
                ->assertNotFound();
        }

        $this->assertSinConsultasA($consultas, 'pallet_base_photos');
        $this->assertSinConsultasA($consultas, 'photo_annotations');
    }

    #[Test]
    public function payload_invalido_no_alcanza_la_validacion_con_segmentos_invalidos(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();

        // Segmento inválido + payload inválido: gana el 404 de la
        // resolución manual, nunca el 422.
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/99999/photos", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc/annotations", [])
            ->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/99999/annotations", [])
            ->assertNotFound();

        // Con segmentos válidos el mismo payload sí es 422 — y el orden
        // es resolución → validación.
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [])
            ->assertUnprocessable();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('annotations');

        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_finalizado_conserva_su_contrato_en_cada_punto(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto, $palletPhoto] = $this->palletConJerarquia('done');

        // Resolución manual inválida gana donde la acción la evalúa
        // antes del chequeo de dominio.
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos")->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc/annotations", [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations")
            ->assertOk();

        // En los DELETE la transacción lockea el pallet antes de buscar
        // la foto: `done` gana al segmento de foto inválido (409), como
        // ocurría antes del refactor.
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/abc")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');

        // Con entidades válidas: upload → 409 con compensación del
        // archivo; deletes y anotaciones → 409.
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 50, 50),
        ])->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');

        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$palletPhoto->id}")->assertConflict();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}")->assertConflict();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations", $this->payloadAnotaciones())
            ->assertConflict();

        // La compensación del upload no dejó archivos.
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertNotNull($basePhoto->fresh());
        $this->assertNotNull($palletPhoto->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    // ── Flujos válidos representativos ─────────────────────────────────────

    #[Test]
    public function upload_foto_base_valida_conserva_el_flujo(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base] = $this->palletConJerarquia();

        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100),
            'note' => 'nota',
        ])->assertCreated()
            ->assertJsonStructure(['photo', 'url']);

        $photo = PalletBasePhoto::where('base_id', $base->id)->latest('id')->first();
        $this->assertNotNull($photo);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'base_photo_uploaded',
            'entity_type' => 'pallet_base_photo',
            'entity_id' => $photo->id,
        ]);
    }

    #[Test]
    public function delete_foto_base_valida_conserva_cleanup_durable(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();
        Storage::disk('public')->put($basePhoto->path, 'contenido');

        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Foto eliminada');

        $this->assertDatabaseMissing('pallet_base_photos', ['id' => $basePhoto->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'base_photo_deleted',
            'entity_type' => 'pallet_base_photo',
        ]);
        // La intención durable queda registrada (el job post-commit la
        // procesa con cola sync y la completa).
        $this->assertDatabaseHas('pending_file_deletions', [
            'path' => $basePhoto->path,
            'reason' => 'base_photo_deleted',
        ]);

        // Repetir el DELETE ya no encuentra la foto → 404.
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');
    }

    #[Test]
    public function delete_foto_pallet_valida_conserva_cleanup_durable(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, , , $palletPhoto] = $this->palletConJerarquia();
        Storage::disk('public')->put($palletPhoto->path, 'contenido');

        $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$palletPhoto->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Foto eliminada');

        $this->assertDatabaseMissing('pallet_photos', ['id' => $palletPhoto->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'pallet_photo_deleted',
            'entity_type' => 'pallet_photo',
        ]);
        $this->assertDatabaseHas('pending_file_deletions', [
            'path' => $palletPhoto->path,
            'reason' => 'pallet_photo_deleted',
        ]);
    }

    #[Test]
    public function anotaciones_validas_conservan_reemplazo_atomico_y_auditoria(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, $basePhoto] = $this->palletConJerarquia();
        PhotoAnnotation::create([
            'photo_id' => $basePhoto->id,
            'text' => 'vieja',
            'x' => 1,
            'y' => 1,
        ]);

        $uri = "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$basePhoto->id}/annotations";

        $this->getJson($uri)->assertOk()
            ->assertJsonCount(1, 'annotations')
            ->assertJsonPath('annotations.0.text', 'vieja');

        // El POST reemplaza el conjunto completo de forma atómica.
        $this->postJson($uri, $this->payloadAnotaciones())
            ->assertCreated()
            ->assertJsonPath('message', 'Anotaciones guardadas')
            ->assertJsonCount(2, 'annotations');

        $this->assertSame(2, PhotoAnnotation::where('photo_id', $basePhoto->id)->count());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'photo_annotations_saved',
            'entity_type' => 'photo_annotation',
            'entity_id' => $basePhoto->id,
        ]);

        // Repetir reemplaza de nuevo (idempotente sobre el conjunto).
        $this->postJson($uri, ['annotations' => [['text' => 'unica', 'x' => 5, 'y' => 6]]])
            ->assertCreated()
            ->assertJsonCount(1, 'annotations');
        $this->assertSame(1, PhotoAnnotation::where('photo_id', $basePhoto->id)->count());
    }
}
