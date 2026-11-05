<?php

namespace Tests\Feature\Pending;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Caracterización del route binding implícito de
 * {pendingItem} en PATCH y DELETE /pending-items/{id} antes de corregir
 * H-050: un segmento no numérico llega a la comparación bigint y en
 * PostgreSQL produce SQLSTATE[22P02] → 500 (en SQLite devuelve 404).
 *
 * El contrato objetivo fija 404 para cualquier identificador que no sea
 * un entero positivo, conservando la precedencia auth → binding → rol →
 * validación observada aquí.
 */
class PendingItemRouteBindingContractTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    private function pendiente(): PendingItem
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        return PendingItem::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 3,
            'status' => 'pending',
            'created_by' => $this->usuario()->id,
        ]);
    }

    // ── Precedencia de auth y rol sobre el binding ─────────────────────────

    #[Test]
    public function anonimo_devuelve_401_en_patch_y_delete(): void
    {
        $this->patchJson('/api/v1/pending-items/abc', ['status' => 'resolved'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->deleteJson('/api/v1/pending-items/abc')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function usuario_inactivo_binding_precede_a_user_active(): void
    {
        // Orden vigente: auth:sanctum (401) → SubstituteBindings (404/500)
        // → user.active (401) → has.role (403). Con id no numérico el
        // inactivo recibe el resultado del binding antes que su 401; con
        // id válido resuelto, recibe 401.
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $pending = $this->pendiente();

        $this->patchJson('/api/v1/pending-items/abc', ['status' => 'resolved'])
            ->assertNotFound();

        $this->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'resolved'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);

        $this->deleteJson('/api/v1/pending-items/abc')
            ->assertNotFound();

        $this->deleteJson("/api/v1/pending-items/{$pending->id}")
            ->assertUnauthorized();
    }

    #[Test]
    public function usuario_sin_rol_recibe_el_binding_antes_del_403(): void
    {
        // El route binding corre antes que has.role: con id no numérico
        // (SQLite) el resultado vigente es 404, no 403.
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/pending-items/abc', ['status' => 'resolved'])
            ->assertNotFound();

        $this->deleteJson('/api/v1/pending-items/abc')
            ->assertNotFound();
    }

    #[Test]
    public function usuario_sin_rol_devuelve_403_con_id_valido(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $pending = $this->pendiente();

        $this->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'resolved'])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Tu cuenta está pendiente de activación. Un administrador debe asignarte un rol antes de poder realizar cambios.',
            ]);

        $this->deleteJson("/api/v1/pending-items/{$pending->id}")
            ->assertForbidden();
    }

    // ── Identificadores inválidos (SQLite: 404; PostgreSQL hoy: 500) ───────

    #[Test]
    public function id_no_numerico_devuelve_404_en_patch_y_delete(): void
    {
        Sanctum::actingAs($this->usuario());

        foreach (['abc', '12abc', '1.5', '-1', '99999999999999999999', '1%20x'] as $id) {
            $this->patchJson("/api/v1/pending-items/{$id}", ['status' => 'resolved'])
                ->assertNotFound("PATCH {$id}");
            $this->deleteJson("/api/v1/pending-items/{$id}")
                ->assertNotFound("DELETE {$id}");
        }

        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function id_numerico_inexistente_devuelve_404(): void
    {
        Sanctum::actingAs($this->usuario());

        $this->patchJson('/api/v1/pending-items/99999', ['status' => 'resolved'])
            ->assertNotFound();

        $this->deleteJson('/api/v1/pending-items/99999')
            ->assertNotFound();
    }

    #[Test]
    public function binding_404_precede_a_la_validacion_del_form_request(): void
    {
        Sanctum::actingAs($this->usuario());

        // Payload inválido + id inexistente: gana el 404 del binding.
        $this->patchJson('/api/v1/pending-items/99999', ['status' => 'invalido'])
            ->assertNotFound();
    }

    #[Test]
    public function segmentos_limite_del_filtro_enteros_conservan_su_semantica(): void
    {
        // El trait común usa filter_var(FILTER_VALIDATE_INT, min_range 0):
        // acepta signo '+' y espacios alrededor del número — se normalizan
        // al id y el binding resuelve, así que un payload inválido llega a
        // la validación (422) en lugar de cortar en el binding (404).
        // Rechaza ceros a la izquierda. Estos casos no estaban
        // caracterizados antes del refactor al trait (H-050, D-033).
        Sanctum::actingAs($this->usuario());
        $pending = $this->pendiente();

        $payloadInvalido = ['status' => 'invalido'];

        foreach (['+' . $pending->id, '%20' . $pending->id] as $seg) {
            $this->patchJson("/api/v1/pending-items/{$seg}", $payloadInvalido)
                ->assertUnprocessable("segmento aceptado {$seg}");
        }

        foreach (['0' . $pending->id, '00' . $pending->id] as $seg) {
            $this->patchJson("/api/v1/pending-items/{$seg}", $payloadInvalido)
                ->assertNotFound("segmento rechazado {$seg}");
        }

        $this->assertDatabaseCount('pending_items', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Ids válidos: comportamiento normal conservado ──────────────────────

    #[Test]
    public function patch_valido_resuelve_y_audita(): void
    {
        Sanctum::actingAs($this->usuario());
        $pending = $this->pendiente();

        $this->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');

        $this->assertDatabaseHas('activity_logs', ['action' => 'pending_item_updated']);
    }

    #[Test]
    public function delete_valido_elimina_y_audita(): void
    {
        Sanctum::actingAs($this->usuario());
        $pending = $this->pendiente();

        $this->deleteJson("/api/v1/pending-items/{$pending->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Pendiente eliminado']);

        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pending_item_deleted']);
    }

    #[Test]
    public function pedido_finalizado_devuelve_409_con_id_valido(): void
    {
        Sanctum::actingAs($this->usuario());
        $pending = $this->pendiente();
        $pending->order->update(['status' => 'done']);

        $this->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'resolved'])
            ->assertConflict()
            ->assertExactJson(['message' => 'El pedido está finalizado. Reabrilo antes de modificarlo.']);

        $this->deleteJson("/api/v1/pending-items/{$pending->id}")
            ->assertConflict();
    }

    #[Test]
    public function payload_invalido_con_id_valido_devuelve_422(): void
    {
        Sanctum::actingAs($this->usuario());
        $pending = $this->pendiente();

        $this->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'invalido'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status', ['validation.in']);
    }
}
