<?php

namespace Tests\Integration\Postgres;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * H-050 — CARACTERIZACIÓN (PostgreSQL) de los route bindings y
 * resoluciones manuales sobre PKs bigint que SIGUEN vulnerables tras la
 * corrección por familias.
 *
 * ATENCIÓN: estos tests acreditan el comportamiento DEFECTUOSO vigente —
 * un segmento no numérico llega sin tipo a la comparación `id = '...'`
 * contra bigint, PostgreSQL responde SQLSTATE[22P02] (o 22003 en
 * overflow) y el handler global traduce la QueryException al 500 genérico.
 * El 500 NO es el contrato deseado: es temporal y queda acotado a las
 * familias aún no corregidas de la matriz de AUDITORIA.md H-050.
 *
 * Familias ya corregidas con contrato propio (404, sin QueryException)
 * y fuera de esta caracterización: {pendingItem}
 * (`PendingItemRouteBindingTest`), la familia admin
 * {user}/{missingItemRequest}, el núcleo {order}/{pallet} y
 * {ticket}/{photo} de tickets (`AdminRouteBindingContractTest`,
 * `OrderPalletRouteBindingContractTest` y
 * `TicketPhotoRouteBindingContractTest`, trait
 * `RejectsInvalidIntegerRouteBindings`).
 *
 * La captura con withoutExceptionHandling prueba el origen real de cada
 * 500 (QueryException con SQLSTATE de clase 22, o TypeError para el
 * type-hint int): ningún 500 de otro origen queda oculto como 22P02.
 * Divergencias con SQLite: ver tests/Feature/Routing.
 */
#[Group('postgres')]
class BigintRouteBindingCharacterizationTest extends TestCase
{
    private const SEGMENTOS_22P02 = ['abc', '12abc', '1.5', '99999999999999999999'];

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

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Ejecuta el request sin el handler global para observar la excepción
     * real que originaría la respuesta, y restaura el handler al salir.
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

    private function afirmarErrorDeTipo22(?Throwable $e, string $contexto): void
    {
        $this->assertInstanceOf(QueryException::class, $e, "{$contexto}: se esperaba QueryException");
        $this->assertContains(
            $e->errorInfo[0] ?? null,
            ['22P02', '22003'],
            "{$contexto}: SQLSTATE esperado 22P02 (sintaxis) o 22003 (overflow), vino " . ($e->errorInfo[0] ?? 'n/a'),
        );
    }

    private function afirmar500Generico($response, string $contexto): void
    {
        $response->assertStatus(500, $contexto)
            ->assertJsonPath('message', 'Ocurrió un error inesperado.')
            ->assertJsonStructure(['error_id'])
            ->assertHeader('X-Error-Id');
    }

    // ── Bindings implícitos desprotegidos ──────────────────────────────────

