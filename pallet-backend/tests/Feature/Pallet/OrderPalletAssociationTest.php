<?php

namespace Tests\Feature\Pallet;

use App\Models\Order;
use App\Models\Pallet;
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
