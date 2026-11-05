<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * H-050 — contrato objetivo en PostgreSQL de los 4 bindings implícitos
 * {base} de PalletBaseController sobre la PK bigint de PalletBase, vía
 * el trait App\Models\Concerns\RejectsInvalidIntegerRouteBindings
 * (D-033).
 *
 * Antes del trait, un segmento no numérico llegaba a la comparación
 * `id = '...'` contra bigint y PostgreSQL abortaba con
 * SQLSTATE[22P02]/[22003] → 500 genérico (PATCH bases/{base} quedó
 * caracterizado en BigintRouteBindingCharacterizationTest; el resto de
 * la familia comparte el mismo binding implícito). Estos tests acreditan
 * que ahora el segmento se rechaza en el binding con
 * ModelNotFoundException (404) sin emitir la consulta por PK: sin
 * excepciones de clase 22 y sin 5xx.
 *
 * La matriz exhaustiva segmento × ruta vive en el espejo SQLite
 * (tests/Feature/Routing/PalletBaseRouteBindingContractTest); acá se
 * cubren rutas representativas de cada tipo en ambos motores.
 */
#[Group('postgres')]
class PalletBaseRouteBindingContractTest extends TestCase
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
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    /**
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
     * @return array{Pallet, PalletBase, Order, OrderItem}
     */
    private function palletConBaseYStock(int $qty = 10): array
    {
        [$pallet, $base, $order, $item] = $this->palletConBase();
        $base->orderItems()->attach($item->id, ['qty' => $qty]);

        return [$pallet, $base, $order, $item];
    }

    /**
     * Ejecuta el request sin el handler global para observar la excepción
     * real del binding (restaura el handler al salir).
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

    // ── {base}: segmentos inválidos → 404, sin query ni 22P02 ──────────────

    #[Test]
    public function base_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'PATCH update' => fn (string $s) => $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$s}", ['name' => 'x']),
            'PATCH adjust-item' => fn (string $s) => $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$s}/adjust-item", [
                'order_item_id' => $item->id, 'qty' => 1,
            ]),
            'DELETE destroy' => fn (string $s) => $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$s}"),
            'POST migrate' => fn (string $s) => $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$s}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
            ]),
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

        // Prueba de no-consulta: el binding de {pallet} sí consultó
        // pallets; el de {base} nunca tocó pallet_bases.
        $this->assertConConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');

        // Cero efectos laterales.
        $this->assertNotNull($base->fresh());
        $this->assertSame(0, DB::table('pallet_base_order_items')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
    }

    #[Test]
    public function pallet_invalido_frena_antes_de_consultar_bases(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {pallet} precede a {base}: al fallar, el binding de PalletBase
        // no se intenta en ninguna de las cuatro rutas.
        foreach ([
            fn () => $this->patchJson("/api/v1/pallets/abc/bases/{$base->id}", ['name' => 'x']),
            fn () => $this->patchJson("/api/v1/pallets/abc/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id, 'qty' => 1,
            ]),
            fn () => $this->deleteJson("/api/v1/pallets/abc/bases/{$base->id}"),
            fn () => $this->postJson("/api/v1/pallets/abc/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
            ]),
        ] as $request) {
            $e = $this->excepcionDe($request);
            $this->assertInstanceOf(ModelNotFoundException::class, $e);
            $request()->assertNotFound();
        }

        $this->assertSinConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');
        $this->assertSame(0, DB::table('activity_logs')->count());
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
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}", ['name' => 'x'])->assertNotFound();
            $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}")->assertNotFound();
        }

        // Un id bien formado sí emite la consulta por PK (bigint válido)
        // y el binding devuelve null → 404, nunca 22P02/22003.
        $this->assertConConsultasA($consultas, 'pallet_bases');
        $this->assertNotNull($base->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Pertenencia pallet/base (ownership) ────────────────────────────────

    #[Test]
    public function base_de_otro_pallet_conserva_404_sin_operar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$palletA] = $this->palletConBase();
        [$palletB, $baseAjena, , $itemB] = $this->palletConBase();
        $baseAjena->orderItems()->attach($itemB->id, ['qty' => 4]);

        // El binding resuelve la base por id global; la pertenencia la
        // rechaza el controlador/servicio con 404 — no se opera sobre la
        // base ajena.
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

        $this->assertNotNull($baseAjena->fresh());
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $baseAjena->id, 'order_item_id' => $itemB->id, 'qty' => 4,
        ]);
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Flujos válidos representativos ─────────────────────────────────────

    #[Test]
    public function ids_validos_conservan_el_flujo_normal(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBaseYStock(10);

        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'renombrada'])
            ->assertOk()
            ->assertJsonPath('name', 'renombrada');

        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id, 'qty' => 5,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('new_qty', 5);

        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 2]],
        ])->assertOk()
            ->assertJsonPath('message', 'Productos migrados correctamente')
            ->assertJsonStructure(['destination_pallet_id']);

        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Base eliminada');
        $this->assertDatabaseMissing('pallet_bases', ['id' => $base->id]);

        $this->assertGreaterThan(0, DB::table('activity_logs')->count());
    }

    // ── Precedencias conservadas en PostgreSQL ─────────────────────────────

    #[Test]
    public function precedencias_auth_binding_rol_se_conservan(): void
    {
        $user = $this->usuario();
        [$pallet, $base] = $this->palletConBase();

        // Anónimo: auth:sanctum gana → 401, con id válido o inválido.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")->assertUnauthorized();

        // Sin rol en escritura: binding primero (inválido → 404),
        // luego has.role (válido → 403).
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", [])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/abc")->assertNotFound();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")->assertForbidden();

        // Inactivo: binding antes que user.active → 404 inválido / 401 válido.
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", [])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    #[Test]
    public function payload_invalido_y_entidad_finalizada_conservan_su_contrato(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base, , $item] = $this->palletConBase('done');
        $base->orderItems()->attach($item->id, ['qty' => 4]);

        // Base inválida gana al 409 de dominio y al 422 del Form Request.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", ['name' => 'x'])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/99999/adjust-item", [])->assertNotFound();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/migrate", [])->assertNotFound();

        // Con bindings válidos: payload inválido → 422; dominio → 409.
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_item_id', 'qty']);
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
            'items' => [['order_item_id' => $item->id, 'qty' => 1]],
        ])->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de migrar productos.');

        $this->assertNotNull($base->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Casos límite del filtro (contrato D-033) ───────────────────────────

    #[Test]
    public function segmentos_limite_del_filtro_conservan_su_semantica(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $base] = $this->palletConBase();

        // filter_var acepta '+' y espacios alrededor del número.
        foreach (['+' . $base->id, '%20' . $base->id] as $seg) {
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}", ['name' => 'editada ' . $seg])
                ->assertOk("base aceptada {$seg}");
        }

        // Rechaza ceros a la izquierda → 404.
        foreach (['0' . $base->id, '00' . $base->id] as $seg) {
            $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$seg}", ['name' => 'x'])
                ->assertNotFound("base rechazada {$seg}");
        }
    }
}
