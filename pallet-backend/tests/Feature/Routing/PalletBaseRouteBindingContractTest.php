<?php

namespace Tests\Feature\Routing;

use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * H-050 — contrato objetivo (SQLite) de los 4 bindings implícitos {base}
 * de PalletBaseController sobre la PK bigint de PalletBase, vía el trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (D-033).
 *
 * Un segmento que no es un entero no negativo dentro del rango bigint
 * debe responder 404 antes de consultar la tabla — en SQLite el 404 ya
 * salía de la comparación sin error de tipo, pero emitía la query;
 * en PostgreSQL esa misma comparación abortaba con SQLSTATE[22P02]/
 * [22003] → 500 (espejo en tests/Integration/Postgres).
 *
 * Precedencia vigente que estos tests fijan:
 * auth:sanctum (401) → SubstituteBindings (404) → user.active (401) →
 * has.role (403) → Form Request / controlador.
 *
 * Cobertura: PATCH update, PATCH adjust-item, DELETE destroy y POST
 * migrate se ejercitan con segmentos inválidos representativos de cada
 * tipo; el inventario automático y el test estructural garantizan que
 * ninguna ruta futura escape del binding esperado. Las otras 4 rutas
 * con {base} (fotos y anotaciones de base) resuelven el segmento a mano
 * con el escalar $baseId y quedan fuera de esta familia — ver la matriz
 * de AUDITORIA.md H-050.
 */
