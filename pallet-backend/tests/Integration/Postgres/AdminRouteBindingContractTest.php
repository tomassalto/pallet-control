<?php

namespace Tests\Integration\Postgres;

use App\Models\MissingItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H-050 — contrato objetivo en PostgreSQL de los bindings implícitos de
 * la familia admin sobre PKs bigint: {user} (PATCH role, POST
 * toggle-active) y {missingItemRequest} (PATCH review), vía el trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings.
 *
 * Antes del mecanismo común, un segmento no numérico llegaba a la
 * comparación `id = '...'` contra bigint y PostgreSQL abortaba con
 * SQLSTATE[22P02]/[22003] → 500 genérico. Estos tests acreditan que ahora
 * el segmento se rechaza en el binding con ModelNotFoundException (404)
 * sin emitir la consulta por PK: sin excepciones de clase 22 y sin 5xx.
 */
#[Group('postgres')]
class AdminRouteBindingContractTest extends TestCase
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

    // ── {user}: segmentos inválidos → 404, sin query ni 22P02 ──────────────

    #[Test]
    public function user_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        Sanctum::actingAs($this->admin());
        $objetivo = $this->objetivo();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            // La excepción real es ModelNotFoundException: ninguna
            // QueryException de clase 22 llega a producirse.
            $e = $this->excepcionDe(fn () => $this->patchJson("/api/v1/admin/users/{$seg}/role", ['role' => 'admin']));
            $this->assertInstanceOf(ModelNotFoundException::class, $e, "PATCH role {$seg}");

            $this->patchJson("/api/v1/admin/users/{$seg}/role", ['role' => 'admin'])
                ->assertNotFound("PATCH role {$seg}");
            $this->postJson("/api/v1/admin/users/{$seg}/toggle-active")
                ->assertNotFound("POST toggle-active {$seg}");
        }

        // Prueba de no-consulta: ninguna query toca la tabla users.
        $this->assertSinConsultasA($consultas, 'users');

        $this->assertDatabaseHas('users', [
            'id' => $objetivo->id,
            'role' => 'user',
            'is_active' => true,
        ]);
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── {missingItemRequest}: segmentos inválidos → 404 ────────────────────

    #[Test]
    public function missing_item_request_segmento_invalido_devuelve_404_sin_consultar(): void
    {
        Sanctum::actingAs($this->admin());
        $solicitud = $this->solicitud();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $e = $this->excepcionDe(fn () => $this->patchJson("/api/v1/admin/missing-item-requests/{$seg}", ['status' => 'rejected']));
            $this->assertInstanceOf(ModelNotFoundException::class, $e, "PATCH {$seg}");

            $this->patchJson("/api/v1/admin/missing-item-requests/{$seg}", ['status' => 'rejected'])
                ->assertNotFound("PATCH {$seg}");
        }

        $this->assertSinConsultasA($consultas, 'missing_item_requests');

        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $solicitud->id,
            'status' => 'pending_review',
        ]);
        $this->assertSame(0, DB::table('pending_items')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Precedencias conservadas en PostgreSQL ──────────────────────────────

    #[Test]
    public function precedencias_auth_binding_admin_se_conservan(): void
    {
        $objetivo = $this->objetivo();
        $solicitud = $this->solicitud();

        // Anónimo: auth:sanctum gana → 401, con id válido o inválido.
        $this->patchJson('/api/v1/admin/users/abc/role', ['role' => 'admin'])->assertUnauthorized();
        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])->assertUnauthorized();

        // No admin: binding primero (inválido → 404), luego admin (válido → 403).
        Sanctum::actingAs($this->usuario());
        $this->patchJson('/api/v1/admin/users/abc/role', ['role' => 'admin'])->assertNotFound();
        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Se requieren permisos de administrador']);
        $this->patchJson('/api/v1/admin/missing-item-requests/abc', ['status' => 'rejected'])->assertNotFound();
        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'rejected'])
            ->assertForbidden();

        // Inactivo: binding antes que user.active → 404 inválido / 401 válido.
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $this->patchJson('/api/v1/admin/users/abc/role', ['role' => 'admin'])->assertNotFound();
        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])
            ->assertUnauthorized();

        // Id numérico inexistente: el binding sí consulta y no encuentra → 404.
        Sanctum::actingAs($this->admin());
        $this->patchJson('/api/v1/admin/users/99999/role', ['role' => 'admin'])->assertNotFound();
        $this->patchJson('/api/v1/admin/missing-item-requests/99999', ['status' => 'rejected'])->assertNotFound();

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Flujos normales con admin (regresión del trait) ────────────────────

    #[Test]
    public function user_cambio_de_rol_con_admin_revoca_tokens_y_audita(): void
    {
        $admin = $this->admin();
        $objetivo = $this->objetivo();
        $objetivo->createToken('sesion-previa');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$objetivo->id}/role", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(1, DB::table('activity_logs')
            ->where('action', 'user_role_changed')->count());
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

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(1, DB::table('activity_logs')
            ->where('action', 'user_deactivated')->count());
    }

    #[Test]
    public function missing_item_request_aprobacion_y_conflictos_conservan_contrato(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $solicitud = $this->solicitud();
        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertSame(1, DB::table('pending_items')->count());

        // Segunda revisión → 409; pedido done → 409 (D-031).
        $this->patchJson("/api/v1/admin/missing-item-requests/{$solicitud->id}", ['status' => 'rejected'])
            ->assertConflict();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $otra = MissingItemRequest::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 2,
            'status' => 'pending_review',
        ]);
        $order->update(['status' => 'done']);

        $this->patchJson("/api/v1/admin/missing-item-requests/{$otra->id}", ['status' => 'approved'])
            ->assertConflict();

        $this->assertSame(1, DB::table('pending_items')->count());
    }
}
