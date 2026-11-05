<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H-050 — contrato objetivo en PostgreSQL de los bindings implícitos
 * {order} y {pallet} sobre PKs bigint, vía el trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (D-033).
 *
 * Antes del mecanismo común, un segmento no numérico llegaba a la
 * comparación `id = '...'` contra bigint y PostgreSQL abortaba con
 * SQLSTATE[22P02]/[22003] → 500 genérico (evidencia previa en
 * BigintRouteBindingCharacterizationTest). Estos tests acreditan que
 * ahora el segmento se rechaza en el binding con
 * ModelNotFoundException (404) sin emitir la consulta por PK: sin
 * excepciones de clase 22 y sin 5xx.
 *
 * La matriz exhaustiva segmento × ruta vive en el espejo SQLite
 * (tests/Feature/Routing/OrderPalletRouteBindingContractTest); acá se
 * cubren rutas representativas de cada tipo en ambos motores.
 */
#[Group('postgres')]
class OrderPalletRouteBindingContractTest extends TestCase
{
    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '1.5', '-1', '01', '99999999999999999999', '9223372036854775808'];

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

    private function pedidoFinalizable(string $status = 'open'): array
    {
        $order = Order::factory()->create(['status' => $status]);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 10]);

        return [$order, $item, $pallet, $base];
    }

    private function palletFinalizable(string $status = 'open'): array
    {
        $pallet = Pallet::factory()->create(['status' => $status]);
        $order = Order::factory()->create();
        $pallet->orders()->attach($order->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        $bases = PalletBase::factory()->for($pallet, 'pallet')->count(2)->create();
        foreach ($bases as $base) {
            PalletBasePhoto::create([
                'base_id' => $base->id,
                'path' => "test/base-{$base->id}.webp",
                'original_name' => 'foto.webp',
            ]);
            $base->orderItems()->attach($item->id, ['qty' => 5]);
        }

        return [$pallet, $order, $item, $bases];
    }

    /**
     * Ejecuta el request sin el handler global para observar la excepción
     * real del binding (restaura el handler al salir).
     */
    private function excepcionDe(callable $request): ?\Throwable
    {
        $this->withoutExceptionHandling();
        try {
            $request();

            return null;
        } catch (\Throwable $e) {
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

    // ── {order}: segmentos inválidos → 404, sin query ni 22P02 ─────────────

    #[Test]
    public function order_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T1']);
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => 'x.webp',
            'original_name' => 'x',
            'order_index' => 0,
        ]);

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'GET show' => fn (string $s) => $this->getJson("/api/v1/orders/{$s}"),
            'PATCH status' => fn (string $s) => $this->patchJson("/api/v1/orders/{$s}", ['status' => 'paused']),
            'POST finalize' => fn (string $s) => $this->postJson("/api/v1/orders/{$s}/finalize"),
            'POST reopen' => fn (string $s) => $this->postJson("/api/v1/orders/{$s}/reopen"),
            'POST attach-pallet' => fn (string $s) => $this->postJson("/api/v1/orders/{$s}/attach-pallet", ['pallet_id' => $pallet->id]),
            'POST import' => fn (string $s) => $this->postJson("/api/v1/orders/{$s}/import", ['raw' => 'x']),
            'POST items' => fn (string $s) => $this->postJson("/api/v1/orders/{$s}/items", ['qty' => 1, 'ean' => '12345678']),
            'DELETE detach-pallet' => fn (string $s) => $this->deleteJson("/api/v1/orders/{$s}/detach-pallet/{$pallet->id}"),
            'GET tickets' => fn (string $s) => $this->getJson("/api/v1/orders/{$s}/tickets"),
            'DELETE ticket' => fn (string $s) => $this->deleteJson("/api/v1/orders/{$s}/tickets/{$ticket->id}"),
            'GET ocr-status' => fn (string $s) => $this->getJson("/api/v1/orders/{$s}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status"),
            'GET activity-logs' => fn (string $s) => $this->getJson("/api/v1/orders/{$s}/activity-logs"),
            'GET can-finalize' => fn (string $s) => $this->getJson("/api/v1/orders/{$s}/can-finalize"),
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

        // Prueba de no-consulta: ninguna query toca la tabla orders.
        $this->assertSinConsultasA($consultas, 'orders');
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── {pallet}: segmentos inválidos → 404 ────────────────────────────────

    #[Test]
    public function pallet_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        Sanctum::actingAs($this->usuario());
        $pallet = Pallet::factory()->create();
        $order = Order::factory()->create();
        $base = PalletBase::factory()->for($pallet)->create();
        $basePhoto = PalletBasePhoto::create([
            'base_id' => $base->id,
            'path' => 'p.webp',
            'original_name' => 'p',
        ]);

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'GET show' => fn (string $s) => $this->getJson("/api/v1/pallets/{$s}"),
            'PATCH status' => fn (string $s) => $this->patchJson("/api/v1/pallets/{$s}", ['status' => 'paused']),
            'DELETE' => fn (string $s) => $this->deleteJson("/api/v1/pallets/{$s}"),
            'POST finalize' => fn (string $s) => $this->postJson("/api/v1/pallets/{$s}/finalize"),
            'POST reopen' => fn (string $s) => $this->postJson("/api/v1/pallets/{$s}/reopen"),
            'POST attach-order' => fn (string $s) => $this->postJson("/api/v1/pallets/{$s}/attach-order", ['order_code' => '123']),
            'GET photos' => fn (string $s) => $this->getJson("/api/v1/pallets/{$s}/photos"),
            'GET bases' => fn (string $s) => $this->getJson("/api/v1/pallets/{$s}/bases"),
            'PATCH base' => fn (string $s) => $this->patchJson("/api/v1/pallets/{$s}/bases/{$base->id}", ['name' => 'x']),
            'PATCH adjust-item' => fn (string $s) => $this->patchJson("/api/v1/pallets/{$s}/bases/{$base->id}/adjust-item", ['order_item_id' => 1, 'qty' => 1]),
            'POST migrate' => fn (string $s) => $this->postJson("/api/v1/pallets/{$s}/bases/{$base->id}/migrate", ['items' => [['order_item_id' => 1, 'qty' => 1]]]),
            'GET annotations' => fn (string $s) => $this->getJson("/api/v1/pallets/{$s}/bases/{$base->id}/photos/{$basePhoto->id}/annotations"),
            'DELETE detach-pallet' => fn (string $s) => $this->deleteJson("/api/v1/orders/{$order->id}/detach-pallet/{$s}"),
            'GET activity-logs' => fn (string $s) => $this->getJson("/api/v1/pallets/{$s}/activity-logs"),
            'GET can-finalize' => fn (string $s) => $this->getJson("/api/v1/pallets/{$s}/can-finalize"),
        ];

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            foreach ($rutas as $contexto => $request) {
                $e = $this->excepcionDe(fn () => $request($seg));
                $this->assertInstanceOf(ModelNotFoundException::class, $e, "{$contexto} {$seg}");

                $request($seg)->assertNotFound("{$contexto} {$seg}");
            }
        }

        $this->assertSinConsultasA($consultas, 'pallets');
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Ids numéricos y flujos normales ────────────────────────────────────

    #[Test]
    public function ids_numericos_inexistentes_consultan_y_devuelven_404(): void
    {
        Sanctum::actingAs($this->usuario());

        $consultas = [];
        $this->capturarConsultas($consultas);

        $this->getJson('/api/v1/orders/99999')->assertNotFound();
        $this->patchJson('/api/v1/orders/99999', ['status' => 'paused'])->assertNotFound();
        $this->postJson('/api/v1/orders/99999/finalize')->assertNotFound();
        $this->getJson('/api/v1/pallets/99999')->assertNotFound();
        $this->deleteJson('/api/v1/pallets/99999')->assertNotFound();

        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "orders"')
        ));
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "pallets"')
        ));
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    #[Test]
    public function ids_validos_conservan_el_flujo_normal(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order] = $this->pedidoFinalizable();
        [$pallet] = $this->palletFinalizable();

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])->assertOk();
        $this->getJson("/api/v1/orders/{$order->id}/can-finalize")->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/finalize")->assertOk()->assertJsonPath('order.status', 'done');
        $this->postJson("/api/v1/orders/{$order->id}/reopen")->assertOk()->assertJsonPath('order.status', 'open');

        $this->getJson("/api/v1/pallets/{$pallet->id}")->assertOk();
        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])->assertOk();
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases")->assertOk();
        $this->postJson("/api/v1/pallets/{$pallet->id}/finalize")->assertOk()->assertJsonPath('pallet.status', 'done');
        $this->postJson("/api/v1/pallets/{$pallet->id}/reopen")->assertOk()->assertJsonPath('pallet.status', 'open');
    }

    // ── Precedencias conservadas en PostgreSQL ─────────────────────────────

    #[Test]
    public function precedencias_auth_binding_rol_se_conservan(): void
    {
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        // Anónimo: auth:sanctum gana → 401, con id válido o inválido.
        $this->getJson('/api/v1/orders/abc')->assertUnauthorized();
        $this->getJson("/api/v1/orders/{$order->id}")->assertUnauthorized();
        $this->getJson('/api/v1/pallets/abc')->assertUnauthorized();

        // Sin rol en escritura: binding primero (inválido → 404),
        // luego has.role (válido → 403).
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/v1/orders/abc', ['status' => 'paused'])->assertNotFound();
        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])->assertForbidden();
        $this->patchJson('/api/v1/pallets/abc', ['status' => 'paused'])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])->assertForbidden();

        // Inactivo: binding antes que user.active → 404 inválido / 401 válido.
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $this->getJson('/api/v1/orders/abc')->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}")->assertUnauthorized();
        $this->getJson('/api/v1/pallets/abc')->assertNotFound();
        $this->getJson("/api/v1/pallets/{$pallet->id}")->assertUnauthorized();

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    #[Test]
    public function entidad_done_conserva_409_despues_de_resolver_el_binding(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->done()->create();
        $pallet = Pallet::factory()->done()->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido ya está finalizado.');
        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet ya está finalizado.');

        $this->assertSame(0, DB::table('activity_logs')->count());
    }
}