class PalletBaseRouteBindingContractTest extends TestCase
{
    use RefreshDatabase;

    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '1.5', '-1', '01', '99999999999999999999', '9223372036854775808'];

    private const SEGMENTOS_NUMERICOS = ['0', '99999', '9223372036854775807'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    /**
     * Pallet + base propia + pedido asociado + item, listos para las
     * cuatro rutas de la familia.
     *
     * @return array{Pallet, PalletBase, Order, OrderItem}
     */
    private function palletConBase(string $palletStatus = 'open'): array
    {
        $pallet = Pallet::factory()->create(['status' => $palletStatus]);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $order = Order::factory()->create();
        $pallet->orders()->attach($order->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        return [$pallet, $base, $order, $item];
    }

    /**
     * Igual que palletConBase pero con el item ya asignado a la base:
     * migrate necesita stock real en el origen.
     *
     * @return array{Pallet, PalletBase, Order, OrderItem}
     */
    private function palletConBaseYStock(int $qty = 10): array
    {
        [$pallet, $base, $order, $item] = $this->palletConBase();
        $base->orderItems()->attach($item->id, ['qty' => $qty]);

        return [$pallet, $base, $order, $item];
    }

    /**
     * Las cuatro rutas de la familia con su payload representativo.
     * $segBase es el segmento crudo que ocupa el lugar de {base}.
     *
     * @return array<string, array{string, string, array}>
     */
    private function rutasDeLaFamilia(Pallet $pallet, string $segBase, OrderItem $item): array
    {
        return [
            'PATCH update' => [
                'patch',
                "/api/v1/pallets/{$pallet->id}/bases/{$segBase}",
                ['name' => 'Base editada'],
            ],
            'PATCH adjust-item' => [
                'patch',
                "/api/v1/pallets/{$pallet->id}/bases/{$segBase}/adjust-item",
                ['order_item_id' => $item->id, 'qty' => 1],
            ],
            'DELETE destroy' => [
                'delete',
                "/api/v1/pallets/{$pallet->id}/bases/{$segBase}",
                [],
            ],
            'POST migrate' => [
                'post',
                "/api/v1/pallets/{$pallet->id}/bases/{$segBase}/migrate",
                ['items' => [['order_item_id' => $item->id, 'qty' => 1]]],
            ],
        ];
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
     * Toda ruta de la familia debe declarar el parámetro con el
     * type-hint del modelo esperado: es lo que convierte el segmento en
     * binding implícito (y por tanto en el alcance del trait). Una ruta
     * futura con el parámetro escalar, renombrado o ligado a otro
     * modelo rompe este test.
     */
    private function assertBindingTipado($route, string $parametro, string $modelo): void
    {
        $uri = $route->uri();

        $this->assertSame(
            $modelo,
            $this->tipoParametro($route, $parametro),
            "{$uri} :: \${$parametro} debe ser {$modelo}"
        );
    }

    // ── Inventario automático y test estructural ───────────────────────────

    #[Test]
    public function las_rutas_base_estan_cubiertas_por_el_binding_esperado(): void
    {
        $this->assertContains(
            RejectsInvalidIntegerRouteBindings::class,
            class_uses_recursive(PalletBase::class),
            'PalletBase debe usar RejectsInvalidIntegerRouteBindings'
        );

        $rutasTipadas = [];
        $rutasManuales = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('base', $route->parameterNames(), true)) {
                continue;
            }

            if ($this->tipoParametro($route, 'base') === PalletBase::class) {
                $rutasTipadas[] = $route;
            } else {
                $rutasManuales[] = $route;
            }
        }

        // Exactamente cuatro bindings implícitos {base} → PalletBase.
        $this->assertCount(4, $rutasTipadas, 'Se esperaban 4 rutas con {base} → PalletBase');

        $acciones = [];
        foreach ($rutasTipadas as $route) {
            $this->assertBindingTipado($route, 'base', PalletBase::class);
            $this->assertBindingTipado($route, 'pallet', Pallet::class);
            $acciones[] = $route->getActionName();
        }

        sort($acciones);
        $this->assertSame([
            'App\Http\Controllers\Api\PalletBaseController@adjustItem',
            'App\Http\Controllers\Api\PalletBaseController@destroy',
            'App\Http\Controllers\Api\PalletBaseController@migrate',
            'App\Http\Controllers\Api\PalletBaseController@update',
        ], $acciones);

        // Las otras 4 rutas con {base} (fotos y anotaciones de base)
        // resuelven el segmento a mano con el escalar $baseId: quedan
        // fuera del trait y siguen como deuda de H-050.
        $this->assertCount(4, $rutasManuales, 'Se esperaban 4 rutas con {base} de resolución manual');
        foreach ($rutasManuales as $route) {
            $this->assertNull(
                $this->tipoParametro($route, 'base'),
                "{$route->uri()} :: {base} manual no debe tener type-hint de modelo"
            );
        }
    }

    // ── {base}: segmentos inválidos → 404 sin consultar ────────────────────

    #[Test]
    public function base_segmentos_invalidos_devuelven_404_sin_consultar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            foreach ($this->rutasDeLaFamilia($pallet, $seg, $item) as $contexto => [$verbo, $uri, $payload]) {
                $this->{$verbo . 'Json'}($uri, $payload)->assertNotFound("{$contexto} {$seg}");
            }
        }

        // El rechazo ocurre en el binding: ninguna consulta toca
        // pallet_bases (el binding de {pallet} sí consultó pallets).
        $this->assertConConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');

        // Cero efectos laterales: base intacta, asignaciones y auditoría
        // vacías aunque se haya intentado DELETE y migrate.
        $this->assertNotNull($base->fresh());
        $this->assertSame(1, PalletBase::count());
        $this->assertSame(0, DB::table('pallet_base_order_items')->count());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    #[Test]
    public function pallet_invalido_frena_antes_de_consultar_bases(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {pallet} precede a {base} en la URI: al fallar, el binding de
        // PalletBase no se intenta en ninguna de las cuatro rutas.
        $this->patchJson("/api/v1/pallets/abc/bases/{$base->id}", ['name' => 'x'])->assertNotFound();
        $this->patchJson("/api/v1/pallets/abc/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id, 'qty' => 1,
        ])->assertNotFound();
        $this->deleteJson("/api/v1/pallets/abc/bases/{$base->id}")->assertNotFound();
        $this->postJson("/api/v1/pallets/abc/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 1]],
        ])->assertNotFound();

        $this->assertSinConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Ids numéricos: contraste con el segmento inválido ─────────────────

    #[Test]
    public function segmentos_numericos_inexistentes_consultan_y_devuelven_404(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_NUMERICOS as $seg) {
            // '0' y los enteros fuera de rango pasan la guarda del trait
            // (son enteros ≥ 0 válidos para filter_var) y sí consultan.
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}", ['name' => 'x'])
                ->assertNotFound("PATCH bases/{$seg}");
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/adjust-item", [
                'order_item_id' => $item->id, 'qty' => 1,
            ])->assertNotFound("adjust-item {$seg}");
            $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}")->assertNotFound("DELETE bases/{$seg}");
            $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
            ])->assertNotFound("migrate {$seg}");
        }

        // Contraste con el segmento inválido: un id bien formado emite
        // la consulta por PK y el binding devuelve null → 404.
        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertNotNull($base->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Casos límite del filtro (contrato D-033) ───────────────────────────

    #[Test]
    public function segmentos_limite_del_filtro_conservan_su_semantica(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base] = $this->palletConBase();

        // filter_var acepta signo '+' y espacios alrededor del número:
        // se normalizan al id y el binding resuelve → flujo normal.
        foreach (['+' . $base->id, '%20' . $base->id] as $seg) {
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}", ['name' => 'editada ' . $seg])
                ->assertOk("base aceptada {$seg}");
        }

        // Rechaza ceros a la izquierda → 404 sin consultar.
        foreach (['0' . $base->id, '00' . $base->id] as $seg) {
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}", ['name' => 'x'])
                ->assertNotFound("base rechazada {$seg}");
        }
    }

    // ── Pertenencia pallet/base (ownership) ────────────────────────────────

    #[Test]
    public function base_de_otro_pallet_conserva_404_sin_operar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$palletA] = $this->palletConBase();
        [$palletB, $baseAjena, $orderB, $itemB] = $this->palletConBase();
        $baseAjena->orderItems()->attach($itemB->id, ['qty' => 4]);

        // El binding resuelve la base por id global; la pertenencia la
        // verifica el controlador/servicio y responde 404 — una base
        // válida nunca opera bajo un pallet incorrecto.
        $this->patchJson("/api/v1/pallets/{$palletA->id}/bases/{$baseAjena->id}", ['name' => 'robada'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada');
        $this->patchJson("/api/v1/pallets/{$palletA->id}/bases/{$baseAjena->id}/adjust-item", [
            'order_item_id' => $itemB->id, 'qty' => 2,
        ])->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada en este pallet');
        $this->deleteJson("/api/v1/pallets/{$palletA->id}/bases/{$baseAjena->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada');
        $this->postJson("/api/v1/pallets/{$palletA->id}/bases/{$baseAjena->id}/migrate", [
            'items' => [['order_item_id' => $itemB->id, 'qty' => 1]],
        ])->assertNotFound()
            ->assertJsonPath('message', 'Base no encontrada en este pallet');

        // La base ajena quedó intacta: nombre, stock y existencia.
        $this->assertNotSame('robada', $baseAjena->fresh()->name);
        $this->assertNotNull($baseAjena->fresh());
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $baseAjena->id, 'order_item_id' => $itemB->id, 'qty' => 4,
        ]);
        $this->assertSame(2, PalletBase::count());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Flujos válidos representativos (R-01/R-02/R-03 + destroy) ──────────

    #[Test]
    public function update_base_valida_conserva_el_flujo(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base] = $this->palletConBase();

        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", [
            'name' => 'Base renombrada',
            'note' => 'nota nueva',
        ])->assertOk()
            ->assertJsonPath('name', 'Base renombrada');

        $this->assertDatabaseHas('pallet_bases', ['id' => $base->id, 'name' => 'Base renombrada']);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'base_updated',
            'entity_type' => 'pallet_base',
            'entity_id' => $base->id,
        ]);
    }

    #[Test]
    public function adjust_item_valido_conserva_el_flujo(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id,
            'qty' => 4,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('old_qty', 0)
            ->assertJsonPath('new_qty', 4);

        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id, 'qty' => 4,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'item_base_qty_adjusted',
            'entity_type' => 'order_item',
            'entity_id' => $item->id,
        ]);
    }

    #[Test]
    public function migrate_valido_conserva_el_flujo(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBaseYStock(10);

        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 4]],
        ])->assertOk()
            ->assertJsonPath('message', 'Productos migrados correctamente')
            ->assertJsonStructure(['destination_pallet_id']);

        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id, 'qty' => 6,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'item_migrated',
            'entity_type' => 'order_item',
            'entity_id' => $item->id,
        ]);
    }

    #[Test]
    public function destroy_valido_conserva_el_flujo(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base] = $this->palletConBase();

        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Base eliminada');

        $this->assertDatabaseMissing('pallet_bases', ['id' => $base->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'base_deleted',
            'entity_type' => 'pallet_base',
            'entity_id' => $base->id,
        ]);
        // Sin fotos, no hay intenciones de borrado.
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    // ── Precedencias vigentes ──────────────────────────────────────────────

    #[Test]
    public function anonimo_recibe_401_antes_del_binding(): void
    {
        foreach (['abc', '1'] as $seg) {
            $this->patchJson("/api/v1/pallets/1/bases/{$seg}", [])->assertUnauthorized();
            $this->patchJson("/api/v1/pallets/1/bases/{$seg}/adjust-item", [])->assertUnauthorized();
            $this->deleteJson("/api/v1/pallets/1/bases/{$seg}")->assertUnauthorized();
            $this->postJson("/api/v1/pallets/1/bases/{$seg}/migrate", [])->assertUnauthorized();
        }
    }

    #[Test]
    public function inactivo_recibe_el_resultado_del_binding_antes_del_401(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        [$pallet, $base, , $item] = $this->palletConBase();

        // El binding precede a user.active: inválido → 404, válido → 401.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", [])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/abc")->assertNotFound();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")->assertUnauthorized();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/migrate", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 1]],
        ])->assertUnauthorized();
    }

    #[Test]
    public function sin_rol_recibe_el_binding_antes_del_403_de_escritura(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$pallet, $base, , $item] = $this->palletConBase();

        // Las cuatro rutas son escrituras: inválido → 404 antes que
        // has.role; válido → 403.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", [])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x'])->assertForbidden();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc/adjust-item", [])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id, 'qty' => 1,
        ])->assertForbidden();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/abc")->assertNotFound();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")->assertForbidden();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/migrate", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 1]],
        ])->assertForbidden();
    }

    #[Test]
    public function payload_invalido_no_alcanza_la_validacion_con_base_invalida(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        // Payload inválido + base inválida/inexistente: gana el 404 del
        // binding, nunca el 422 del Form Request.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", ['items' => 'no-es-array'])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/99999", ['items' => 'no-es-array'])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc/adjust-item", [])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/99999/adjust-item", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/migrate", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/99999/migrate", [])->assertNotFound();

        // Con bindings válidos el mismo payload inválido sí es 422.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['items' => 'no-es-array'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_item_id', 'qty']);
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertNotNull($base->fresh());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_finalizado_conserva_409_despues_de_resolver_los_bindings(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase('done');
        $base->orderItems()->attach($item->id, ['qty' => 4]);

        // Los bindings resolvieron las entidades reales: las reglas de
        // dominio (D-031) responden 409, no el binding.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id, 'qty' => 2,
        ])->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificar sus bases.');
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 1]],
        ])->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de migrar productos.');

        // Con base inválida el 404 del binding sigue ganando al 409.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/abc")->assertNotFound();

        // Sin efectos: base y asignación intactas, sin auditoría.
        $this->assertNotNull($base->fresh());
        $this->assertSame('done', $pallet->fresh()->status);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id, 'qty' => 4,
        ]);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
