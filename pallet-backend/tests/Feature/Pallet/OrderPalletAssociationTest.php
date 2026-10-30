<?php

namespace Tests\Feature\Pallet;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPalletAssociationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_order_creates_order_association_and_audits_atomically(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/attach-order", ['order_code' => '12345'])
            ->assertCreated()
            ->assertJsonPath('order.code', '12345');

        $orderId = $response->json('order.id');
        $this->assertDatabaseHas('order_pallet', ['order_id' => $orderId, 'pallet_id' => $pallet->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order_created', 'order_id' => $orderId]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order_assigned',
            'order_id' => $orderId,
            'pallet_id' => $pallet->id,
        ]);
    }

    public function test_both_endpoints_reject_an_existing_association_without_new_log(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);

        $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/attach-order", ['order_code' => $order->code])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido ya está asociado a este pallet.');

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/attach-pallet", ['pallet_id' => $pallet->id])
            ->assertConflict();

        $this->assertDatabaseCount('order_pallet', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_new_order_and_association_roll_back_when_audit_fails(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $this->failActivityLogInserts();

        $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/attach-order", ['order_code' => '54321'])
            ->assertStatus(500);

        $this->assertDatabaseMissing('orders', ['code' => '54321']);
        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_existing_order_association_rolls_back_when_audit_fails(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $this->failActivityLogInserts();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/attach-pallet", ['pallet_id' => $pallet->id])
            ->assertStatus(500);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_detach_removes_allocations_pivot_and_audits(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        DB::table('pallet_base_order_items')->insert([
            'base_id' => $base->id,
            'order_item_id' => $item->id,
            'qty' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$order->id}/detach-pallet/{$pallet->id}")
            ->assertOk()
            ->assertJsonPath('pallets', []);

        $this->assertDatabaseCount('order_pallet', 0);
        $this->assertDatabaseCount('pallet_base_order_items', 0);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'pallet_detached',
            'order_id' => $order->id,
            'pallet_id' => $pallet->id,
        ]);
    }

    public function test_detach_rolls_back_allocations_and_pivot_when_audit_fails(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        DB::table('pallet_base_order_items')->insert([
            'base_id' => $base->id,
            'order_item_id' => $item->id,
            'qty' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->failActivityLogInserts();

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$order->id}/detach-pallet/{$pallet->id}")
            ->assertStatus(500);

        // Nada se escribió: la asignación y el pivot sobreviven al rollback.
        $this->assertDatabaseCount('order_pallet', 1);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id,
            'order_item_id' => $item->id,
            'qty' => 6,
        ]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_detached']);
    }

    public function test_detach_without_association_returns_404(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$order->id}/detach-pallet/{$pallet->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'El pallet no está asociado a este pedido');
    }

    private function failActivityLogInserts(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log_insert
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced activity log failure');
            END
        SQL);
    }
}
