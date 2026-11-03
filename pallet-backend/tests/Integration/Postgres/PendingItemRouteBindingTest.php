<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
class PendingItemRouteBindingTest extends TestCase
{
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

    private function pendiente(): PendingItem
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        return PendingItem::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 3,
            'status' => 'pending',
            'created_by' => User::factory()->withRole()->create()->id,
        ]);
    }

    // H-050 — evidencia del defecto vigente: el segmento {pendingItem}
    // llega sin chequeo de tipo a la comparación bigint del binding
    // implícito. PostgreSQL responde SQLSTATE[22P02] y el handler global
    // devuelve el 500 genérico; SQLite devuelve 404 en el mismo caso.
    // Tras el fix este test se actualiza deliberadamente al contrato 404.
    public function test_id_no_numerico_actualmente_produce_500(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());

        $this->patchJson('/api/v1/pending-items/abc', ['status' => 'resolved'])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Ocurrió un error inesperado.');

        $this->deleteJson('/api/v1/pending-items/abc')
            ->assertStatus(500);

        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // El id numérico inexistente conserva su 404 y el válido su flujo
    // normal: nada cambia para identificadores bien formados.
    public function test_ids_numericos_conservan_el_contrato(): void
    {
        $user = User::factory()->withRole()->create();
        Sanctum::actingAs($user);
        $pending = $this->pendiente();

        $this->patchJson('/api/v1/pending-items/99999', ['status' => 'resolved'])
            ->assertNotFound();

        $this->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');

        $this->assertSame(1, DB::table('activity_logs')->count());
    }
}
