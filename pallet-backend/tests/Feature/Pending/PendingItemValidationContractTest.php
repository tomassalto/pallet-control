<?php

namespace Tests\Feature\Pending;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PendingItemValidationContractTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_SIN_ROL = 'Tu cuenta está pendiente de activación. Un administrador debe asignarte un rol antes de poder realizar cambios.';

    private function usuarioConRol(): User
    {
        return User::factory()->withRole()->create();
    }

    private function usuarioSinRol(): User
    {
        return User::factory()->create();
    }

    private function pendiente(array $attrs = []): PendingItem
    {
        $order = $attrs['order'] ?? Order::factory()->create();
        $orderItem = $attrs['order_item'] ?? OrderItem::factory()->for($order)->create();

        return PendingItem::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'qty_missing' => 2,
            'status' => 'pending',
            'created_by' => $attrs['created_by'] ?? User::factory()->withRole()->create()->id,
        ]);
    }

    // ── a. 401 sin autenticación ────────────────────────────────────────────

    #[Test]
    public function sin_auth_todos_los_endpoints_devuelven_401(): void
    {
        $pendiente = $this->pendiente();

        $this->getJson('/api/v1/pending-items')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->getJson('/api/v1/pending-items/summary')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->postJson('/api/v1/pending-items', [])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->patchJson("/api/v1/pending-items/{$pendiente->id}", [])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->deleteJson("/api/v1/pending-items/{$pendiente->id}")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    // ── b. 403 usuario sin rol ──────────────────────────────────────────────

    #[Test]
    public function sin_rol_escritura_devuelve_403_y_lectura_200(): void
    {
        $pendiente = $this->pendiente();
        Sanctum::actingAs($this->usuarioSinRol());

        $this->postJson('/api/v1/pending-items', [])
            ->assertForbidden()
            ->assertExactJson(['message' => self::MENSAJE_SIN_ROL]);

        // La autorización precede a la validación: body inválido sigue dando 403.
        $this->postJson('/api/v1/pending-items', ['qty_missing' => 'abc'])
            ->assertForbidden()
            ->assertExactJson(['message' => self::MENSAJE_SIN_ROL]);

        $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['status' => 'resolved'])
            ->assertForbidden()
            ->assertExactJson(['message' => self::MENSAJE_SIN_ROL]);

        $this->deleteJson("/api/v1/pending-items/{$pendiente->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => self::MENSAJE_SIN_ROL]);

        $this->getJson('/api/v1/pending-items')->assertOk();
        $this->getJson('/api/v1/pending-items/summary')->assertOk();
    }

    // ── c. Orden 403 vs 404 ─────────────────────────────────────────────────

    #[Test]
    public function sin_rol_patch_sobre_id_inexistente_devuelve_404(): void
    {
        Sanctum::actingAs($this->usuarioSinRol());

        // El route binding (404) corre antes que el middleware has.role (403).
        $this->patchJson('/api/v1/pending-items/9999', ['status' => 'resolved'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No query results for model [App\Models\PendingItem] 9999');
    }

    // ── d. 404 con rol ──────────────────────────────────────────────────────

    #[Test]
    public function con_rol_id_inexistente_devuelve_404_antes_de_validar(): void
    {
        Sanctum::actingAs($this->usuarioConRol());

        // Con APP_DEBUG=true el cuerpo incluye exception/file/line/trace;
        // la parte estable del contrato es `message`.
        $this->patchJson('/api/v1/pending-items/9999', ['status' => 'resolved'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No query results for model [App\Models\PendingItem] 9999');

        $this->deleteJson('/api/v1/pending-items/9999')
            ->assertNotFound()
            ->assertJsonPath('message', 'No query results for model [App\Models\PendingItem] 9999');

        // El binding precede a la validación: body inválido sigue dando 404.
        $this->patchJson('/api/v1/pending-items/9999', ['status' => 'unknown'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No query results for model [App\Models\PendingItem] 9999');
    }

    // ── e. 422 en store ─────────────────────────────────────────────────────

    #[Test]
    public function store_body_vacio_devuelve_422(): void
    {
        Sanctum::actingAs($this->usuarioConRol());

        $response = $this->postJson('/api/v1/pending-items', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.required (and 2 more errors)',
                'errors' => [
                    'order_id' => ['validation.required'],
                    'order_item_id' => ['validation.required'],
                    'qty_missing' => ['validation.required'],
                ],
            ]);

        $this->assertSame(
            ['order_id', 'order_item_id', 'qty_missing'],
            array_keys($response->json('errors'))
        );
    }

    #[Test]
    public function store_qty_missing_invalido_devuelve_422(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();
        $base = ['order_id' => $order->id, 'order_item_id' => $orderItem->id];

        $response = $this->postJson('/api/v1/pending-items', $base + ['qty_missing' => 0])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.min.numeric',
                'errors' => [
                    'qty_missing' => ['validation.min.numeric'],
                ],
            ]);
        $this->assertSame(['qty_missing'], array_keys($response->json('errors')));

        $response = $this->postJson('/api/v1/pending-items', $base + ['qty_missing' => 'abc'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.integer',
                'errors' => [
                    'qty_missing' => ['validation.integer'],
                ],
            ]);
        $this->assertSame(['qty_missing'], array_keys($response->json('errors')));
    }

    #[Test]
    public function store_note_devuelve_422_a_partir_de_1001_chars(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();
        $base = ['order_id' => $order->id, 'order_item_id' => $orderItem->id, 'qty_missing' => 1];

        $this->postJson('/api/v1/pending-items', $base + ['note' => str_repeat('a', 1000)])
            ->assertCreated();

        $response = $this->postJson('/api/v1/pending-items', $base + ['note' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.max.string',
                'errors' => [
                    'note' => ['validation.max.string'],
                ],
            ]);
        $this->assertSame(['note'], array_keys($response->json('errors')));
    }

    #[Test]
    public function store_ids_inexistentes_devuelven_422(): void
    {
        Sanctum::actingAs($this->usuarioConRol());

        $response = $this->postJson('/api/v1/pending-items', [
            'order_id' => 9999,
            'order_item_id' => 9999,
            'qty_missing' => 1,
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.exists (and 1 more error)',
                'errors' => [
                    'order_id' => ['validation.exists'],
                    'order_item_id' => ['validation.exists'],
                ],
            ]);
        $this->assertSame(['order_id', 'order_item_id'], array_keys($response->json('errors')));
    }

    // ── f. Regla de pertenencia al pedido ───────────────────────────────────

    #[Test]
    public function store_item_de_otro_pedido_devuelve_422_sin_efectos(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $order = Order::factory()->create();
        $otroOrder = Order::factory()->create();
        $orderItemAjeno = OrderItem::factory()->for($otroOrder)->create();

        $this->postJson('/api/v1/pending-items', [
            'order_id' => $order->id,
            'order_item_id' => $orderItemAjeno->id,
            'qty_missing' => 1,
        ])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'El producto no pertenece a ese pedido']);

        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function store_validacion_prevalece_sobre_regla_de_pertenencia(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $order = Order::factory()->create();
        $otroOrder = Order::factory()->create();
        $orderItemAjeno = OrderItem::factory()->for($otroOrder)->create();

        $response = $this->postJson('/api/v1/pending-items', [
            'order_id' => $order->id,
            'order_item_id' => $orderItemAjeno->id,
            'qty_missing' => 0,
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.min.numeric',
                'errors' => [
                    'qty_missing' => ['validation.min.numeric'],
                ],
            ]);
        $this->assertSame(['qty_missing'], array_keys($response->json('errors')));

        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── g. Allowlist de asignación masiva ───────────────────────────────────

    #[Test]
    public function store_ignora_campos_fuera_del_allowlist(): void
    {
        $user = $this->usuarioConRol();
        Sanctum::actingAs($user);
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();

        $this->postJson('/api/v1/pending-items', [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'qty_missing' => 3,
            'status' => 'resolved',
            'created_by' => 9999,
            'resolved_by' => 9999,
            'resolved_at' => '2020-01-01 00:00:00',
        ])->assertCreated();

        $this->assertDatabaseHas('pending_items', [
            'order_item_id' => $orderItem->id,
            'status' => 'pending',
            'created_by' => $user->id,
            'resolved_by' => null,
            'resolved_at' => null,
        ]);
    }

    #[Test]
    public function update_ignora_campos_fuera_del_allowlist(): void
    {
        $user = $this->usuarioConRol();
        Sanctum::actingAs($user);
        $pendiente = $this->pendiente(['created_by' => $user->id]);
        $otroOrder = Order::factory()->create();
        $otroItem = OrderItem::factory()->for($otroOrder)->create();

        $this->patchJson("/api/v1/pending-items/{$pendiente->id}", [
            'note' => 'nota valida',
            'order_id' => $otroOrder->id,
            'order_item_id' => $otroItem->id,
            'created_by' => 9999,
            'resolved_by' => 9999,
        ])->assertOk();

        $this->assertDatabaseHas('pending_items', [
            'id' => $pendiente->id,
            'order_id' => $pendiente->order_id,
            'order_item_id' => $pendiente->order_item_id,
            'created_by' => $user->id,
            'resolved_by' => null,
            'note' => 'nota valida',
        ]);
    }

    // ── h. 422 en update ────────────────────────────────────────────────────

    #[Test]
    public function update_status_desconocido_devuelve_422(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $pendiente = $this->pendiente();

        $response = $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['status' => 'unknown'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.in',
                'errors' => [
                    'status' => ['validation.in'],
                ],
            ]);
        $this->assertSame(['status'], array_keys($response->json('errors')));
    }

    #[Test]
    public function update_qty_missing_cero_devuelve_422(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $pendiente = $this->pendiente();

        $response = $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['qty_missing' => 0])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.min.numeric',
                'errors' => [
                    'qty_missing' => ['validation.min.numeric'],
                ],
            ]);
        $this->assertSame(['qty_missing'], array_keys($response->json('errors')));
    }

    #[Test]
    public function update_note_largo_devuelve_422_y_null_es_valido(): void
    {
        Sanctum::actingAs($this->usuarioConRol());
        $pendiente = $this->pendiente();

        $response = $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['note' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.max.string',
                'errors' => [
                    'note' => ['validation.max.string'],
                ],
            ]);
        $this->assertSame(['note'], array_keys($response->json('errors')));

        $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['note' => null])
            ->assertOk();
        $this->assertNull($pendiente->fresh()->note);
    }

    // ── i. Resolver un pendiente ya resuelto ────────────────────────────────

    #[Test]
    public function resolver_un_resuelto_conserva_resolved_at_y_resolved_by(): void
    {
        $user = $this->usuarioConRol();
        $resolvedBy = User::factory()->withRole()->create();
        Sanctum::actingAs($user);

        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();
        $resueltoEn = Carbon::parse('2024-01-15 10:00:00');

        $pendiente = PendingItem::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'qty_missing' => 2,
            'status' => 'resolved',
            'created_by' => $user->id,
            'resolved_by' => $resolvedBy->id,
            'resolved_at' => $resueltoEn,
        ]);

        Carbon::setTestNow('2025-06-01 12:00:00');
        try {
            $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['status' => 'resolved'])
                ->assertOk();

            $fresh = $pendiente->fresh();
            $this->assertSame('resolved', $fresh->status);
            $this->assertSame($resolvedBy->id, $fresh->resolved_by);
            $this->assertEquals($resueltoEn->toDateTimeString(), $fresh->resolved_at->toDateTimeString());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function volver_a_pending_limpia_resolved_at_y_resolved_by(): void
    {
        $user = $this->usuarioConRol();
        Sanctum::actingAs($user);

        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();

        $pendiente = PendingItem::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'qty_missing' => 2,
            'status' => 'resolved',
            'created_by' => $user->id,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        $this->patchJson("/api/v1/pending-items/{$pendiente->id}", ['status' => 'pending'])
            ->assertOk();

        $fresh = $pendiente->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->resolved_by);
    }

    // ── j. 422 en index ─────────────────────────────────────────────────────

    #[Test]
    public function index_filtros_invalidos_devuelven_422(): void
    {
        Sanctum::actingAs($this->usuarioConRol());

        $response = $this->getJson('/api/v1/pending-items?page=0')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.min.numeric',
                'errors' => [
                    'page' => ['validation.min.numeric'],
                ],
            ]);
        $this->assertSame(['page'], array_keys($response->json('errors')));

        $response = $this->getJson('/api/v1/pending-items?per_page=abc')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.integer',
                'errors' => [
                    'per_page' => ['validation.integer'],
                ],
            ]);
        $this->assertSame(['per_page'], array_keys($response->json('errors')));

        $response = $this->getJson('/api/v1/pending-items?status=unknown')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.in',
                'errors' => [
                    'status' => ['validation.in'],
                ],
            ]);
        $this->assertSame(['status'], array_keys($response->json('errors')));
    }

    // ── k. Happy paths ──────────────────────────────────────────────────────

    #[Test]
    public function store_201_devuelve_formato_completo(): void
    {
        $user = $this->usuarioConRol();
        Sanctum::actingAs($user);
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();

        $response = $this->postJson('/api/v1/pending-items', [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'qty_missing' => 5,
            'note' => 'faltan bultos',
        ])->assertCreated();

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
            'note',
            'status',
            'resolved_at',
            'resolved_by_name',
            'created_by_name',
            'created_at',
        ], array_keys($response->json()));

        $response->assertJsonPath('status', 'pending')
            ->assertJsonPath('qty_missing', 5)
            ->assertJsonPath('note', 'faltan bultos')
            ->assertJsonPath('created_by_name', $user->name)
            ->assertJsonPath('resolved_at', null)
            ->assertJsonPath('resolved_by_name', null);
    }

    #[Test]
    public function update_200_devuelve_formato_completo(): void
    {
        $user = $this->usuarioConRol();
        Sanctum::actingAs($user);
        $pendiente = $this->pendiente(['created_by' => $user->id]);

        $response = $this->patchJson("/api/v1/pending-items/{$pendiente->id}", [
            'qty_missing' => 7,
            'note' => 'actualizado',
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
            'note',
            'status',
            'resolved_at',
            'resolved_by_name',
            'created_by_name',
            'created_at',
        ], array_keys($response->json()));

        $response->assertJsonPath('qty_missing', 7)
            ->assertJsonPath('note', 'actualizado');
    }
}
