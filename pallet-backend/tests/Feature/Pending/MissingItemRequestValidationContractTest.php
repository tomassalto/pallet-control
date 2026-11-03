<?php

namespace Tests\Feature\Pending;

use App\Models\MissingItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Caracterización del contrato de MissingItemRequestController antes de
 * migrar la validación inline a Form Requests (H-020) y corregir H-050
 * en `order_item_id`.
 *
 * Las diferencias deliberadas posteriores quedan señaladas en cada test:
 * - `status` de index pasa de filtro crudo a `in:` (422 en inválidos).
 * - `order_item_id` no numérico pasa de `validation.exists` a
 *   `validation.integer` (H-050).
 */
class MissingItemRequestValidationContractTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function pedidoConItem(): array
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        return [$order, $item];
    }

    private function urlPublica(Order $order): string
    {
        return "/api/v1/public/orders/{$order->public_token}/missing-items";
    }

    private function solicitudPendiente(OrderItem $item, array $attrs = []): MissingItemRequest
    {
        $solicitud = MissingItemRequest::create([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'qty_missing' => 3,
            'status' => 'pending_review',
            ...$attrs,
        ]);

        if (isset($attrs['created_at'])) {
            $solicitud->timestamps = false;
            $solicitud->created_at = $attrs['created_at'];
            $solicitud->save();
        }

        return $solicitud;
    }

    // ── INDEX ───────────────────────────────────────────────────────────────

    #[Test]
    public function index_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/v1/admin/missing-item-requests')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function index_usuario_no_admin_devuelve_403(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());

        $this->getJson('/api/v1/admin/missing-item-requests')
            ->assertForbidden()
            ->assertExactJson(['error' => 'Se requieren permisos de administrador']);
    }

    #[Test]
    public function index_sin_filtros_devuelve_todas_con_formato_y_orden(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [$order, $item] = $this->pedidoConItem();

        $aprobada = $this->solicitudPendiente($item, [
            'status' => 'approved',
            'created_at' => now()->subDay(),
        ]);
        $pendienteVieja = $this->solicitudPendiente($item, [
            'created_at' => now()->subHour(),
        ]);
        $pendienteNueva = $this->solicitudPendiente($item);

        $response = $this->getJson('/api/v1/admin/missing-item-requests')->assertOk();

        // pending_review primero, luego created_at desc.
        $this->assertSame(
            [$pendienteNueva->id, $pendienteVieja->id, $aprobada->id],
            array_column($response->json(), 'id')
        );
        $this->assertSame([
            'id',
            'order_id',
            'order_code',
            'order_item_id',
            'description',
            'ean',
            'image_url',
            'units_per_bulto',
            'original_qty',
            'qty_missing',
            'requester_name',
            'note',
            'status',
            'reviewed_at',
            'reviewed_by_name',
            'created_at',
        ], array_keys($response->json('0')));
    }

    #[Test]
    public function index_filtro_status_valido_filtra(): void
    {
        Sanctum::actingAs($this->admin());
        [$order, $item] = $this->pedidoConItem();

        $this->solicitudPendiente($item);
        $aprobada = $this->solicitudPendiente($item, ['status' => 'approved']);

        $response = $this->getJson('/api/v1/admin/missing-item-requests?status=approved')
            ->assertOk();

        $this->assertSame([$aprobada->id], array_column($response->json(), 'id'));
    }

    #[Test]
    public function index_filtro_status_invalido_devuelve_lista_vacia(): void
    {
        // Contrato actual (pre-Form Request): el filtro se lee sin validar y
        // un valor desconocido simplemente no coincide con ninguna fila.
        Sanctum::actingAs($this->admin());
        [$order, $item] = $this->pedidoConItem();
        $this->solicitudPendiente($item);

        $this->getJson('/api/v1/admin/missing-item-requests?status=no_existe')
            ->assertOk()
            ->assertExactJson([]);
    }

    // ── STORE público ───────────────────────────────────────────────────────

    #[Test]
    public function store_caso_feliz_crea_la_solicitud(): void
    {
        [$order, $item] = $this->pedidoConItem();

        $response = $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => 2,
            'requester_name' => 'Cliente Mostrador',
            'note' => 'faltan cajas',
        ])->assertCreated()
            ->assertJsonPath('message', 'Solicitud recibida');

        $id = $response->json('id');
        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 2,
            'requester_name' => 'Cliente Mostrador',
            'note' => 'faltan cajas',
            'status' => 'pending_review',
        ]);
    }

    #[Test]
    public function store_payload_vacio_devuelve_422_con_orden_de_claves(): void
    {
        [$order] = $this->pedidoConItem();

        $response = $this->postJson($this->urlPublica($order), [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.required (and 1 more error)',
                'errors' => [
                    'order_item_id' => ['validation.required'],
                    'qty_missing' => ['validation.required'],
                ],
            ]);
        $this->assertSame(['order_item_id', 'qty_missing'], array_keys($response->json('errors')));

        $this->assertDatabaseCount('missing_item_requests', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function store_order_item_id_ausente_devuelve_422(): void
    {
        [$order] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), ['qty_missing' => 1])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.required',
                'errors' => ['order_item_id' => ['validation.required']],
            ]);

        $this->assertDatabaseCount('missing_item_requests', 0);
    }

    #[Test]
    public function store_order_item_id_no_numerico_devuelve_422_exists_en_sqlite(): void
    {
        // SQLite es flexible con tipos: `exists` no encuentra 'abc' y devuelve
        // 422 validation.exists. En PostgreSQL esta misma entrada producía
        // SQLSTATE[22P02] → 500 (H-050). Tras la corrección, ambos motores
        // devuelven 422 validation.integer.
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Caracteriza el comportamiento SQLite previo a H-050.');
        }

        [$order] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => 'abc',
            'qty_missing' => 1,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.exists',
                'errors' => ['order_item_id' => ['validation.exists']],
            ]);

        $this->assertDatabaseCount('missing_item_requests', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function store_order_item_id_string_numerico_crea(): void
    {
        [$order, $item] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => (string) $item->id,
            'qty_missing' => 1,
        ])->assertCreated();

        $this->assertDatabaseHas('missing_item_requests', ['order_item_id' => $item->id]);
    }

    #[Test]
    public function store_order_item_id_entero_inexistente_devuelve_422_exists(): void
    {
        [$order] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => 99999,
            'qty_missing' => 1,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.exists',
                'errors' => ['order_item_id' => ['validation.exists']],
            ]);

        $this->assertDatabaseCount('missing_item_requests', 0);
    }

    #[Test]
    public function store_item_de_otro_pedido_devuelve_422_sin_errors(): void
    {
        [$order] = $this->pedidoConItem();
        $otroItem = OrderItem::factory()->create();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $otroItem->id,
            'qty_missing' => 1,
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'El producto no pertenece a este pedido']);

        $this->assertDatabaseCount('missing_item_requests', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function store_token_desconocido_devuelve_404_antes_de_validar(): void
    {
        $this->postJson('/api/v1/public/orders/'.str_repeat('a', 48).'/missing-items', [])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Pedido no encontrado']);
    }

    #[Test]
    public function store_token_malformado_devuelve_404(): void
    {
        $this->postJson('/api/v1/public/orders/corto/missing-items', [])
            ->assertNotFound();
    }

    #[Test]
    public function store_pedido_finalizado_devuelve_409_sin_efectos(): void
    {
        $order = Order::factory()->done()->create();
        $item = OrderItem::factory()->for($order)->create();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => 1,
        ])->assertConflict()
            ->assertExactJson(['message' => 'El pedido está finalizado. Reabrilo antes de modificarlo.']);

        $this->assertDatabaseCount('missing_item_requests', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function store_qty_superior_a_la_del_item_devuelve_422(): void
    {
        [$order, $item] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => $item->qty + 1,
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'La cantidad no puede superar la del pedido']);

        $this->assertDatabaseCount('missing_item_requests', 0);
    }

    #[Test]
    public function store_solicitud_reciente_devuelve_409(): void
    {
        [$order, $item] = $this->pedidoConItem();
        $this->solicitudPendiente($item);

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => 1,
        ])->assertConflict()
            ->assertExactJson(['message' => 'Ya existe una solicitud reciente para este producto']);

        $this->assertDatabaseCount('missing_item_requests', 1);
    }

    #[Test]
    public function store_campos_extra_son_ignorados(): void
    {
        [$order, $item] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => 1,
            'status' => 'approved',
            'order_id' => 999,
            'campo_inventado' => 'x',
        ])->assertCreated();

        $this->assertDatabaseHas('missing_item_requests', [
            'order_id' => $order->id,
            'status' => 'pending_review',
        ]);
    }

    #[Test]
    public function store_note_largo_devuelve_422(): void
    {
        [$order, $item] = $this->pedidoConItem();

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => 1,
            'note' => str_repeat('a', 501),
        ])->assertUnprocessable()
            ->assertJsonPath('errors.note', ['validation.max.string']);

        $this->assertDatabaseCount('missing_item_requests', 0);
    }

    #[Test]
    public function store_rate_limit_devuelve_429_sin_desplazar_la_validacion(): void
    {
        // throttle:public-order-write → 5 req/min por token+IP. El middleware
        // corre antes que el controlador, así que agota el cupo aunque el
        // payload valide.
        [$order, $item] = $this->pedidoConItem();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->urlPublica($order), [
                'order_item_id' => $item->id,
                'qty_missing' => 1,
            ]);
        }

        $this->postJson($this->urlPublica($order), [
            'order_item_id' => $item->id,
            'qty_missing' => 1,
        ])->assertStatus(429);
    }

    // ── UPDATE / revisión ───────────────────────────────────────────────────

    #[Test]
    public function update_sin_auth_devuelve_401(): void
    {
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'approved'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function update_no_admin_devuelve_403(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'approved'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Se requieren permisos de administrador']);
    }

    #[Test]
    public function update_id_inexistente_devuelve_404_antes_del_403(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());

        $this->patchJson('/api/v1/admin/missing-item-requests/99999', ['status' => 'approved'])
            ->assertNotFound();
    }

    #[Test]
    public function update_aprobacion_devuelve_formato_completo(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item, [
            'requester_name' => 'Cliente',
            'note' => 'nota',
        ]);

        $response = $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", [
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame([
            'id',
            'order_id',
            'order_code',
            'order_item_id',
            'description',
            'ean',
            'image_url',
            'units_per_bulto',
            'original_qty',
            'qty_missing',
            'requester_name',
            'note',
            'status',
            'reviewed_at',
            'reviewed_by_name',
            'created_at',
        ], array_keys($response->json()));

        $response->assertJsonPath('status', 'approved')
            ->assertJsonPath('reviewed_by_name', $admin->name)
            ->assertJsonPath('requester_name', 'Cliente');
    }

    #[Test]
    public function update_rechazo_devuelve_200_sin_pendiente(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", [
            'status' => 'rejected',
        ])->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'missing_item_request_rejected']);
    }

    #[Test]
    public function update_status_invalido_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin());
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", [
            'status' => 'pending_review',
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.in',
                'errors' => ['status' => ['validation.in']],
            ]);

        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $solicitud->id,
            'status' => 'pending_review',
            'reviewed_at' => null,
        ]);
        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function update_sin_status_devuelve_422_required(): void
    {
        Sanctum::actingAs($this->admin());
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.required',
                'errors' => ['status' => ['validation.required']],
            ]);
    }

    #[Test]
    public function update_campos_extra_son_ignorados(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [$order, $item] = $this->pedidoConItem();
        $solicitud = $this->solicitudPendiente($item);
        $otroAdmin = User::factory()->admin()->create();

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", [
            'status' => 'rejected',
            'reviewed_by' => $otroAdmin->id,
            'qty_missing' => 99,
        ])->assertOk();

        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $solicitud->id,
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'qty_missing' => 3,
        ]);
    }

    #[Test]
    public function update_pedido_finalizado_devuelve_409_y_solicitud_intacta(): void
    {
        Sanctum::actingAs($this->admin());
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create();
        $solicitud = $this->solicitudPendiente($item);
        $order->update(['status' => 'done']);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", [
            'status' => 'approved',
        ])->assertConflict()
            ->assertExactJson(['message' => 'El pedido está finalizado. Reabrilo antes de modificarlo.']);

        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $solicitud->id,
            'status' => 'pending_review',
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
