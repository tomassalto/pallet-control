<?php

namespace Tests\Feature\Pallet;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La finalización/reapertura es una autoridad transaccional única:
 * estado + auditoría confirman juntos, Telegram sale después del commit
 * y los requisitos se re-evalúan bajo lock.
 */
class FinalizationTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        Http::fake();
    }

    private function actingUser(): User
    {
        return User::factory()->withRole()->create();
    }

    /**
     * Pallet que cumple los requisitos de finalización:
     * 2 bases, 2 fotos en total, cada base con al menos 1 asignación.
     *
     * @return array{0: Pallet, 1: Order, 2: OrderItem, 3: Collection<int, PalletBase>}
     */
    private function palletFinalizable(string $status = 'open'): array
    {
        $pallet = Pallet::factory()->create(['status' => $status]);
        $order = Order::factory()->create();
        $pallet->orders()->attach($order->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        $bases = PalletBase::factory()->for($pallet, 'pallet')->count(2)->create();
        foreach ($bases as $base) {
            PalletBasePhoto::create([
                'base_id' => $base->id,
                'path' => "test/base-{$base->id}.webp",
                'original_name' => 'foto.webp',
            ]);
            $base->orderItems()->attach($item->id, ['qty' => 5]);
        }

        return [$pallet, $order, $item, $bases];
    }

    /** Pedido con un ítem totalmente distribuido en la base de su pallet. */
    private function pedidoFinalizable(string $status = 'open', int $qty = 10): array
    {
        $order = Order::factory()->create(['status' => $status]);
        $item = OrderItem::factory()->for($order)->create(['qty' => $qty]);
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => $qty]);

        return [$order, $item, $pallet, $base];
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

    // ───────────────────────────────────────────────────────────
    // POST /pallets/{pallet}/finalize
    // ───────────────────────────────────────────────────────────

    public function test_finalizar_pallet_confirma_estado_auditoria_y_telegram(): void
    {
        [$pallet] = $this->palletFinalizable();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertOk()
            ->assertJsonPath('message', 'Pallet finalizado correctamente')
            ->assertJsonPath('pallet.status', 'done');

        $this->assertSame('done', $pallet->fresh()->status);

        $logs = ActivityLog::where('action', 'pallet_finalized')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('open', $logs->first()->old_values['status']);
        $this->assertSame('done', $logs->first()->new_values['status']);
        $this->assertSame($pallet->id, $logs->first()->pallet_id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'cerrado'));
    }

    public function test_finalizar_pallet_pausado_registra_el_estado_real(): void
    {
        [$pallet] = $this->palletFinalizable(status: 'paused');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertOk();

        $log = ActivityLog::where('action', 'pallet_finalized')->sole();
        $this->assertSame('paused', $log->old_values['status']);
        Http::assertSentCount(1);
    }

    public function test_finalizar_pallet_sin_requisitos_409_sin_log_ni_telegram(): void
    {
        $pallet = Pallet::factory()->create();
        PalletBase::factory()->for($pallet, 'pallet')->create();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'Se requieren al menos 2 bases, 2 fotos en total, y todas las bases deben tener al menos 1 producto asignado.')
            ->assertJsonStructure([
                'message', 'bases_count', 'total_photos',
                'bases_with_products', 'all_bases_have_products', 'requirements',
            ]);

        $this->assertSame('open', $pallet->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_finalized']);
        Http::assertNothingSent();
    }

    public function test_finalizar_pallet_ya_finalizado_409_sin_log_ni_telegram(): void
    {
        [$pallet] = $this->palletFinalizable(status: 'done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet ya está finalizado.');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_finalized']);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────
    // POST /pallets/{pallet}/reopen
    // ───────────────────────────────────────────────────────────

    public function test_reabrir_pallet_finalizado_confirma_estado_auditoria_y_telegram(): void
    {
        [$pallet] = $this->palletFinalizable(status: 'done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/reopen")
            ->assertOk()
            ->assertJsonPath('pallet.status', 'open');

        $this->assertSame('open', $pallet->fresh()->status);

        $logs = ActivityLog::where('action', 'pallet_reopened')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('done', $logs->first()->old_values['status']);
        $this->assertSame('open', $logs->first()->new_values['status']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'reabierto'));
    }

    public function test_reabrir_pallet_abierto_409_sin_log_ni_telegram(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/reopen")
            ->assertConflict()
            ->assertJsonPath('message', 'Solo se pueden reabrir pallets que estén finalizados');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_reopened']);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────
    // POST /orders/{order}/finalize
    // ───────────────────────────────────────────────────────────

    public function test_finalizar_pedido_confirma_estado_auditoria_y_telegram(): void
    {
        [$order] = $this->pedidoFinalizable();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertOk()
            ->assertJsonPath('order.status', 'done');

        $this->assertSame('done', $order->fresh()->status);

        $logs = ActivityLog::where('action', 'order_finalized')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('open', $logs->first()->old_values['status']);
        $this->assertSame($order->id, $logs->first()->order_id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'finalizado'));
    }

    public function test_finalizar_pedido_ignora_asignaciones_en_pallets_no_asociados(): void
    {
        [$order, $item] = $this->pedidoFinalizable();

        // Mover la asignación a una base de un pallet NO asociado al pedido.
        DB::table('pallet_base_order_items')->delete();
        $otroPallet = Pallet::factory()->create();
        $otraBase = PalletBase::factory()->for($otroPallet, 'pallet')->create();
        $otraBase->orderItems()->attach($item->id, ['qty' => 10]);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'Todos los productos deben tener sus unidades distribuidas en bases de pallets');

        $this->assertSame('open', $order->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_finalized']);
        Http::assertNothingSent();
    }

    public function test_finalizar_pedido_sin_items_409(): void
    {
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido no tiene productos');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_finalized']);
        Http::assertNothingSent();
    }

    public function test_finalizar_pedido_sin_distribuir_409(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create(['qty' => 10]);
        $pallet = Pallet::factory()->create();
        $order->pallets()->attach($pallet->id);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'Todos los productos deben tener sus unidades distribuidas en bases de pallets');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_finalized']);
        Http::assertNothingSent();
    }

    public function test_finalizar_pedido_ya_finalizado_409_sin_log_ni_telegram(): void
    {
        [$order] = $this->pedidoFinalizable(status: 'done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido ya está finalizado.');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_finalized']);
        Http::assertNothingSent();
    }

    public function test_finalizar_pedido_pausado_registra_el_estado_real(): void
    {
        [$order] = $this->pedidoFinalizable(status: 'paused');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertOk();

        $log = ActivityLog::where('action', 'order_finalized')->sole();
        $this->assertSame('paused', $log->old_values['status']);
        Http::assertSentCount(1);
    }

    // ───────────────────────────────────────────────────────────
    // Atomicidad: si la auditoría falla, nada confirma
    // ───────────────────────────────────────────────────────────

    public function test_finalizar_pallet_revierte_estado_si_falla_la_auditoria(): void
    {
        [$pallet] = $this->palletFinalizable();
        $this->failActivityLogInserts();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertStatus(500);

        $this->assertSame('open', $pallet->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_finalized']);
        Http::assertNothingSent();
    }

    public function test_reabrir_pallet_revierte_estado_si_falla_la_auditoria(): void
    {
        [$pallet] = $this->palletFinalizable(status: 'done');
        $this->failActivityLogInserts();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/reopen")
            ->assertStatus(500);

        $this->assertSame('done', $pallet->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_reopened']);
        Http::assertNothingSent();
    }

    public function test_finalizar_pedido_revierte_estado_si_falla_la_auditoria(): void
    {
        [$order] = $this->pedidoFinalizable();
        $this->failActivityLogInserts();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertStatus(500);

        $this->assertSame('open', $order->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_finalized']);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────
    // Telegram sale DESPUÉS del commit
    // ───────────────────────────────────────────────────────────

    public function test_telegram_del_finalize_de_pallet_sale_con_la_transaccion_confirmada(): void
    {
        [$pallet] = $this->palletFinalizable();
        $nivelBase = DB::transactionLevel();
        $captura = [];

        Http::fake(function () use (&$captura, $pallet) {
            $captura = [
                'nivel' => DB::transactionLevel(),
                'status' => DB::table('pallets')->where('id', $pallet->id)->value('status'),
                'log' => DB::table('activity_logs')->where('action', 'pallet_finalized')->exists(),
            ];

            return Http::response('ok');
        });

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize")
            ->assertOk();

        $this->assertSame($nivelBase, $captura['nivel']);
        $this->assertSame('done', $captura['status']);
        $this->assertTrue($captura['log']);
    }

    public function test_telegram_del_reopen_sale_con_la_transaccion_confirmada(): void
    {
        [$pallet] = $this->palletFinalizable(status: 'done');
        $nivelBase = DB::transactionLevel();
        $captura = [];

        Http::fake(function () use (&$captura, $pallet) {
            $captura = [
                'nivel' => DB::transactionLevel(),
                'status' => DB::table('pallets')->where('id', $pallet->id)->value('status'),
                'log' => DB::table('activity_logs')->where('action', 'pallet_reopened')->exists(),
            ];

            return Http::response('ok');
        });

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/reopen")
            ->assertOk();

        $this->assertSame($nivelBase, $captura['nivel']);
        $this->assertSame('open', $captura['status']);
        $this->assertTrue($captura['log']);
    }

    public function test_telegram_del_finalize_de_pedido_sale_con_la_transaccion_confirmada(): void
    {
        [$order] = $this->pedidoFinalizable();
        $nivelBase = DB::transactionLevel();
        $captura = [];

        Http::fake(function () use (&$captura, $order) {
            $captura = [
                'nivel' => DB::transactionLevel(),
                'status' => DB::table('orders')->where('id', $order->id)->value('status'),
                'log' => DB::table('activity_logs')->where('action', 'order_finalized')->exists(),
            ];

            return Http::response('ok');
        });

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/finalize")
            ->assertOk();

        $this->assertSame($nivelBase, $captura['nivel']);
        $this->assertSame('done', $captura['status']);
        $this->assertTrue($captura['log']);
    }

    // ───────────────────────────────────────────────────────────
    // Presupuesto de queries: constante respecto a N dependencias
    // ───────────────────────────────────────────────────────────

    private function contarQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_finalizar_pallet_mantiene_un_numero_constante_de_queries(): void
    {
        $user = $this->actingUser();
        $this->actingAs($user);

        [$palletChico] = $this->palletFinalizable();
        $queriesChico = $this->contarQueries(
            fn () => $this->postJson("/api/v1/pallets/{$palletChico->id}/finalize")->assertOk()
        );

        // Pallet con 20 bases, todas con foto y asignación.
        $palletGrande = Pallet::factory()->create();
        $order = Order::factory()->create();
        $palletGrande->orders()->attach($order->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 200]);
        PalletBase::factory()->for($palletGrande, 'pallet')->count(20)->create()
            ->each(function (PalletBase $base) use ($item) {
                PalletBasePhoto::create([
                    'base_id' => $base->id,
                    'path' => "test/base-{$base->id}.webp",
                    'original_name' => 'foto.webp',
                ]);
                $base->orderItems()->attach($item->id, ['qty' => 10]);
            });

        $queriesGrande = $this->contarQueries(
            fn () => $this->postJson("/api/v1/pallets/{$palletGrande->id}/finalize")->assertOk()
        );

        $this->assertSame($queriesChico, $queriesGrande);
        $this->assertSame(10, $queriesChico, "La finalización ejecutó {$queriesChico} queries.");
    }

    public function test_finalizar_pedido_mantiene_un_numero_constante_de_queries(): void
    {
        $user = $this->actingUser();
        $this->actingAs($user);

        [$orderChico] = $this->pedidoFinalizable();
        $queriesChico = $this->contarQueries(
            fn () => $this->postJson("/api/v1/orders/{$orderChico->id}/finalize")->assertOk()
        );

        // Pedido con 20 ítems, todos totalmente distribuidos.
        $orderGrande = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $orderGrande->pallets()->attach($pallet->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        OrderItem::factory()->for($orderGrande)->count(20)->create(['qty' => 10])
            ->each(fn (OrderItem $item) => $base->orderItems()->attach($item->id, ['qty' => 10]));

        $queriesGrande = $this->contarQueries(
            fn () => $this->postJson("/api/v1/orders/{$orderGrande->id}/finalize")->assertOk()
        );

        $this->assertSame($queriesChico, $queriesGrande);
        $this->assertSame(10, $queriesChico, "La finalización ejecutó {$queriesChico} queries.");
    }
}
