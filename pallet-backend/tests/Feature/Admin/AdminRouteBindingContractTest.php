<?php

namespace Tests\Feature\Admin;

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
 * H-050 — contrato objetivo de los bindings implícitos de la familia
 * admin sobre PKs bigint: {user} (PATCH role, POST toggle-active) y
 * {missingItemRequest} (PATCH review).
 *
 * Un segmento que no es un entero no negativo dentro del rango bigint
 * debe responder 404 antes de consultar la tabla — en SQLite el 404 ya
 * salía de la comparación sin error de tipo, pero no impedía la query;
 * en PostgreSQL esa misma comparación abortaba con SQLSTATE[22P02]/
 * [22003] → 500. El mecanismo común es el trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (D-033).
 *
 * Precedencia vigente que estos tests fijan:
 * auth:sanctum (401) → SubstituteBindings (404) → user.active (401) →
 * admin (403) → Form Request (422).
 */
class AdminRouteBindingContractTest extends TestCase
{
    use RefreshDatabase;

    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '1.5', '-1', '01', '99999999999999999999', '9223372036854775808'];

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    private function objetivo(): User
    {
        return User::factory()->withRole()->create();
    }

    private function solicitud(): MissingItemRequest
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        return MissingItemRequest::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 3,
            'status' => 'pending_review',
        ]);
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

    // ── {user}: segmentos inválidos ────────────────────────────────────────

    #[Test]
    public function user_segmento_invalido_devuelve_404_sin_consultar_ni_tocar(): void
    {
        $admin = $this->admin();
        $objetivo = $this->objetivo();
        Sanctum::actingAs($admin);

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $this->patchJson("/api/v1/admin/users/{$seg}/role", ['role' => 'admin'])
                ->assertNotFound("PATCH role {$seg}");
            $this->postJson("/api/v1/admin/users/{$seg}/toggle-active")
                ->assertNotFound("POST toggle-active {$seg}");
        }

        // El rechazo ocurre en el binding: ninguna consulta toca `users`.
        $this->assertSinConsultasA($consultas, 'users');

        // Cero cambios y cero auditoría.
        $this->assertDatabaseHas('users', [
            'id' => $objetivo->id,
            'role' => 'user',
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function user_id_numerico_inexistente_consulta_y_devuelve_404(): void
    {
        Sanctum::actingAs($this->admin());

        $consultas = [];
        $this->capturarConsultas($consultas);

        $this->patchJson('/api/v1/admin/users/99999/role', ['role' => 'admin'])
            ->assertNotFound();
        $this->postJson('/api/v1/admin/users/99999/toggle-active')
            ->assertNotFound();

        // Contraste con el caso inválido: un id bien formado sí emite la
        // consulta por PK y el binding devuelve null → 404.
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "users"')
        ));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function user_valido_sin_admin_devuelve_403_tras_resolver_el_binding(): void
    {
        $objetivo = $this->objetivo();
        Sanctum::actingAs($this->usuario());

        // Binding inválido → 404 antes que el middleware admin.
        $this->patchJson('/api/v1/admin/users/abc/role', ['role' => 'admin'])
            ->assertNotFound();
        $this->postJson('/api/v1/admin/users/abc/toggle-active')
            ->assertNotFound();

        // Id numérico inexistente → 404 (el binding no encuentra fila).
        $this->patchJson('/api/v1/admin/users/99999/role', ['role' => 'admin'])
            ->assertNotFound();

        // Id válido existente → el binding resuelve y admin responde 403.
        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Se requieren permisos de administrador']);
        $this->postJson("/api/v1/admin/users/{$objetivo->id}/toggle-active")
            ->assertForbidden()
            ->assertExactJson(['error' => 'Se requieren permisos de administrador']);
    }

    #[Test]
    public function user_anonimo_recibe_401_antes_del_binding(): void
    {
        $this->patchJson('/api/v1/admin/users/abc/role', ['role' => 'admin'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->postJson('/api/v1/admin/users/abc/toggle-active')
            ->assertUnauthorized();
    }

    #[Test]
    public function user_inactivo_recibe_el_binding_antes_del_401(): void
    {
        $objetivo = $this->objetivo();
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());

        // El binding precede a user.active: inválido → 404, válido → 401.
        $this->patchJson('/api/v1/admin/users/abc/role', ['role' => 'admin'])
            ->assertNotFound();
        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
    }

    #[Test]
    public function user_segmentos_limite_del_filtro_conservan_su_semantica(): void
    {
        // Contrato del mecanismo común (filter_var entero ≥ 0): acepta
        // signo '+' y espacios alrededor del número — se normalizan al id
        // y el binding resuelve, así que el payload inválido llega a la
        // validación → 422, nunca 404. Rechaza ceros a la izquierda.
        // El actor es admin para que la petición supere el middleware y
        // llegue al validate() del controlador.
        $objetivo = $this->objetivo();
        Sanctum::actingAs($this->admin());

        $payloadInvalido = ['role' => 'no-existe'];

        foreach (['+' . $objetivo->id, '%20' . $objetivo->id] as $seg) {
            $this->patchJson("/api/v1/admin/users/{$seg}/role", $payloadInvalido)
                ->assertUnprocessable("segmento aceptado {$seg}");
        }

        foreach (['0' . $objetivo->id, '00' . $objetivo->id] as $seg) {
            $this->patchJson("/api/v1/admin/users/{$seg}/role", $payloadInvalido)
                ->assertNotFound("segmento rechazado {$seg}");
        }
    }

    // ── {user}: flujo normal con admin ─────────────────────────────────────

    #[Test]
    public function user_cambio_de_rol_con_admin_conserva_revocacion_y_auditoria(): void
    {
        $admin = $this->admin();
        $objetivo = $this->objetivo();
        $objetivo->createToken('sesion-previa');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');

        // D-007: el cambio de rol revoca todas las sesiones del usuario.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_role_changed',
            'entity_type' => 'user',
            'entity_id' => $objetivo->id,
        ]);
    }

    #[Test]
    public function user_toggle_active_con_admin_desactiva_y_revoca_tokens(): void
    {
        $admin = $this->admin();
        $objetivo = $this->objetivo();
        $objetivo->createToken('sesion-previa');
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/users/{$objetivo->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_deactivated',
            'entity_type' => 'user',
            'entity_id' => $objetivo->id,
        ]);
    }

    // ── {missingItemRequest}: segmentos inválidos ──────────────────────────

    #[Test]
    public function missing_item_request_segmento_invalido_devuelve_404_sin_consultar(): void
    {
        Sanctum::actingAs($this->admin());
        $solicitud = $this->solicitud();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $this->patchJson("/api/v1/admin/missing-item-requests/{$seg}", ['status' => 'rejected'])
                ->assertNotFound("PATCH {$seg}");
        }

        $this->assertSinConsultasA($consultas, 'missing_item_requests');

        // Cero pendientes derivados y cero logs; la solicitud intacta.
        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $solicitud->id,
            'status' => 'pending_review',
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function missing_item_request_id_inexistente_consulta_y_devuelve_404(): void
    {
        Sanctum::actingAs($this->admin());

        $consultas = [];
        $this->capturarConsultas($consultas);

        $this->patchJson('/api/v1/admin/missing-item-requests/99999', ['status' => 'rejected'])
            ->assertNotFound();

        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "missing_item_requests"')
        ));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function missing_item_request_valido_sin_admin_devuelve_403_tras_resolver(): void
    {
        $solicitud = $this->solicitud();
        Sanctum::actingAs($this->usuario());

        // Inválido → 404 antes que admin; inexistente → 404; válido → 403.
        $this->patchJson('/api/v1/admin/missing-item-requests/abc', ['status' => 'rejected'])
            ->assertNotFound();
        $this->patchJson('/api/v1/admin/missing-item-requests/99999', ['status' => 'rejected'])
            ->assertNotFound();
        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'rejected'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Se requieren permisos de administrador']);
    }

    #[Test]
    public function missing_item_request_anonimo_e_inactivo_respetan_la_precedencia(): void
    {
        $solicitud = $this->solicitud();

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'rejected'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());

        $this->patchJson('/api/v1/admin/missing-item-requests/abc', ['status' => 'rejected'])
            ->assertNotFound();
        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'rejected'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
    }

    #[Test]
    public function missing_item_request_binding_404_precede_a_la_validacion(): void
    {
        Sanctum::actingAs($this->admin());

        // Payload inválido + binding inexistente: gana el 404 del binding.
        $this->patchJson('/api/v1/admin/missing-item-requests/99999', ['status' => 'invalido'])
            ->assertNotFound();
        $this->patchJson('/api/v1/admin/missing-item-requests/abc', ['status' => 'invalido'])
            ->assertNotFound();
    }

    // ── {missingItemRequest}: flujo normal con admin ───────────────────────

    #[Test]
    public function missing_item_request_aprobacion_con_admin_crea_pendiente_y_audita(): void
    {
        $admin = $this->admin();
        $solicitud = $this->solicitud();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('reviewed_by_name', $admin->name);

        $this->assertDatabaseCount('pending_items', 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'missing_item_request_approved',
            'entity_id' => $solicitud->id,
        ]);
    }

    #[Test]
    public function missing_item_request_segunda_revision_devuelve_409(): void
    {
        $admin = $this->admin();
        $solicitud = $this->solicitud();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'approved'])
            ->assertOk();

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'rejected'])
            ->assertConflict()
            ->assertJsonPath('message', 'La solicitud ya fue revisada.');

        $this->assertDatabaseCount('pending_items', 1);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    #[Test]
    public function missing_item_request_pedido_finalizado_devuelve_409(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $solicitud = MissingItemRequest::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 3,
            'status' => 'pending_review',
        ]);
        $order->update(['status' => 'done']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'approved'])
            ->assertConflict()
            ->assertExactJson(['message' => 'El pedido está finalizado. Reabrilo antes de modificarlo.']);

        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
