<?php

namespace Tests\Feature\Routing;

use App\Jobs\ProcessTicketOcr;
use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * H-050 — contrato objetivo (SQLite) de los bindings implícitos {ticket}
 * y {photo} de tickets sobre PKs bigint, vía el trait
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
 * Cobertura: las 6 rutas con {ticket} y las 4 rutas de ticket con
 * {photo} (binding implícito a OrderTicketPhoto) se ejercitan con
 * segmentos inválidos representativos de cada tipo; el inventario
 * automático y el test estructural garantizan que ninguna ruta futura
 * escape del binding esperado. Los {photo} de pallets/bases/anotaciones
 * se resuelven manualmente (escalar $photoId) y quedan fuera de esta
 * familia — ver la matriz de AUDITORIA.md H-050.
 */
class TicketPhotoRouteBindingContractTest extends TestCase
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

    /**
     * Nombre de clase del type-hint del parámetro de ruta, o null si es
     * escalar/closure (resolución manual, fuera del binding implícito).
     */
    private function tipoParametro($route, string $parametro): ?string
    {
        $action = $route->getActionName();

        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action);

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parametroRef) {
            if ($parametroRef->getName() !== $parametro) {
                continue;
            }

            $tipo = $parametroRef->getType();

            return $tipo instanceof ReflectionNamedType && ! $tipo->isBuiltin()
                ? $tipo->getName()
                : null;
        }

        return null;
    }

    /**
     * Toda ruta de la familia debe declarar el parámetro con el
     * type-hint del modelo esperado: es lo que convierte el segmento en
     * binding implícito (y por tanto en el alcance del trait). Una ruta
     * futura con el parámetro escalar, renombrado o ligado a otro
     * modelo rompe este test.
     */
    private function assertBindingTipado($route, string $parametro, string $modelo): void
    {
        $uri = $route->uri();

        $this->assertSame(
            $modelo,
            $this->tipoParametro($route, $parametro),
            "{$uri} :: \${$parametro} debe ser {$modelo}"
        );
    }

    // ── Inventario automático y test estructural ───────────────────────────

    #[Test]
    public function las_rutas_ticket_y_photo_estan_cubiertas_por_el_binding_esperado(): void
    {
        $this->assertContains(
            RejectsInvalidIntegerRouteBindings::class,
            class_uses_recursive(OrderTicket::class),
            'OrderTicket debe usar RejectsInvalidIntegerRouteBindings'
        );
        $this->assertContains(
            RejectsInvalidIntegerRouteBindings::class,
            class_uses_recursive(OrderTicketPhoto::class),
            'OrderTicketPhoto debe usar RejectsInvalidIntegerRouteBindings'
        );

        $rutasTicket = [];
        $rutasPhotoTicket = [];

        foreach (Route::getRoutes() as $route) {
            $nombres = $route->parameterNames();

            if (in_array('ticket', $nombres, true)) {
                $rutasTicket[] = $route;
            }

            // Sólo cuentan las fotos de ticket: los {photo} de
            // pallets/bases/anotaciones se resuelven a mano con un
            // escalar y pertenecen a otra familia de H-050.
            if (in_array('photo', $nombres, true)
                && $this->tipoParametro($route, 'photo') === OrderTicketPhoto::class) {
                $rutasPhotoTicket[] = $route;
            }
        }

        $this->assertCount(6, $rutasTicket, 'Se esperaban 6 rutas con el parámetro {ticket}');
        $this->assertCount(4, $rutasPhotoTicket, 'Se esperaban 4 rutas de ticket con {photo} → OrderTicketPhoto');

        foreach ($rutasTicket as $route) {
            $this->assertBindingTipado($route, 'ticket', OrderTicket::class);
        }
        foreach ($rutasPhotoTicket as $route) {
            $this->assertBindingTipado($route, 'photo', OrderTicketPhoto::class);
        }
    }

    // ── {ticket}: segmentos inválidos → 404 sin consultar ──────────────────

    #[Test]
    public function ticket_segmentos_invalidos_devuelven_404_sin_consultar(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            // Las 6 rutas con {ticket}, de tipos distintos.
            $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$seg}")->assertNotFound("DELETE ticket {$seg}");
            $this->postJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos")->assertNotFound("POST photo {$seg}");
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos/{$photo->id}/ocr-status")->assertNotFound("ocr-status {$seg}");
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos/{$photo->id}/highlights")->assertNotFound("highlights {$seg}");
            $this->postJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos/{$photo->id}/trigger-ocr")->assertNotFound("trigger-ocr {$seg}");
            $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos/{$photo->id}")->assertNotFound("DELETE photo {$seg}");
        }

        // El rechazo ocurre en el binding: ninguna consulta toca las
        // tablas de tickets ni de fotos de ticket (el binding de {photo}
        // ni siquiera se intenta cuando {ticket} falla antes en la URI).
        $this->assertSinConsultasA($consultas, 'order_tickets');
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertDatabaseCount('activity_logs', 0);
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
        $this->getJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}/highlights")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}")->assertNotFound();

        $this->assertSinConsultasA($consultas, 'order_tickets');
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function order_invalido_frena_antes_de_consultar_tickets_y_fotos(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        // {order} es el primer parámetro de la URI: al fallar, los
        // bindings de {ticket} y {photo} no se intentan.
        $this->deleteJson("/api/v1/orders/abc/tickets/{$ticket->id}")->assertNotFound();
        $this->postJson("/api/v1/orders/abc/tickets/{$ticket->id}/photos")->assertNotFound();
        $this->getJson("/api/v1/orders/abc/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")->assertNotFound();
        $this->deleteJson("/api/v1/orders/abc/tickets/{$ticket->id}/photos/{$photo->id}")->assertNotFound();

        $this->assertSinConsultasA($consultas, 'orders');
        $this->assertSinConsultasA($consultas, 'order_tickets');
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── {photo} de tickets: segmentos inválidos → 404 sin consultar ────────

    #[Test]
    public function photo_segmentos_invalidos_devuelven_404_sin_consultar_fotos(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        foreach (self::SEGMENTOS_INVALIDOS as $seg) {
            // Las 4 rutas de ticket con {photo}.
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$seg}/ocr-status")->assertNotFound("ocr-status {$seg}");
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$seg}/highlights")->assertNotFound("highlights {$seg}");
            $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$seg}/trigger-ocr")->assertNotFound("trigger-ocr {$seg}");
            $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$seg}")->assertNotFound("DELETE photo {$seg}");
        }

        // {ticket} es válido: su binding sí consulta order_tickets; el
        // rechazo de {photo} ocurre antes de consultar order_ticket_photos.
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "order_tickets"')
        ));
        $this->assertSinConsultasA($consultas, 'order_ticket_photos');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    // ── Ids numéricos y flujo normal ───────────────────────────────────────

    #[Test]
    public function ids_numericos_inexistentes_consultan_la_tabla_y_devuelven_404(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket] = $this->pedidoConTicket();

        $consultas = [];
        $this->capturarConsultas($consultas);

        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/99999")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/tickets/99999/photos")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/99999/ocr-status")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/99999")->assertNotFound();

        // Contraste con el segmento inválido: un id bien formado sí emite
        // la consulta por PK y el binding devuelve null → 404.
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "order_tickets"')
        ));
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "order_ticket_photos"')
        ));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function ticket_y_photo_validos_conservan_el_flujo_normal(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket();
        Queue::fake();

        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")
            ->assertOk()
            ->assertJsonPath('id', $photo->id)
            ->assertJsonStructure(['ocr_processed_at', 'ocr_log', 'ocr_eans_count']);

        // Foto sin ocr_data: el contrato responde ready=false, no error.
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/highlights")
            ->assertOk()
            ->assertJsonPath('ready', false);

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

    // ── Casos límite del filtro (contrato D-033) ───────────────────────────

    #[Test]
    public function segmentos_limite_del_filtro_conservan_su_semantica(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        // filter_var acepta signo '+' y espacios alrededor del número:
        // se normalizan al id y el binding resuelve → flujo normal.
        foreach (['+' . $ticket->id, '%20' . $ticket->id] as $seg) {
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos/{$photo->id}/ocr-status")
                ->assertOk("ticket aceptado {$seg}");
        }
        foreach (['+' . $photo->id, '%20' . $photo->id] as $seg) {
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$seg}/ocr-status")
                ->assertOk("photo aceptado {$seg}");
        }

        // Rechaza ceros a la izquierda → 404 sin consultar.
        foreach (['0' . $ticket->id, '00' . $ticket->id] as $seg) {
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$seg}/photos/{$photo->id}/ocr-status")
                ->assertNotFound("ticket rechazado {$seg}");
        }
        foreach (['0' . $photo->id, '00' . $photo->id] as $seg) {
            $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$seg}/ocr-status")
                ->assertNotFound("photo rechazado {$seg}");
        }
    }

    // ── Precedencias vigentes ──────────────────────────────────────────────

    #[Test]
    public function anonimo_recibe_401_antes_del_binding(): void
    {
        $this->getJson('/api/v1/orders/1/tickets/abc/photos/1/ocr-status')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->deleteJson('/api/v1/orders/1/tickets/abc')->assertUnauthorized();
        $this->postJson('/api/v1/orders/1/tickets/abc/photos/1/trigger-ocr')->assertUnauthorized();
        $this->postJson('/api/v1/orders/1/tickets/1/photos', [])->assertUnauthorized();
    }

    #[Test]
    public function inactivo_recibe_el_resultado_del_binding_antes_del_401(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->withRole()->create());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        // El binding precede a user.active: inválido → 404, válido → 401.
        $this->getJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}/ocr-status")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/abc")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")->assertUnauthorized();
    }

    #[Test]
    public function sin_rol_recibe_el_binding_antes_del_403_de_escritura(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$order, $ticket, $photo] = $this->pedidoConTicket();

        // Escritura: inválido → 404 antes que has.role; válido → 403.
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/abc")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos")->assertForbidden();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/trigger-ocr")->assertForbidden();

        // Lectura: has.role deja pasar GET; el binding sigue siendo el
        // único guardián del segmento.
        $this->getJson("/api/v1/orders/{$order->id}/tickets/abc/photos/{$photo->id}/ocr-status")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/abc/ocr-status")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/ocr-status")->assertOk();
    }

    #[Test]
    public function binding_inexistente_precede_al_form_request(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket] = $this->pedidoConTicket();

        // Payload inválido o ausente + binding inválido/inexistente:
        // gana el 404 del binding, nunca el 422 de la validación.
        $this->postJson("/api/v1/orders/{$order->id}/tickets/abc/photos")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/tickets/99999/photos")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/abc/trigger-ocr")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/99999/trigger-ocr")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/99999")->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/99999")->assertNotFound();
    }

    #[Test]
    public function orden_done_conserva_409_despues_de_resolver_el_binding(): void
    {
        Sanctum::actingAs($this->usuario());
        [$order, $ticket, $photo] = $this->pedidoConTicket('done');

        // El binding resolvió las entidades reales: las reglas de dominio
        // (D-026/D-031) responden 409, no el binding.
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 50, 50),
        ])->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/trigger-ocr")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
        $this->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        // Sin efectos: entidades intactas, sin intenciones de borrado.
        $this->assertNotNull($ticket->fresh());
        $this->assertNotNull($photo->fresh());
        $this->assertSame(1, OrderTicketPhoto::count());
        $this->assertDatabaseCount('pending_file_deletions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
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

        // Ticket de orderB bajo orderA: el binding resuelve por id
        // global; la pertenencia la verifica el controlador → 404.
        $this->deleteJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketAjeno->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Ticket no encontrado');
        $this->postJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketAjeno->id}/photos")
            ->assertNotFound()
            ->assertJsonPath('message', 'Ticket no encontrado');

        // Foto de otro ticket bajo el ticket propio.
        $this->getJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}/ocr-status")
            ->assertNotFound();
        $this->getJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}/highlights")
            ->assertNotFound();
        $this->postJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}/trigger-ocr")
            ->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$orderA->id}/tickets/{$ticketPropio->id}/photos/{$photoAjena->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Foto no encontrada');

        // Foto propia bajo el ticket ajeno (pertenece a orderB): el
        // ticket ajeno sí pertenece a orderB, así que el primer chequeo
        // pasa y el de foto↔ticket es el que responde 404.
        $this->getJson("/api/v1/orders/{$orderB->id}/tickets/{$ticketAjeno->id}/photos/{$photoPropia->id}/ocr-status")
            ->assertNotFound();

        // Nada se operó sobre entidades ajenas.
        $this->assertNotNull($ticketAjeno->fresh());
        $this->assertNotNull($photoAjena->fresh());
        $this->assertSame(2, OrderTicket::count());
        $this->assertSame(2, OrderTicketPhoto::count());
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
