<?php

namespace Tests\Feature\Pending;

use App\Models\MissingItemRequest;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MissingItemRequestReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_updates_request_creates_pending_and_audits_atomically(): void
    {
        [$admin, $request] = $this->scenario();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/missing-item-requests/{$request->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('reviewed_by_name', $admin->name);

        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('pending_items', [
            'order_item_id' => $request->order_item_id,
            'qty_missing' => $request->qty_missing,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'missing_item_request_approved',
            'entity_id' => $request->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_second_review_returns_conflict_without_duplicate_effects(): void
    {
        [$admin, $request] = $this->scenario();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/missing-item-requests/{$request->id}", ['status' => 'approved'])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/missing-item-requests/{$request->id}", ['status' => 'rejected'])
            ->assertConflict()
            ->assertJsonPath('message', 'La solicitud ya fue revisada.');

        $this->assertDatabaseCount('pending_items', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
    }

    public function test_approval_rolls_back_when_pending_creation_fails(): void
    {
        [$admin, $request] = $this->scenario();
        $this->failInsertsOn('pending_items');

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/missing-item-requests/{$request->id}", ['status' => 'approved'])
            ->assertStatus(500);

        $this->assertRequestRemainsPending($request);
        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_approval_rolls_back_request_and_pending_when_audit_fails(): void
    {
        [$admin, $request] = $this->scenario();
        $this->failInsertsOn('activity_logs');

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/missing-item-requests/{$request->id}", ['status' => 'approved'])
            ->assertStatus(500);

        $this->assertRequestRemainsPending($request);
        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * @return array{User, MissingItemRequest}
     */
    private function scenario(): array
    {
        $admin = User::factory()->admin()->create();
        $item = OrderItem::factory()->create(['qty' => 10]);
        $request = MissingItemRequest::create([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'qty_missing' => 3,
            'status' => 'pending_review',
        ]);

        return [$admin, $request];
    }

    private function failInsertsOn(string $table): void
    {
        DB::statement(<<<SQL
            CREATE TRIGGER fail_{$table}_insert
            BEFORE INSERT ON {$table}
            BEGIN
                SELECT RAISE(ABORT, 'forced {$table} failure');
            END
        SQL);
    }

    private function assertRequestRemainsPending(MissingItemRequest $request): void
    {
        $this->assertDatabaseHas('missing_item_requests', [
            'id' => $request->id,
            'status' => 'pending_review',
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
    }
}
