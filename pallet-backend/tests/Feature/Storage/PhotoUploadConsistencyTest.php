<?php

namespace Tests\Feature\Storage;

use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use App\Services\PhotoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    public function test_foto_de_pallet_no_deja_registro_ni_archivo_si_falla_la_auditoria(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $this->failActivityLogs();

        $this->actingAs($user)
            ->post("/api/v1/pallets/{$pallet->id}/photos", [
                'photo' => UploadedFile::fake()->image('pallet.jpg', 100, 100),
            ])
            ->assertStatus(500);

        $this->assertDatabaseCount('pallet_photos', 0);
        $this->assertStorageIsEmpty();
    }

    public function test_foto_de_base_no_deja_registro_ni_archivo_si_falla_la_auditoria(): void
    {
        $user = User::factory()->withRole()->create();
        $base = PalletBase::factory()->create();
        $this->failActivityLogs();

        $this->actingAs($user)
            ->post("/api/v1/pallets/{$base->pallet_id}/bases/{$base->id}/photos", [
                'photo' => UploadedFile::fake()->image('base.jpg', 100, 100),
            ])
            ->assertStatus(500);

        $this->assertDatabaseCount('pallet_base_photos', 0);
        $this->assertStorageIsEmpty();
    }

    public function test_foto_de_ticket_no_deja_registro_ni_archivo_si_falla_la_auditoria(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);
        $this->failActivityLogs();

        $this->actingAs($user)
            ->post("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos", [
                'photo' => UploadedFile::fake()->image('ticket.jpg', 100, 100),
            ])
            ->assertStatus(500);

        $this->assertDatabaseCount('order_ticket_photos', 0);
        $this->assertStorageIsEmpty();
    }

    public function test_ticket_del_bot_no_deja_ticket_foto_ni_archivo_si_falla_la_auditoria(): void
    {
        $order = Order::factory()->create();
        $this->failActivityLogs();

        try {
            app(PhotoUploadService::class)->upload(
                UploadedFile::fake()->image('telegram.jpg', 100, 100),
                ['type' => 'ticket', 'order_id' => $order->id],
            );

            $this->fail('La subida debía fallar al intentar registrar la auditoría.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('forced audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('order_tickets', 0);
        $this->assertDatabaseCount('order_ticket_photos', 0);
        $this->assertStorageIsEmpty();
    }

    private function failActivityLogs(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);
    }

    private function assertStorageIsEmpty(): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
