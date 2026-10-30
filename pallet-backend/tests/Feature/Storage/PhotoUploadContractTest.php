<?php

namespace Tests\Feature\Storage;

use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Caracterización del contrato de subida de fotos (paso A de D-022).
 * Los tests con sufijo _actual_ fijan el comportamiento que D-022
 * reemplaza; el resto documenta el contrato que se conserva.
 */
class PhotoUploadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    // ── Éxito (contrato conservado) ──────────────────────────────────────

    public function test_foto_de_pallet_subida_ok_devuelve_201_con_photo_y_url(): void
    {
        [$user, , , $pallet] = $this->fixture();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/photos", [
                'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100),
            ]);

        $response->assertCreated()->assertJsonStructure(['photo', 'url']);
    }

    public function test_foto_de_base_subida_ok_devuelve_201_con_photo_y_url(): void
    {
        [$user, $base, , $pallet] = $this->fixture();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
                'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100),
            ]);

        $response->assertCreated()->assertJsonStructure(['photo', 'url']);
    }

    public function test_foto_de_ticket_subida_ok_devuelve_201_con_photo_y_url(): void
    {
        [$user, , $ticket] = $this->fixture();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", [
                'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100),
            ]);

        $response->assertCreated()->assertJsonStructure(['photo', 'url']);
    }

    // ── Sin autenticación (contrato conservado) ──────────────────────────

    public function test_los_tres_endpoints_sin_autenticacion_devuelven_401(): void
    {
        [, $base, $ticket, $pallet] = $this->fixture();
        $file = ['photo' => UploadedFile::fake()->image('foto.jpg', 50, 50)];

        $this->postJson("/api/v1/pallets/{$pallet->id}/photos", $file)->assertUnauthorized();
        $this->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", $file)->assertUnauthorized();
        $this->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", $file)->assertUnauthorized();
    }

    // ── Contenido no imagen (contrato conservado: 422) ───────────────────

    public function test_archivo_de_texto_devuelve_422_en_los_tres_endpoints(): void
    {
        [$user, $base, $ticket, $pallet] = $this->fixture();
        $file = ['photo' => UploadedFile::fake()->create('foto.txt', 10, 'text/plain')];

        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/photos", $file)->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", $file)->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", $file)->assertUnprocessable();
    }

    public function test_jpg_con_contenido_de_texto_devuelve_422_en_los_tres_endpoints(): void
    {
        [$user, $base, $ticket, $pallet] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'txt') . '.jpg';
        file_put_contents($path, 'esto no es una imagen');
        $file = ['photo' => new UploadedFile($path, 'foto.jpg', 'text/plain', null, true)];

        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/photos", $file)->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
            'photo' => new UploadedFile($path, 'foto.jpg', 'text/plain', null, true),
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", [
            'photo' => new UploadedFile($path, 'foto.jpg', 'text/plain', null, true),
        ])->assertUnprocessable();

        @unlink($path);
    }

    // ── Tamaño y nota (contrato conservado) ──────────────────────────────

    public function test_foto_mayor_a_20480kb_devuelve_422_en_los_tres_endpoints(): void
    {
        [$user, $base, $ticket, $pallet] = $this->fixture();
        $make = fn () => UploadedFile::fake()->create('grande.jpg', 20481, 'image/jpeg');

        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/photos", ['photo' => $make()])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", ['photo' => $make()])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", ['photo' => $make()])->assertUnprocessable();
    }

    public function test_nota_mayor_a_1000_devuelve_422_en_los_tres_endpoints(): void
    {
        [$user, $base, $ticket, $pallet] = $this->fixture();
        $payload = fn () => [
            'photo' => UploadedFile::fake()->image('foto.jpg', 50, 50),
            'note' => str_repeat('a', 1001),
        ];

        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/photos", $payload())->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", $payload())->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", $payload())->assertUnprocessable();
    }

    // ── Orden 404 vs 422 (contrato conservado) ───────────────────────────

    public function test_base_de_otro_pallet_devuelve_404_aun_sin_archivo(): void
    {
        [$user, , , $pallet] = $this->fixture();
        $otraBase = PalletBase::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$otraBase->id}/photos", [])
            ->assertStatus(404);
    }

    public function test_ticket_de_otro_pedido_devuelve_404_aun_sin_archivo(): void
    {
        [$user, , $ticket] = $this->fixture();
        $otroPedido = Order::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$otroPedido->id}/tickets/{$ticket->id}/photos", [])
            ->assertStatus(404);
    }

    // ── GIF ──────────────────────────────────────────────────────────────

    public function test_gif_devuelve_422_en_base_y_ticket(): void
    {
        [$user, $base, $ticket, $pallet] = $this->fixture();
        $make = fn () => UploadedFile::fake()->image('foto.gif');

        $this->actingAs($user)->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", ['photo' => $make()])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", ['photo' => $make()])->assertUnprocessable();
    }

    // ── Contrato actual que D-022 reemplaza (sufijo _actual_) ────────────

    public function test_actual_foto_de_pallet_acepta_gif_hoy(): void
    {
        [$user, , , $pallet] = $this->fixture();

        $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/photos", [
                'photo' => UploadedFile::fake()->image('foto.gif'),
            ])
            ->assertCreated();
    }

    public function test_actual_sin_foto_pallet_y_ticket_mensaje_en_ingles(): void
    {
        [$user, , $ticket, $pallet] = $this->fixture();

        $palletResponse = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/photos", []);
        $palletResponse->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
        $this->assertSame(
            __('validation.required'),
            $palletResponse->json('errors.photo.0'),
        );

        $ticketResponse = $this->actingAs($user)
            ->postJson("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos", []);
        $ticketResponse->assertUnprocessable();
        $this->assertSame(
            __('validation.required'),
            $ticketResponse->json('errors.photo.0'),
        );
    }

    public function test_actual_sin_foto_base_mensaje_custom_sin_errors(): void
    {
        [$user, $base, , $pallet] = $this->fixture();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", []);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'No se recibió ningún archivo')
            ->assertJsonMissingPath('errors');
    }

    public function test_actual_base_acepta_archivo_en_otro_campo_pero_sin_alta(): void
    {
        [$user, $base, , $pallet] = $this->fixture();

        // El fallback lee el primer archivo de cualquier campo, pero la
        // validación exige 'photo': jamás produce un alta.
        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
                'file' => UploadedFile::fake()->image('foto.jpg', 50, 50),
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('pallet_base_photos', 0);
    }

    public function test_actual_base_con_error_de_subida_devuelve_error_code(): void
    {
        [$user, $base, , $pallet] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'err');
        file_put_contents($path, 'x');

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
                'photo' => new UploadedFile($path, 'foto.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true),
            ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['message', 'error_code'])
            ->assertJsonPath('error_code', UPLOAD_ERR_INI_SIZE);
        $this->assertDatabaseCount('pallet_base_photos', 0);

        @unlink($path);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{User, PalletBase, OrderTicket, Pallet} */
    private function fixture(): array
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $order = Order::factory()->create();
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);

        return [$user, $base, $ticket, $pallet];
    }
}
