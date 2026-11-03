<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Caracterización del contrato de OrderController::attachPallet antes de
 * migrar la validación inline a un Form Request (H-020) y corregir H-050
 * en `pallet_id`.
 *
 * La migración no cambia el contrato observable: Laravel 12 ya omite la
 * regla `exists` cuando `integer` falló antes
 * (`hasNotFailedPreviousRuleIfPresenceRule`), así que un id no numérico ya
 * devolvía `422` `validation.integer`. `bail` fija esa garantía de forma
 * explícita según el patrón de la familia (H-050).
 */
class AttachPalletValidationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        Http::fake();
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    private function url(Order $order): string
    {
        return "/api/v1/orders/{$order->id}/attach-pallet";
    }

    // ── Autenticación y precedencia ─────────────────────────────────────────

    #[Test]
    public function anonimo_devuelve_401(): void
    {
        $order = Order::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => 1])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function usuario_inactivo_devuelve_401(): void
    {
        $order = Order::factory()->create();
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());

        $this->postJson($this->url($order), ['pallet_id' => 1])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
    }

    #[Test]
    public function usuario_sin_rol_devuelve_403_aun_con_payload_invalido(): void
    {
        $order = Order::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->url($order), ['pallet_id' => 'abc'])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Tu cuenta está pendiente de activación. Un administrador debe asignarte un rol antes de poder realizar cambios.',
            ]);
    }

    #[Test]
    public function pedido_inexistente_devuelve_404_antes_del_403(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders/99999/attach-pallet', ['pallet_id' => 'abc'])
            ->assertNotFound();
    }

    #[Test]
    public function pedido_inexistente_devuelve_404_antes_de_validar(): void
    {
        Sanctum::actingAs($this->usuario());

        $this->postJson('/api/v1/orders/99999/attach-pallet', [])
            ->assertNotFound();
    }

    // ── Validación ─────────────────────────────────────────────────────────

    #[Test]
    public function payload_vacio_devuelve_422_sin_efectos(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();

        $response = $this->postJson($this->url($order), [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.required',
                'errors' => ['pallet_id' => ['validation.required']],
            ]);

        $this->assertSame(['pallet_id'], array_keys($response->json('errors')));
        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function pallet_id_null_devuelve_422(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => null])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.required',
                'errors' => ['pallet_id' => ['validation.required']],
            ]);

        $this->assertDatabaseCount('order_pallet', 0);
    }

    #[Test]
    public function pallet_id_no_numerico_devuelve_422_integer(): void
    {
        // H-050: con `bail` + `integer` antes de `exists`, un id no
        // numérico nunca llega a la consulta. Laravel 12 ya omitía `exists`
        // tras el fallo de `integer`; `bail` lo hace explícito.
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => 'abc'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.integer',
                'errors' => ['pallet_id' => ['validation.integer']],
            ]);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_id_array_devuelve_422_integer(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => [1, 2]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pallet_id', ['validation.integer']);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_id_entero_inexistente_devuelve_422_exists(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => 99999])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.exists',
                'errors' => ['pallet_id' => ['validation.exists']],
            ]);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function pallet_id_string_numerico_existente_asocia(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => (string) $pallet->id])
            ->assertCreated();

        $this->assertDatabaseHas('order_pallet', [
            'order_id' => $order->id,
            'pallet_id' => $pallet->id,
        ]);
    }

    #[Test]
    public function campos_extra_son_ignorados(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        $this->postJson($this->url($order), [
            'pallet_id' => $pallet->id,
            'status' => 'done',
            'order_id' => 999,
            'campo_inventado' => 'x',
        ])->assertCreated();

        $this->assertDatabaseHas('order_pallet', [
            'order_id' => $order->id,
            'pallet_id' => $pallet->id,
        ]);
        $this->assertSame('open', $pallet->fresh()->status);
    }

    // ── Contrato de negocio ────────────────────────────────────────────────

    #[Test]
    public function asociacion_nueva_devuelve_201_con_auditoria_y_telegram(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        $response = $this->postJson($this->url($order), ['pallet_id' => $pallet->id])
            ->assertCreated()
            ->assertJsonPath('message', 'Pedido asociado al pallet.')
            ->assertJsonPath('pallet.id', $pallet->id);

        $this->assertSame(['message', 'pallet', 'pallets'], array_keys($response->json()));
        $this->assertSame([$pallet->id], array_column($response->json('pallets'), 'id'));

        $this->assertDatabaseHas('order_pallet', [
            'order_id' => $order->id,
            'pallet_id' => $pallet->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order_assigned',
            'order_id' => $order->id,
            'pallet_id' => $pallet->id,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'asociado al pallet'));
    }

    #[Test]
    public function asociacion_repetida_devuelve_409_sin_efectos(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);

        $this->postJson($this->url($order), ['pallet_id' => $pallet->id])
            ->assertConflict()
            ->assertExactJson(['message' => 'El pedido ya está asociado a este pallet.']);

        $this->assertDatabaseCount('order_pallet', 1);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function pedido_finalizado_devuelve_409_sin_efectos(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->done()->create();
        $pallet = Pallet::factory()->create();

        $this->postJson($this->url($order), ['pallet_id' => $pallet->id])
            ->assertConflict()
            ->assertExactJson(['message' => 'El pedido está finalizado. Reabrilo antes de modificarlo.']);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function pallet_finalizado_devuelve_409_sin_efectos(): void
    {
        Sanctum::actingAs($this->usuario());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create(['status' => 'done']);

        $this->postJson($this->url($order), ['pallet_id' => $pallet->id])
            ->assertConflict()
            ->assertExactJson(['message' => 'El pallet está finalizado. Reabrilo antes de modificarlo.']);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }
}