    public function test_segmentos_no_numericos_producen_500_22p02_en_bindings_de_lectura(): void
    {
        Sanctum::actingAs($this->usuario());
        Customer::factory()->create();

        foreach (self::SEGMENTOS_22P02 as $seg) {
            $this->afirmarErrorDeTipo22(
                $this->excepcionDe(fn () => $this->getJson("/api/v1/customers/{$seg}")),
                "GET customers/{$seg}",
            );
            $this->afirmar500Generico($this->getJson("/api/v1/customers/{$seg}"), "GET customers/{$seg}");
        }

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    public function test_enteros_fuera_de_rango_y_negativos(): void
    {
        Sanctum::actingAs($this->usuario());

        // '-1' es un bigint válido: la consulta corre y no encuentra fila —
        // ModelNotFoundException (404), no QueryException.
        $e = $this->excepcionDe(fn () => $this->getJson('/api/v1/customers/-1'));
        $this->assertInstanceOf(ModelNotFoundException::class, $e);
        $this->getJson('/api/v1/customers/-1')->assertNotFound();

        // Numérico inexistente dentro de rango: 404.
        $this->getJson('/api/v1/customers/99999')->assertNotFound();
    }

    public function test_bindings_implicitos_en_escritura_y_anidados(): void
    {
        Sanctum::actingAs($this->usuario());
        $pallet = Pallet::factory()->create();

        $casos = [
            'PATCH order-items' => fn () => $this->patchJson('/api/v1/order-items/abc', ['qty' => 5]),
            'PATCH bases' => fn () => $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/abc", ['name' => 'x']),
        ];

        foreach ($casos as $contexto => $request) {
            $this->afirmarErrorDeTipo22($this->excepcionDe($request), $contexto);
            $this->afirmar500Generico($request(), $contexto);
        }

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Resoluciones manuales: misma forma de defecto sin binding ──────────

    public function test_resoluciones_manuales_producen_el_mismo_500_22p02(): void
    {
        Sanctum::actingAs($this->usuario());
        $pallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($pallet)->create();
        $photo = PalletBasePhoto::create(['base_id' => $base->id, 'path' => 'p.webp', 'original_name' => 'p']);

        $casos = [
            // PalletBase::findOrFail($baseId) antes de validar el archivo.
            'POST bases/{base}/photos' => fn () => $this->postJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos"),
            // $pallet->photos()->find($photoId).
            'DELETE pallets/{pallet}/photos/{photo}' => fn () => $this->deleteJson("/api/v1/pallets/{$pallet->id}/photos/abc"),
            // Anotaciones: findOrFail($baseId) y find($photoId) manuales.
            'GET annotations {base}' => fn () => $this->getJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos/{$photo->id}/annotations"),
            'GET annotations {photo}' => fn () => $this->getJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/abc/annotations"),
            'DELETE base photos {base}' => fn () => $this->deleteJson("/api/v1/pallets/{$pallet->id}/bases/abc/photos/{$photo->id}"),
        ];

        foreach ($casos as $contexto => $request) {
            $this->afirmarErrorDeTipo22($this->excepcionDe($request), $contexto);
            $this->afirmar500Generico($request(), $contexto);
        }

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── {id} con type-hint int: TypeError, no QueryException ───────────────

    public function test_storage_delete_con_type_hint_int_falla_por_type_error(): void
    {
        Sanctum::actingAs($this->admin());

        // No es un QueryException: PHP rechaza el segmento al coaccionar
        // `int $id`. El 500 es independiente del motor (SQLite igual).
        $e = $this->excepcionDe(fn () => $this->deleteJson('/api/v1/admin/storage/pallets/abc'));
        $this->assertInstanceOf(\TypeError::class, $e);
        $this->afirmar500Generico($this->deleteJson('/api/v1/admin/storage/pallets/abc'), 'DELETE storage/pallets/abc');

        $e = $this->excepcionDe(fn () => $this->deleteJson('/api/v1/admin/storage/orders/abc'));
        $this->assertInstanceOf(\TypeError::class, $e);
        $this->afirmar500Generico($this->deleteJson('/api/v1/admin/storage/orders/abc'), 'DELETE storage/orders/abc');

        // '-1' sí coacciona a int y llega al whereKey: 404 limpio.
        $this->deleteJson('/api/v1/admin/storage/pallets/-1')->assertNotFound();

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Precedencia en PostgreSQL ──────────────────────────────────────────

    public function test_precedencia_anonimo_y_activos(): void
    {
        // Anónimo: auth:sanctum corta antes del binding — 401, no 500.
        $this->getJson('/api/v1/customers/abc')->assertUnauthorized();

        // Inactivo: el binding precede a user.active — 500 por 22P02, no 401.
        // Divergencia respecto a SQLite (que devuelve 404 en este punto).
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $this->afirmarErrorDeTipo22(
            $this->excepcionDe(fn () => $this->getJson('/api/v1/customers/abc')),
            'inactivo + customers/abc',
        );
        $this->afirmar500Generico($this->getJson('/api/v1/customers/abc'), 'inactivo + customers/abc');
    }

    public function test_precedencia_binding_antes_de_has_role(): void
    {
        // Sin rol en escritura: el binding precede a has.role — 500, no 403.
        Sanctum::actingAs(User::factory()->create());
        $this->afirmarErrorDeTipo22(
            $this->excepcionDe(fn () => $this->patchJson('/api/v1/order-items/abc', ['qty' => 5])),
            'sin-rol + order-items/abc',
        );
        $this->afirmar500Generico($this->patchJson('/api/v1/order-items/abc', ['qty' => 5]), 'sin-rol + order-items/abc');
    }

    // ── Pertenencia de hijos anidados ──────────────────────────────────────

    public function test_hijo_ajeno_al_padre_no_se_resuelve_como_propio(): void
    {
        Sanctum::actingAs($this->usuario());
        $orderA = Order::factory()->create();
        $orderB = Order::factory()->create();
        $ticketAjeno = OrderTicket::create(['order_id' => $orderB->id, 'code' => 'AJENO']);
        $ticketPropio = OrderTicket::create(['order_id' => $orderA->id, 'code' => 'PROPIO']);
        $photoAjena = OrderTicketPhoto::create([
            'ticket_id' => $ticketAjeno->id,
            'path' => 'x.webp',
            'original_name' => 'x',
            'order_index' => 0,
        ]);

        $palletA = Pallet::factory()->create();
        $palletB = Pallet::factory()->create();
        $baseAjena = PalletBase::factory()->for($palletB)->create();

        // El binding resuelve por id global; la pertenencia la rechaza el
        // controlador/servicio con 404 — no se opera sobre el hijo ajeno.
        $this->deleteJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketAjeno->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Ticket no encontrado');

        $this->getJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}/ocr-status")
            ->assertNotFound();

        $this->patchJson("/api/v1/pallets/{$palletA->id}/bases/{$baseAjena->id}", ['name' => 'x'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Escalares, restricciones y ruta muerta ─────────────────────────────

    public function test_token_ean_y_ruta_muerta(): void
    {
        // {token} tiene regex: el 404 sale del matching, antes del throttle.
        $this->getJson('/api/v1/public/orders/abc')->assertNotFound();
        // {ean} escalar saneado a dígitos: sin comparación bigint.
        $this->getJson('/api/v1/products/by-ean/abc')->assertNotFound();

        // Ruta registrada hacia método inexistente (hallazgo separado):
        // Error de "undefined method", no QueryException — 500 en ambos motores.
        $e = $this->excepcionDe(fn () => $this->getJson('/api/v1/auth/verify/abc'));
        $this->assertInstanceOf(\Error::class, $e);
        $this->getJson('/api/v1/auth/verify/abc')->assertStatus(500);
    }
}
