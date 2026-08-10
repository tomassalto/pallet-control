<?php

namespace Tests\Feature\Pallet;

use App\Models\ActivityLog;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de caracterización — documentan el comportamiento ACTUAL de
 * POST /api/v1/pallets, incluyendo bugs conocidos (ver AUDITORIA.md).
 * NO corrigen nada. Si un refactor rompe alguno de estos asserts,
 * cambió el comportamiento observable — evaluar si era intencional.
 *
 * Referencia: PalletController::store (app/Http/Controllers/Api/PalletController.php)
 */
class PalletCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_autenticado_recibe_401(): void
    {
        $response = $this->postJson('/api/v1/pallets', ['note' => 'test']);

        $response->assertStatus(401);
    }

    public function test_usuario_sin_rol_asignado_recibe_403(): void
    {
        $user = User::factory()->create(['role' => null]);

        $response = $this->actingAs($user)->postJson('/api/v1/pallets', []);

        $response->assertStatus(403);
        $this->assertDatabaseCount('pallets', 0);
    }

    public function test_usuario_sin_rol_puede_listar_pallets_aunque_no_pueda_crear(): void
    {
        $user = User::factory()->create(['role' => null]);
        Pallet::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/pallets');

        $response->assertOk();
    }

    public function test_usuario_con_rol_crea_pallet_sin_nota(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/pallets', []);

        $response->assertCreated();
        $response->assertJsonPath('status', 'open');
        $response->assertJsonPath('note', null);
        $this->assertDatabaseCount('pallets', 1);
    }

    public function test_codigo_generado_tiene_el_formato_pallet_fecha_secuencia(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/pallets', []);

        $today = now()->format('Ymd');
        $response->assertJsonPath('code', "PAL-{$today}-0001");
    }

    public function test_dos_pallets_el_mismo_dia_incrementan_la_secuencia(): void
    {
        $user = User::factory()->withRole()->create();

        $this->actingAs($user)->postJson('/api/v1/pallets', [])->assertCreated();
        $response = $this->actingAs($user)->postJson('/api/v1/pallets', []);

        $today = now()->format('Ymd');
        $response->assertJsonPath('code', "PAL-{$today}-0002");
    }

    public function test_nota_se_persiste_cuando_se_envia(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/pallets', [
            'note' => 'Pallet para pedido urgente',
        ]);

        $response->assertJsonPath('note', 'Pallet para pedido urgente');
        $this->assertDatabaseHas('pallets', ['note' => 'Pallet para pedido urgente']);
    }

    /**
     * H-014 (AUDITORIA.md): 'created_by' existe en el modelo/fillable pero
     * el controlador SIEMPRE lo manda null, sin importar quién esté autenticado.
     * Este test documenta ese comportamiento actual a propósito.
     */
    public function test_created_by_queda_siempre_null_aunque_haya_usuario_autenticado(): void
    {
        $user = User::factory()->withRole()->create();

        $this->actingAs($user)->postJson('/api/v1/pallets', [])->assertCreated();

        $this->assertDatabaseHas('pallets', ['created_by' => null]);
    }

    public function test_crear_pallet_registra_activity_log(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/pallets', []);
        $palletId = $response->json('id');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'pallet_created',
            'entity_type' => 'pallet',
            'entity_id' => $palletId,
            'pallet_id' => $palletId,
        ]);
        $this->assertSame($user->id, ActivityLog::first()->user_id);
    }

    /**
     * La validación hoy es un $request->validate() inline (H-020), no un
     * Form Request — este test fija el comportamiento actual de esa regla.
     */
    public function test_nota_no_string_falla_validacion_con_422(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/pallets', [
            'note' => ['esto' => 'no es un string'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('pallets', 0);
    }
}
