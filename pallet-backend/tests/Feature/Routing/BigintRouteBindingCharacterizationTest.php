<?php

namespace Tests\Feature\Routing;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H-050 — CARACTERIZACIÓN (SQLite) de los route bindings y parámetros
 * escalares sobre PKs bigint que SIGUEN vulnerables tras la corrección
 * por familias.
 *
 * Qué registra esta suite:
 * - SQLite compara el segmento crudo contra la PK sin error de tipo:
 *   `id = 'abc'` no encuentra fila y el framework responde 404. Este
 *   resultado NO es evidencia de seguridad para PostgreSQL.
 * - En PostgreSQL la misma comparación aborta con SQLSTATE[22P02]/[22003]
 *   y el handler global responde 500 (evidencia en
 *   `tests/Integration/Postgres/BigintRouteBindingCharacterizationTest.php`,
 *   grupo `postgres`). El 500 acreditado allí es el DEFECTO vigente, no el
 *   contrato deseado — temporal hasta que cada familia se corrija.
 * - `StorageController::{deletePallet,deleteOrder}(int $id)` es la
 *   excepción: el TypeError de coerción PHP produce 500 en AMBOS motores.
 *
 * Familias ya corregidas con contrato propio y fuera de esta
 * caracterización: {pendingItem} (`PendingItemRouteBindingContractTest`),
 * la familia admin {user}/{missingItemRequest}, el núcleo {order}/
 * {pallet} (`AdminRouteBindingContractTest` y
 * `OrderPalletRouteBindingContractTest`), {ticket}/{photo} de tickets
 * (`TicketPhotoRouteBindingContractTest`), los 4 bindings implícitos
 * {base} de PalletBaseController
 * (`PalletBaseRouteBindingContractTest`) y las 5 resoluciones manuales
 * {base}/{photo} de fotos y anotaciones
 * (`ManualPhotoParameterContractTest`), todos gobernados por el trait
 * `RejectsInvalidIntegerRouteBindings` (D-033). {customer}, {item} y el
 * {id} int de Admin Storage siguen acá. Ver AUDITORIA.md H-050.
 */
class BigintRouteBindingCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const SEGMENTOS_INVALIDOS = ['abc', '12abc', '-1', '1.5', '99999999999999999999'];

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ── Bindings implícitos desprotegidos (SQLite: 404) ────────────────────

    #[Test]
    public function bindings_implicitos_lectura_devuelven_404_en_sqlite(): void
    {
        Sanctum::actingAs($this->usuario());
        Customer::factory()->create();

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $this->getJson("/api/v1/customers/{$seg}")->assertNotFound("GET customers/{$seg}");
        }

        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function binding_implicito_escritura_devuelve_404_antes_del_form_request(): void
    {
        Sanctum::actingAs($this->usuario());

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            // UpdateOrderItemRequest existe, pero el binding corre antes:
            // nunca llega a la validación ni a OrderItemService.
            $this->patchJson("/api/v1/order-items/{$seg}", ['qty' => 5])
                ->assertNotFound("PATCH order-items/{$seg}");
        }

        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Parámetro {id} con type-hint int: 500 por TypeError en AMBOS motores ─

    #[Test]
    public function storage_delete_con_type_hint_int_devuelve_500_por_type_error(): void
    {
        Sanctum::actingAs($this->admin());

        // 'abc' y '12abc' no son strings numéricos: PHP lanza TypeError al
        // coaccionar el parámetro int — 500 independiente del motor.
        foreach (['abc', '12abc'] as $seg) {
            $this->deleteJson("/api/v1/admin/storage/pallets/{$seg}")
                ->assertStatus(500, "DELETE storage/pallets/{$seg}")
                ->assertJsonPath('message', 'Ocurrió un error inesperado.');

            $this->deleteJson("/api/v1/admin/storage/orders/{$seg}")
                ->assertStatus(500, "DELETE storage/orders/{$seg}");
        }

        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Restricciones de ruta y escalares ──────────────────────────────────

    #[Test]
    public function token_publico_con_regex_rechaza_segmentos_invalidos(): void
    {
        // El where('[A-Za-z0-9]{48}') falla en el matching: 404 antes de
        // cualquier middleware, anónimo incluido.
        foreach (['abc', '12abc', '-1', '1.5'] as $seg) {
            $this->getJson("/api/v1/public/orders/{$seg}")->assertNotFound("GET public/orders/{$seg}");
            $this->postJson("/api/v1/public/orders/{$seg}/missing-items", [])->assertNotFound();
        }
    }

    #[Test]
    public function ean_escalar_no_compara_contra_bigint(): void
    {
        // {ean} es string y se sanea a dígitos: sin riesgo 22P02.
        $this->getJson('/api/v1/products/by-ean/abc')
            ->assertNotFound()
            ->assertJsonPath('message', 'Producto no encontrado');
    }

    #[Test]
    public function auth_verify_apunta_a_metodo_inexistente(): void
    {
        // Hallazgo separado: AuthController::verifyEmail y
        // ::resendVerification no existen — la ruta responde 500 en ambos
        // motores independientemente del segmento.
        $this->getJson('/api/v1/auth/verify/abc')->assertStatus(500);
        $this->postJson('/api/v1/auth/email/resend', ['email' => 'a@b.c'])->assertStatus(500);
    }

    // ── Precedencia: auth → binding → user.active → rol/admin ──────────────

    #[Test]
    public function anonimo_recibe_401_antes_del_binding(): void
    {
        $this->getJson('/api/v1/customers/abc')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function usuario_inactivo_recibe_el_resultado_del_binding_antes_del_401(): void
    {
        // SQLite: 404 (en PostgreSQL este mismo caso es 500 — el binding
        // precede a user.active en ambos motores).
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $customer = Customer::factory()->create();

        $this->getJson('/api/v1/customers/abc')->assertNotFound();
        $this->getJson("/api/v1/customers/{$customer->id}")->assertUnauthorized();
    }

    #[Test]
    public function usuario_sin_rol_recibe_el_binding_antes_del_403_de_escritura(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $item = OrderItem::factory()->create();

        // SQLite: 404 del binding antes que el 403 de has.role.
        $this->patchJson('/api/v1/order-items/abc', ['qty' => 5])->assertNotFound();
        $this->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 5])->assertForbidden();
    }

    // ── Pertenencia de hijos anidados (ownership) ──────────────────────────

    #[Test]
    public function hijo_ajeno_al_padre_no_se_resuelve_como_propio(): void
    {
        Sanctum::actingAs($this->usuario());
        $orderA = Order::factory()->create();
        $orderB = Order::factory()->create();
        $ticketAjeno = OrderTicket::create(['order_id' => $orderB->id, 'code' => 'AJENO']);
        $photoAjena = OrderTicketPhoto::create([
            'ticket_id' => $ticketAjeno->id,
            'path' => 'x.webp',
            'original_name' => 'x',
            'order_index' => 0,
        ]);
        $ticketPropio = OrderTicket::create(['order_id' => $orderA->id, 'code' => 'PROPIO']);

        $palletA = Pallet::factory()->create();
        $palletB = Pallet::factory()->create();
        $baseAjena = PalletBase::factory()->for($palletB)->create();

        // El binding resuelve el hijo por id global; la pertenencia la
        // verifica el controlador/servicio y devuelve 404.
        $this->deleteJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketAjeno->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Ticket no encontrado');

        $this->getJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}/ocr-status")
            ->assertNotFound();

        $this->patchJson("/api/v1/pallets/{$palletA->id}/bases/{$baseAjena->id}", ['name' => 'x'])
            ->assertNotFound();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Divergencia documentada: un GET sin middleware de escritura ────────

    #[Test]
    public function get_sin_rol_pasa_has_role_pero_el_binding_sigue_activo(): void
    {
        // GET no requiere rol (has.role deja pasar lecturas): el binding
        // sigue siendo el único guardián del segmento.
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/customers/abc')->assertNotFound();
    }
}
