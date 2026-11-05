<?php

namespace Tests\Feature\Routing;

use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * H-050 — contrato objetivo (SQLite) de los bindings implícitos {order}
 * y {pallet} sobre PKs bigint, vía el trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (D-033).
 *
 * Un segmento que no es un entero no negativo dentro del rango bigint
 * debe responder 404 antes de consultar la tabla — en SQLite el 404 ya
 * salía de la comparación sin error de tipo, pero no impedía la query;
 * en PostgreSQL esa misma comparación abortaba con SQLSTATE[22P02]/
 * [22003] → 500 (espejo en tests/Integration/Postgres).
 *
 * Precedencia vigente que estos tests fijan:
 * auth:sanctum (401) → SubstituteBindings (404) → user.active (401) →
 * has.role (403) → Form Request / controlador.
 *
 * Cobertura: las 18 rutas con {order} y las 22 con {pallet} se ejercitan
 * con segmentos inválidos representativos de cada tipo; el inventario
 * automático y el test estructural garantizan que ninguna ruta futura
 * escape del binding esperado.
 */
class OrderPalletRouteBindingContractTest extends TestCase
{
    use RefreshDatabase;

    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '1.5', '-1', '01', '99999999999999999999', '9223372036854775808'];

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

    // ── Inventario automático y test estructural ───────────────────────────

    #[Test]
    public function las_18_rutas_order_y_22_pallet_estan_cubiertas_por_el_binding_esperado(): void
    {
        $this->assertContains(
            RejectsInvalidIntegerRouteBindings::class,
            class_uses_recursive(Order::class),
            'Order debe usar RejectsInvalidIntegerRouteBindings'
        );
        $this->assertContains(
            RejectsInvalidIntegerRouteBindings::class,
            class_uses_recursive(Pallet::class),
            'Pallet debe usar RejectsInvalidIntegerRouteBindings'
        );

        $rutasOrder = [];
        $rutasPallet = [];

        foreach (Route::getRoutes() as $route) {
            $nombres = $route->parameterNames();
            if (in_array('order', $nombres, true)) {
                $rutasOrder[] = $route;
            }
            if (in_array('pallet', $nombres, true)) {
                $rutasPallet[] = $route;
            }
        }

        $this->assertCount(18, $rutasOrder, 'Se esperaban 18 rutas con el parámetro {order}');
        $this->assertCount(22, $rutasPallet, 'Se esperaban 22 rutas con el parámetro {pallet}');

        foreach ($rutasOrder as $route) {
            $this->assertBindingTipado($route, 'order', Order::class);
        }
        foreach ($rutasPallet as $route) {
            $this->assertBindingTipado($route, 'pallet', Pallet::class);
        }
    }

    /**
     * Toda ruta con {order}/{pallet} debe declarar el parámetro con el
     * type-hint del modelo esperado: es lo que convierte el segmento en
     * binding implícito (y por tanto en el alcance del trait). Una ruta
     * futura con el parámetro escalar, renombrado o ligado a otro modelo
     * rompe este test.
     */
    private function assertBindingTipado($route, string $parametro, string $modelo): void
    {
        $uri = $route->uri();
        $action = $route->getActionName();

        $this->assertNotSame('Closure', $action, "{$uri} no es una acción de controlador");
        $this->assertStringContainsString('@', $action, "{$uri} no resuelve a Class@method");

        [$class, $method] = explode('@', $action);
        $ref = new ReflectionMethod($class, $method);

        foreach ($ref->getParameters() as $parametroRef) {
            if ($parametroRef->getName() !== $parametro) {
                continue;
            }

            $tipo = $parametroRef->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $tipo, "{$uri} :: \${$parametro}");
            $this->assertSame($modelo, $tipo->getName(), "{$uri} :: \${$parametro} debe ser {$modelo}");

            return;
        }

        $this->fail("{$uri} no declara el parámetro \${$parametro}");
    }

    // ── {order}: segmentos inválidos → 404 sin consultar ───────────────────

    #[Test]
    public function order_segmentos_invalidos_devuelven_404_sin_consultar_orders(): void
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

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            // Las 18 rutas con {order}, de tipos distintos.
            $this->getJson("/api/v1/orders/{$seg}")->assertNotFound("GET show {$seg}");
            $this->patchJson("/api/v1/orders/{$seg}", ['status' => 'paused'])->assertNotFound("PATCH {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/attach-pallet", ['pallet_id' => $pallet->id])->assertNotFound("attach-pallet {$seg}");
            $this->getJson("/api/v1/orders/{$seg}/activity-logs")->assertNotFound("activity-logs {$seg}");
            $this->getJson("/api/v1/orders/{$seg}/can-finalize")->assertNotFound("can-finalize {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/finalize")->assertNotFound("finalize {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/reopen")->assertNotFound("reopen {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/import", ['raw' => 'x'])->assertNotFound("import {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/items", ['qty' => 1, 'ean' => '12345678'])->assertNotFound("items {$seg}");
            $this->deleteJson("/api/v1/orders/{$seg}/detach-pallet/{$pallet->id}")->assertNotFound("detach-pallet {$seg}");
            $this->getJson("/api/v1/orders/{$seg}/tickets")->assertNotFound("GET tickets {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/tickets", ['code' => 'T'])->assertNotFound("POST tickets {$seg}");
            $this->deleteJson("/api/v1/orders/{$seg}/tickets/{$ticket->id}")->assertNotFound("DELETE ticket {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/tickets/{$ticket->id}/photos")->assertNotFound("POST photo {$seg}");
            $this->getJson("/api/v1/orders/{$seg}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")->assertNotFound("ocr-status {$seg}");
            $this->getJson("/api/v1/orders/{$seg}/tickets/{$ticket->id}/photos/{$photo->id}/highlights")->assertNotFound("highlights {$seg}");
            $this->postJson("/api/v1/orders/{$seg}/tickets/{$ticket->id}/photos/{$photo->id}/trigger-ocr")->assertNotFound("trigger-ocr {$seg}");
            $this->deleteJson("/api/v1/orders/{$seg}/tickets/{$ticket->id}/photos/{$photo->id}")->assertNotFound("DELETE photo {$seg}");
        }

        // El rechazo ocurre en el binding: ninguna consulta toca `orders`.
        $this->assertSinConsultasA($consultas, 'orders');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function order_invalido_en_rutas_anidadas_tampoco_consulta_los_hijos(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T1']);
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => 'x.webp',
            'original_name' => 'x',
            'order_index' => 0,
        ]);

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {order} es el primer parámetro de la URI: al fallar, los
        // bindings de {ticket} y {photo} (todavía sin protección, deuda
        // H-050) ni siquiera se intentan.
        $this->deleteJson("/api/v1/orders/abc/tickets/{$ticket->id}")->assertNotFound();
        $this->getJson("/api/v1/orders/abc/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")->assertNotFound();
        $this->deleteJson("/api/v1/orders/abc/tickets/{$ticket->id}/photos/{$photo->id}")->assertNotFound();

        $this->assertSinConsultasA($consultas, 'orders');
        $this->assertSinConsultasA($consultas, 'order_tickets');
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── {pallet}: segmentos inválidos → 404 sin consultar ──────────────────

    #[Test]
    public function pallet_segmentos_invalidos_devuelven_404_sin_consultar_pallets(): void
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

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            // Las 22 rutas con {pallet}, de tipos distintos. {base} y
            // {photo} son válidos o escalares manuales: el único
            // segmento inválido es siempre {pallet}.
            $this->getJson("/api/v1/pallets/{$seg}")->assertNotFound("GET show {$seg}");
            $this->patchJson("/api/v1/pallets/{$seg}", ['status' => 'paused'])->assertNotFound("PATCH {$seg}");
            $this->deleteJson("/api/v1/pallets/{$seg}")->assertNotFound("DELETE {$seg}");
            $this->getJson("/api/v1/pallets/{$seg}/activity-logs")->assertNotFound("activity-logs {$seg}");
            $this->getJson("/api/v1/pallets/{$seg}/can-finalize")->assertNotFound("can-finalize {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/finalize")->assertNotFound("finalize {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/reopen")->assertNotFound("reopen {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/attach-order", ['order_code' => '123'])->assertNotFound("attach-order {$seg}");
            $this->getJson("/api/v1/pallets/{$seg}/photos")->assertNotFound("GET photos {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/photos")->assertNotFound("POST photos {$seg}");
            $this->deleteJson("/api/v1/pallets/{$seg}/photos/{$basePhoto->id}")->assertNotFound("DELETE photo {$seg}");
            $this->getJson("/api/v1/pallets/{$seg}/bases")->assertNotFound("GET bases {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/bases")->assertNotFound("POST bases {$seg}");
            $this->patchJson("/api/v1/pallets/{$seg}/bases/{$base->id}", ['name' => 'x'])->assertNotFound("PATCH base {$seg}");
            $this->patchJson("/api/v1/pallets/{$seg}/bases/{$base->id}/adjust-item", ['order_item_id' => 1, 'qty' => 1])->assertNotFound("adjust-item {$seg}");
            $this->deleteJson("/api/v1/pallets/{$seg}/bases/{$base->id}")->assertNotFound("DELETE base {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/bases/{$base->id}/migrate", ['items' => [['order_item_id' => 1, 'qty' => 1]]])->assertNotFound("migrate {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/bases/{$base->id}/photos")->assertNotFound("POST base photo {$seg}");
            $this->deleteJson("/api/v1/pallets/{$seg}/bases/{$base->id}/photos/{$basePhoto->id}")->assertNotFound("DELETE base photo {$seg}");
            $this->getJson("/api/v1/pallets/{$seg}/bases/{$base->id}/photos/{$basePhoto->id}/annotations")->assertNotFound("GET annotations {$seg}");
            $this->postJson("/api/v1/pallets/{$seg}/bases/{$base->id}/photos/{$basePhoto->id}/annotations", [])->assertNotFound("POST annotations {$seg}");
            // La ruta compartida: {pallet} inválido con {order} válido.
            $this->deleteJson("/api/v1/orders/{$order->id}/detach-pallet/{$seg}")->assertNotFound("detach-pallet {$seg}");
        }

        // El rechazo ocurre en el binding: ninguna consulta toca `pallets`.
        $this->assertSinConsultasA($consultas, 'pallets');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_invalido_en_rutas_anidadas_tampoco_consulta_las_bases(): void
    {
        Sanctum::actingAs($this->usuario());
        $pallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($pallet)->create();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {pallet} precede a {base} en la URI: al fallar, el binding
        // implícito de PalletBase (todavía sin protección) no se intenta.
        $this->patchJson("/api/v1/pallets/abc/bases/{$base->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/pallets/abc/bases/{$base->id}")->assertNotFound();
        $this->postJson("/api/v1/pallets/abc/bases/{$base->id}/migrate", ['items' => [['order_item_id' => 1, 'qty' => 1]]])->assertNotFound();

        $this->assertSinConsultasA($consultas, 'pallets');
        $this->assertSinConsultasA($consultas, 'pallet_bases');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Ids numéricos y flujo normal ───────────────────────────────────────

    #[Test]
    public function ids_numericos_inexistentes_consultan_la_tabla_y_devuelven_404(): void
    {
        Sanctum::actingAs($this->usuario());

        $consultas = [];
        $this->capturarConsultas($consultas);

        $this->getJson('/api/v1/orders/99999')->assertNotFound();
        $this->patchJson('/api/v1/orders/99999', ['status' => 'paused'])->assertNotFound();
        $this->getJson('/api/v1/pallets/99999')->assertNotFound();
        $this->deleteJson('/api/v1/pallets/99999')->assertNotFound();

        // Contraste con el segmento inválido: un id bien formado sí emite
        // la consulta por PK y el binding devuelve null → 404.
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "orders"')
        ));
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "pallets"')
        ));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function order_valido_conserva_el_flujo_normal(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $item, $pallet] = $this->pedidoFinalizable();
        $otroPallet = Pallet::factory()->create();

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused');
        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'open'])->assertOk();
        $this->getJson("/api/v1/orders/{$order->id}/activity-logs")->assertOk();
        $this->getJson("/api/v1/orders/{$order->id}/can-finalize")->assertOk()->assertJsonStructure(['can_finalize']);
        $this->getJson("/api/v1/orders/{$order->id}/tickets")->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/tickets", ['code' => 'T-1'])->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/attach-pallet", ['pallet_id' => $otroPallet->id])->assertCreated();
        $this->deleteJson("/api/v1/orders/{$order->id}/detach-pallet/{$otroPallet->id}")->assertOk();
        // El binding resolvió y la validación del controlador corre:
        // payload incompleto → 422, no 404.
        $this->postJson("/api/v1/orders/{$order->id}/import")->assertUnprocessable()->assertJsonValidationErrors(['raw']);

        $this->postJson("/api/v1/orders/{$order->id}/finalize")->assertOk()->assertJsonPath('order.status', 'done');
        $this->postJson("/api/v1/orders/{$order->id}/reopen")->assertOk()->assertJsonPath('order.status', 'open');

        // El alta de ítems va al final: un ítem sin distribuir impediría
        // la finalización que ya se acreditó arriba.
        $this->postJson("/api/v1/orders/{$order->id}/items", ['qty' => 2, 'ean' => '7501234567890'])->assertCreated();
    }

    #[Test]
    public function pallet_valido_conserva_el_flujo_normal(): void
    {
        Sanctum::actingAs($this->usuario());
        [$pallet, $order, $item, $bases] = $this->palletFinalizable();

        $this->getJson("/api/v1/pallets/{$pallet->id}")->assertOk();
        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused');
        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'open'])->assertOk();
        $this->getJson("/api/v1/pallets/{$pallet->id}/activity-logs")->assertOk();
        $this->getJson("/api/v1/pallets/{$pallet->id}/can-finalize")->assertOk();
        $this->getJson("/api/v1/pallets/{$pallet->id}/photos")->assertOk();
        $this->getJson("/api/v1/pallets/{$pallet->id}/bases")->assertOk();
        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$bases->first()->id}", ['name' => 'Base X'])->assertOk();

        $this->postJson("/api/v1/pallets/{$pallet->id}/finalize")->assertOk()->assertJsonPath('pallet.status', 'done');
        $this->postJson("/api/v1/pallets/{$pallet->id}/reopen")->assertOk()->assertJsonPath('pallet.status', 'open');

        $this->postJson("/api/v1/pallets/{$pallet->id}/attach-order", ['order_code' => '777001'])->assertCreated();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}")->assertOk();
        $this->assertDatabaseMissing('pallets', ['id' => $pallet->id]);
    }

    // ── Casos límite del filtro (contrato D-033) ───────────────────────────

    #[Test]
    public function segmentos_limite_del_filtro_conservan_su_semantica(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        // filter_var acepta signo '+' y espacios alrededor del número:
        // se normalizan al id y el binding resuelve → flujo normal.
        foreach (['+' . $order->id, '%20' . $order->id] as $seg) {
            $this->getJson("/api/v1/orders/{$seg}")->assertOk("segmento aceptado {$seg}");
        }
        foreach (['+' . $pallet->id, '%20' . $pallet->id] as $seg) {
            $this->getJson("/api/v1/pallets/{$seg}")->assertOk("segmento aceptado {$seg}");
        }

        // Rechaza ceros a la izquierda → 404 sin consultar.
        foreach (['0' . $order->id, '00' . $order->id] as $seg) {
            $this->getJson("/api/v1/orders/{$seg}")->assertNotFound("segmento rechazado {$seg}");
        }
        foreach (['0' . $pallet->id, '00' . $pallet->id] as $seg) {
            $this->getJson("/api/v1/pallets/{$seg}")->assertNotFound("segmento rechazado {$seg}");
        }
    }

    // ── Precedencias vigentes ──────────────────────────────────────────────

    #[Test]
    public function anonimo_recibe_401_antes_del_binding(): void
    {
        $this->getJson('/api/v1/orders/abc')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->patchJson('/api/v1/orders/abc', ['status' => 'paused'])->assertUnauthorized();
        $this->getJson('/api/v1/pallets/abc')->assertUnauthorized();
        $this->deleteJson('/api/v1/pallets/abc')->assertUnauthorized();
    }

    #[Test]
    public function inactivo_recibe_el_resultado_del_binding_antes_del_401(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        // El binding precede a user.active: inválido → 404, válido → 401.
        $this->getJson('/api/v1/orders/abc')->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->getJson('/api/v1/pallets/abc')->assertNotFound();
        $this->getJson("/api/v1/pallets/{$pallet->id}")->assertUnauthorized();
    }

    #[Test]
    public function sin_rol_recibe_el_binding_antes_del_403_de_escritura(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        // Inválido → 404 antes que has.role; válido → 403.
        $this->patchJson('/api/v1/orders/abc', ['status' => 'paused'])->assertNotFound();
        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])->assertForbidden();
        $this->postJson('/api/v1/orders/abc/finalize')->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/finalize")->assertForbidden();
        $this->patchJson('/api/v1/pallets/abc', ['status' => 'paused'])->assertNotFound();
        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])->assertForbidden();
        $this->deleteJson('/api/v1/pallets/abc')->assertNotFound();
        $this->deleteJson("/api/v1/pallets/{$pallet->id}")->assertForbidden();
    }

    #[Test]
    public function binding_inexistente_precede_al_form_request(): void
    {
        Sanctum::actingAs($this->usuario());

        // Payload inválido + binding inválido/inexistente: gana el 404
        // del binding, nunca el 422 de la validación.
        $this->postJson('/api/v1/orders/abc/items', [])->assertNotFound();
        $this->postJson('/api/v1/orders/99999/items', [])->assertNotFound();
        $this->postJson('/api/v1/orders/abc/attach-pallet', ['pallet_id' => 'abc'])->assertNotFound();
        $this->patchJson('/api/v1/orders/99999', ['status' => 'inexistente'])->assertNotFound();
        $this->postJson('/api/v1/pallets/abc/attach-order', [])->assertNotFound();
        $this->postJson('/api/v1/pallets/99999/attach-order', [])->assertNotFound();
        $this->patchJson('/api/v1/pallets/abc/bases/1', [])->assertNotFound();
    }

    #[Test]
    public function entidad_done_conserva_409_despues_de_resolver_el_binding(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->done()->create();
        $pallet = Pallet::factory()->done()->create();

        // El binding resolvió la entidad real: las reglas de dominio
        // (D-026/D-031) responden 409, no el binding.
        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido ya está finalizado.');
        $this->postJson("/api/v1/orders/{$order->id}/reopen")->assertOk()->assertJsonPath('order.status', 'open');

        $this->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet ya está finalizado.');
        $this->deleteJson("/api/v1/pallets/{$pallet->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de eliminarlo.');
        $this->postJson("/api/v1/pallets/{$pallet->id}/reopen")->assertOk()->assertJsonPath('pallet.status', 'open');
    }
}
