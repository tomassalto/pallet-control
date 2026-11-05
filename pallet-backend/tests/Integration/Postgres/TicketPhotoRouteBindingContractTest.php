<?php

namespace Tests\Integration\Postgres;

use App\Jobs\ProcessTicketOcr;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * H-050 — contrato objetivo en PostgreSQL de los bindings implícitos
 * {ticket} y {photo} de tickets sobre PKs bigint, vía el trait
 * App\Models\Concerns\RejectsInvalidIntegerRouteBindings (D-033).
 *
 * Antes del trait, un segmento no numérico llegaba a la comparación
 * `id = '...'` contra bigint y PostgreSQL abortaba con
 * SQLSTATE[22P02]/[22003] → 500 genérico (evidencia previa en
 * BigintRouteBindingCharacterizationTest). Estos tests acreditan que
 * ahora el segmento se rechaza en el binding con
 * ModelNotFoundException (404) sin emitir la consulta por PK: sin
 * excepciones de clase 22 y sin 5xx.
 *
 * La matriz exhaustiva segmento × ruta vive en el espejo SQLite
 * (tests/Feature/Routing/TicketPhotoRouteBindingContractTest); acá se
 * cubren rutas representativas de cada tipo en ambos motores.
 */
#[Group('postgres')]
class TicketPhotoRouteBindingContractTest extends TestCase
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

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function usuario(): User
    {
        return User::factory()->withRole()->create();
    }

    /**
     * @return array{Order, OrderTicket, OrderTicketPhoto}
     */
    private function pedidoConTicket(string $orderStatus = 'open'): array
    {
        $order = Order::factory()->create(['status' => $orderStatus]);
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => "orders/{$order->id}/tickets/{$ticket->id}/foto.webp",
            'original_name' => 'foto.webp',
            'order_index' => 0,
        ]);

        return [$order, $ticket, $photo];
    }

    /**
     * Ejecuta el request sin el handler global para observar la excepción
     * real del binding (restaura el handler al salir).
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

    /**
     * trigger-ocr lleva throttle:10,1 por usuario y corre antes del
     * binding: cada intento consume cupo aunque el segmento sea
     * inválido. Se limpia para no agotar el límite dentro del test.
     */
    private function limpiarThrottleOcr(User $user): void
    {
        RateLimiter::clear(sha1((string) $user->id));
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

    // ── {ticket}: segmentos inválidos → 404, sin query ni 22P02 ────────────

    #[Test]
    public function ticket_segmento_invalido_devuelve_404_sin_consultar_bigint(): void
    {
        $user = $this->usuario();
        Sanctum::actingAs($user);
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'DELETE ticket' => fn (string $s) => $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$s}"),
            'POST photo' => fn (string $s) => $this->postJson("/api/v1/orders/{$order->id}/tickets/{$s}/photos"),
            'GET ocr-status' => fn (string $s) => $this->getJson("/api/v1/orders/{$order->id}/tickets/{$s}/photos/{$photo->id}/ocr-status"),
            'GET highlights' => fn (string $s) => $this->getJson("/api/v1/orders/{$order->id}/tickets/{$s}/photos/{$photo->id}/highlights"),
            'POST trigger-ocr' => fn (string $s) => $this->postJson("/api/v1/orders/{$order->id}/tickets/{$s}/photos/{$photo->id}/trigger-ocr"),
            'DELETE photo' => fn (string $s) => $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$s}/photos/{$photo->id}"),
        ];

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $this->limpiarThrottleOcr($user);

            foreach ($rutas as $contexto => $request) {
                // La excepción real es ModelNotFoundException: ninguna
                // QueryException de clase 22 llega a producirse.
                $e = $this->excepcionDe(fn () => $request($seg));
                $this->assertInstanceOf(ModelNotFoundException::class, $e, "{$contexto} {$seg}");

                $request($seg)->assertNotFound("{$contexto} {$seg}");
            }
        }

        // Prueba de no-consulta: ninguna query toca las tablas de
        // tickets ni de fotos de ticket.
        $this->assertSinConsultasA($consultas, 'order_tickets');
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── {photo} de tickets: segmentos inválidos → 404 ───────────────────────

    #[Test]
    public function photo_segmento_invalido_devuelve_404_sin_consultar_fotos(): void
    {
        $user = $this->usuario();
        Sanctum::actingAs($user);
        [$order, $ticket] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $rutas = [
            'GET ocr-status' => fn (string $s) => $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$s}/ocr-status"),
            'GET highlights' => fn (string $s) => $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$s}/highlights"),
            'POST trigger-ocr' => fn (string $s) => $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$s}/trigger-ocr"),
            'DELETE photo' => fn (string $s) => $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$s}"),
        ];

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            $this->limpiarThrottleOcr($user);

            foreach ($rutas as $contexto => $request) {
                $e = $this->excepcionDe(fn () => $request($seg));
                $this->assertInstanceOf(ModelNotFoundException::class, $e, "{$contexto} {$seg}");

                $request($seg)->assertNotFound("{$contexto} {$seg}");
            }
        }

        // {ticket} es válido: su binding sí consulta order_tickets; el
        // rechazo de {photo} ocurre antes de consultar order_ticket_photos.
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "order_tickets"')
        ));
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    #[Test]
    public function ticket_invalido_frena_antes_de_resolver_la_foto(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {ticket} precede a {photo} en la URI: al fallar, el binding de
        // OrderTicketPhoto no se intenta.
        $this->getJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}/ocr-status")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}")->assertNotFound();

        $this->assertSinConsultasA($consultas, 'order_tickets');
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Ids numéricos y flujos normales ────────────────────────────────────

    #[Test]
    public function ids_numericos_inexistentes_consultan_y_devuelven_404(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/99999")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/99999/ocr-status")->assertNotFound();

        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "order_tickets"')
        ));
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "order_ticket_photos"')
        ));
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    #[Test]
    public function ids_validos_conservan_el_flujo_normal(): void
    {
        $user = $this->usuario();
        Sanctum::actingAs($user);
        [$order, $ticket, $photo] = $this->pedidoConTicket();
        Queue::fake();

        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")
            ->assertOk()
            ->assertJsonPath('id', $photo->id);
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/highlights")
            ->assertOk()
            ->assertJsonPath('ready', false);

        $this->limpiarThrottleOcr($user);
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/trigger-ocr")
            ->assertOk()
            ->assertJsonPath('message', 'OCR iniciado');
        Queue::assertPushed(ProcessTicketOcr::class);

        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100),
        ])->assertCreated()->assertJsonStructure(['photo', 'url']);

        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Foto eliminada');
        $this->assertDatabaseMissing('order_ticket_photos', ['id' => $photo->id]);

        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Ticket eliminado');
        $this->assertDatabaseMissing('order_tickets', ['id' => $ticket->id]);
    }

    // ── Pertenencia de hijos anidados (ownership) ──────────────────────────

    #[Test]
    public function hijos_ajenos_al_padre_no_se_resuelven_como_propios(): void
    {
        Sanctum::actingAs($this->usuario());
        $orderA = Order::factory()->create();
        [$orderB, $ticketAjeno, $photoAjena] = $this->pedidoConTicket();
        $ticketPropio = OrderTicket::create(['order_id' => $orderA->id, 'code' => 'PROPIO']);
        $photoPropia = OrderTicketPhoto::create([
            'ticket_id' => $ticketPropio->id,
            'path' => 'propia.webp',
            'original_name' => 'propia',
            'order_index' => 0,
        ]);

        // El binding resuelve por id global; la pertenencia la rechaza el
        // controlador con 404 — no se opera sobre el hijo ajeno.
        $this->deleteJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketAjeno->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Ticket no encontrado');
        $this->getJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}/ocr-status")
            ->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');
        $this->getJson("/api/v1/orders/{$orderB->id}/tickets/{$ticketAjeno->id}/photos/{$photoPropia->id}/ocr-status")
            ->assertNotFound();

        $this->assertNotNull($ticketAjeno->fresh());
        $this->assertNotNull($photoAjena->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // ── Precedencias conservadas en PostgreSQL ─────────────────────────────

    #[Test]
    public function precedencias_auth_binding_rol_se_conservan(): void
    {
        $order = Order::factory()->create();
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => 'x.webp',
            'original_name' => 'x',
            'order_index' => 0,
        ]);

        // Anónimo: auth:sanctum gana → 401, con id válido o inválido.
        $this->getJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}/ocr-status")->assertUnauthorized();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/abc")->assertUnauthorized();

        // Sin rol en escritura: binding primero (inválido → 404),
        // luego has.role (válido → 403). En lectura GET pasa has.role.
        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/abc")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")->assertForbidden();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")->assertOk();

        // Inactivo: binding antes que user.active → 404 inválido / 401 válido.
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        $this->getJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}/ocr-status")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    #[Test]
    public function orden_done_conserva_409_despues_de_resolver_el_binding(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket('done');

        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/trigger-ocr")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertNotNull($ticket->fresh());
        $this->assertNotNull($photo->fresh());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }
}
