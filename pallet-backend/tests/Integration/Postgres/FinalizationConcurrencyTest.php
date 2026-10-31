<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Postgres\Concerns\RunsConcurrentRequests;
use Tests\TestCase;

/**
 * Acredita que finalize/reopen son una autoridad transaccional única:
 * los locks en orden D-020 hacen que dos requests solapadas serialicen
 * sin deadlock (nunca 500) y que el ganador deje un único log coherente.
 *
 * El solape es determinista: una transacción lateral retiene FOR UPDATE
 * sobre la fila disputada hasta que ambas requests ya esperan un lock.
 * Cada escenario corre 3 iteraciones con datos frescos.
 */
#[Group('postgres')]
class FinalizationConcurrencyTest extends TestCase
{
    use RunsConcurrentRequests;

    private const PORT_A = 18021;

    private const PORT_B = 18022;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('La extensión pdo_pgsql no está instalada en este runtime.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Este gate sólo acredita concurrencia cuando usa PostgreSQL.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->startServer(self::PORT_A);
        $this->startServer(self::PORT_B);
    }

    protected function tearDown(): void
    {
        $this->stopServersAndProcesses();

        parent::tearDown();
    }

    private function token(): string
    {
        return User::factory()->withRole()->create()
            ->createToken('postgres-concurrency')->plainTextToken;
    }

    private function request(int $port, string $method, string $path, string $token, array $payload = []): array
    {
        return [
            'port' => $port,
            'method' => $method,
            'path' => $path,
            'payload' => $payload,
            'token' => $token,
        ];
    }

    /**
     * Corre las dos requests bajo la compuerta y devuelve sus respuestas.
     *
     * @param array<int, array> $requests
     * @return array<int, array{status: int, body: mixed}>
     */
    private function overlap(string $gateSql, int $gateId, array $requests): array
    {
        $gate = $this->openGateLock($gateSql, $gateId);
        [$multiHandle, $handles] = $this->startJsonRequests($requests);

        $this->waitForLockWaits(2, pump: $multiHandle);

        $gate->commit();
        $responses = $this->collectJsonResponses($multiHandle, $handles);
        unset($gate);

        return $responses;
    }

    private function assertNo5xx(array $responses): void
    {
        foreach ($responses as $response) {
            $this->assertLessThan(500, $response['status'], json_encode($responses, JSON_PRETTY_PRINT));
        }
    }

    private function logIds(string $action): array
    {
        return DB::table('activity_logs')
            ->where('action', $action)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /** Pallet finalizable: 2 bases, 2 fotos, cada base con asignación. */
    private function palletFinalizable(string $status = 'open'): array
    {
        $pallet = Pallet::factory()->create(['status' => $status]);
        $order = Order::factory()->create();
        $pallet->orders()->attach($order->id);

        $itemA = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $itemB = OrderItem::factory()->for($order)->create(['qty' => 5]);

        $baseA = PalletBase::factory()->for($pallet, 'pallet')->create();
        $baseB = PalletBase::factory()->for($pallet, 'pallet')->create();
        $baseA->orderItems()->attach($itemA->id, ['qty' => 10]);
        $baseB->orderItems()->attach($itemB->id, ['qty' => 5]);

        $photoA = PalletBasePhoto::create([
            'base_id' => $baseA->id, 'path' => "t/{$baseA->id}.webp", 'original_name' => 'a.webp',
        ]);
        $photoB = PalletBasePhoto::create([
            'base_id' => $baseB->id, 'path' => "t/{$baseB->id}.webp", 'original_name' => 'b.webp',
        ]);

        return [$pallet, $order, $itemA, $itemB, $baseA, $baseB, $photoA, $photoB];
    }

    // ───────────────────────────────────────────────────────────
    // 1. finalize vs adjust-item (qty 0 sobre la única asignación de base B)
    // ───────────────────────────────────────────────────────────

    public function test_finalize_de_pallet_vs_adjust_item(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $token = $this->token();
            [$pallet, , , $itemB, , $baseB] = $this->palletFinalizable();

            $responses = $this->overlap(
                'SELECT id FROM pallets WHERE id = ? FOR UPDATE',
                $pallet->id,
                [
                    $this->request(self::PORT_A, 'POST', "/api/v1/pallets/{$pallet->id}/finalize", $token),
                    $this->request(self::PORT_B, 'PATCH', "/api/v1/pallets/{$pallet->id}/bases/{$baseB->id}/adjust-item", $token, [
                        'order_item_id' => $itemB->id,
                        'qty' => 0,
                    ]),
                ],
            );

            $this->assertNo5xx($responses);
            $statuses = array_column($responses, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));

            $pallet->refresh();
            if ($pallet->status === 'done') {
                // Ganó el finalize: el adjust quedó rechazado y cada base
                // conserva su asignación.
                $this->assertSame(200, $responses[0]['status']);
                foreach ([$baseB->id] as $baseId) {
                    $this->assertGreaterThan(0, DB::table('pallet_base_order_items')->where('base_id', $baseId)->count());
                }
            } else {
                $this->assertSame(200, $responses[1]['status']);
                $this->assertSame(0, DB::table('pallet_base_order_items')->where('base_id', $baseB->id)->count());
            }
        }
    }

    // ───────────────────────────────────────────────────────────
    // 2. finalize vs DELETE de foto de base (quedan 1 foto)
    // ───────────────────────────────────────────────────────────

    public function test_finalize_de_pallet_vs_delete_foto_de_base(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $token = $this->token();
            [$pallet, , , , $baseA, $baseB, $photoA] = $this->palletFinalizable();

            $responses = $this->overlap(
                'SELECT id FROM pallets WHERE id = ? FOR UPDATE',
                $pallet->id,
                [
                    $this->request(self::PORT_A, 'POST', "/api/v1/pallets/{$pallet->id}/finalize", $token),
                    $this->request(self::PORT_B, 'DELETE', "/api/v1/pallets/{$pallet->id}/bases/{$baseA->id}/photos/{$photoA->id}", $token),
                ],
            );

            $this->assertNo5xx($responses);
            $finalizeStatus = $responses[0]['status'];
            $deleteStatus = $responses[1]['status'];

            if ($deleteStatus === 200 && $finalizeStatus === 409) {
                // El delete ganó: sin 2 fotos el pallet ya no cumple.
                $this->assertSame('open', $pallet->fresh()->status);
                $this->assertSame(1, PalletBasePhoto::query()
                    ->whereIn('base_id', [$baseA->id, $baseB->id])->count());
            } else {
                // Ganó el finalize: ambos 200 y su log precede al de la foto.
                $this->assertSame(200, $finalizeStatus, json_encode($responses, JSON_PRETTY_PRINT));
                $this->assertSame('done', $pallet->fresh()->status);

                $finalizeLog = DB::table('activity_logs')
                    ->where('action', 'pallet_finalized')->where('entity_id', $pallet->id)->value('id');
                $deleteLog = DB::table('activity_logs')
                    ->where('action', 'base_photo_deleted')->where('pallet_id', $pallet->id)->value('id');
                $this->assertNotNull($finalizeLog);
                $this->assertNotNull($deleteLog);
                $this->assertLessThan($deleteLog, $finalizeLog);
            }
        }
    }

    // ───────────────────────────────────────────────────────────
    // 3. finalize de pedido vs PATCH /order-items/{item} (qty 10→12)
    // ───────────────────────────────────────────────────────────

    public function test_finalize_de_pedido_vs_cambio_de_cantidad(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $token = $this->token();
            $order = Order::factory()->create();
            $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
            $pallet = Pallet::factory()->create();
            $order->pallets()->attach($pallet->id);
            $base = PalletBase::factory()->for($pallet, 'pallet')->create();
            $base->orderItems()->attach($item->id, ['qty' => 10]);

            $responses = $this->overlap(
                'SELECT id FROM order_items WHERE id = ? FOR UPDATE',
                $item->id,
                [
                    $this->request(self::PORT_A, 'PATCH', "/api/v1/order-items/{$item->id}", $token, ['qty' => 12]),
                    $this->request(self::PORT_B, 'POST', "/api/v1/orders/{$order->id}/finalize", $token),
                ],
            );

            $this->assertNo5xx($responses);
            $patchStatus = $responses[0]['status'];
            $finalizeStatus = $responses[1]['status'];

            $finalizeLog = DB::table('activity_logs')
                ->where('action', 'order_finalized')->where('entity_id', $order->id)->value('id');

            if ($finalizeStatus === 409) {
                // Ganó el patch: qty 12 > 10 asignadas, el pedido no cumple.
                $this->assertSame(200, $patchStatus, json_encode($responses, JSON_PRETTY_PRINT));
                $this->assertSame('open', $order->fresh()->status);
                $this->assertNull($finalizeLog);
                $this->assertSame(12, (int) $item->fresh()->qty);
            } else {
                // Ganó el finalize: el patch después sube a 12 y ambos 200;
                // el log del pedido precede al del ítem.
                $this->assertSame(200, $finalizeStatus, json_encode($responses, JSON_PRETTY_PRINT));
                $this->assertSame(200, $patchStatus, json_encode($responses, JSON_PRETTY_PRINT));
                $this->assertSame('done', $order->fresh()->status);
                $this->assertNotNull($finalizeLog);

                $patchLog = DB::table('activity_logs')
                    ->where('action', 'item_quantity_changed')->where('entity_id', $item->id)->value('id');
                $this->assertNotNull($patchLog);
                $this->assertLessThan($patchLog, $finalizeLog);
            }
        }
    }

    // ───────────────────────────────────────────────────────────
    // 4. Dos finalize simultáneos (pallet y pedido)
    // ───────────────────────────────────────────────────────────

    public function test_dos_finalize_de_pallet_simultaneos(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $token = $this->token();
            [$pallet] = $this->palletFinalizable();

            $responses = $this->overlap(
                'SELECT id FROM pallets WHERE id = ? FOR UPDATE',
                $pallet->id,
                [
                    $this->request(self::PORT_A, 'POST', "/api/v1/pallets/{$pallet->id}/finalize", $token),
                    $this->request(self::PORT_B, 'POST', "/api/v1/pallets/{$pallet->id}/finalize", $token),
                ],
            );

            $this->assertNo5xx($responses);
            $statuses = array_column($responses, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));

            $this->assertSame('done', $pallet->fresh()->status);
            $this->assertCount(1, DB::table('activity_logs')
                ->where('action', 'pallet_finalized')->where('entity_id', $pallet->id)->get());
        }
    }

    public function test_dos_finalize_de_pedido_simultaneos(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $token = $this->token();
            $order = Order::factory()->create();
            $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
            $pallet = Pallet::factory()->create();
            $order->pallets()->attach($pallet->id);
            $base = PalletBase::factory()->for($pallet, 'pallet')->create();
            $base->orderItems()->attach($item->id, ['qty' => 10]);

            $responses = $this->overlap(
                'SELECT id FROM orders WHERE id = ? FOR UPDATE',
                $order->id,
                [
                    $this->request(self::PORT_A, 'POST', "/api/v1/orders/{$order->id}/finalize", $token),
                    $this->request(self::PORT_B, 'POST', "/api/v1/orders/{$order->id}/finalize", $token),
                ],
            );

            $this->assertNo5xx($responses);
            $statuses = array_column($responses, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));

            $this->assertSame('done', $order->fresh()->status);
            $this->assertCount(1, DB::table('activity_logs')
                ->where('action', 'order_finalized')->where('entity_id', $order->id)->get());
        }
    }

    // ───────────────────────────────────────────────────────────
    // 5. finalize vs reopen sobre un pallet 'done' que cumple requisitos
    // ───────────────────────────────────────────────────────────

    public function test_finalize_vs_reopen_sobre_pallet_finalizado(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $token = $this->token();
            [$pallet] = $this->palletFinalizable(status: 'done');

            $responses = $this->overlap(
                'SELECT id FROM pallets WHERE id = ? FOR UPDATE',
                $pallet->id,
                [
                    $this->request(self::PORT_A, 'POST', "/api/v1/pallets/{$pallet->id}/finalize", $token),
                    $this->request(self::PORT_B, 'POST', "/api/v1/pallets/{$pallet->id}/reopen", $token),
                ],
            );

            $this->assertNo5xx($responses);
            $finalizeStatus = $responses[0]['status'];
            $reopenStatus = $responses[1]['status'];
            $this->assertSame(200, $reopenStatus, json_encode($responses, JSON_PRETTY_PRINT));

            $reopenLogs = DB::table('activity_logs')
                ->where('action', 'pallet_reopened')->where('entity_id', $pallet->id)->pluck('id');
            $finalizeLogs = DB::table('activity_logs')
                ->where('action', 'pallet_finalized')->where('entity_id', $pallet->id)->pluck('id');
            $this->assertCount(1, $reopenLogs);

            $pallet->refresh();
            if ($finalizeStatus === 200) {
                // Ganó el reopen y luego el finalize volvió a cerrar.
                $this->assertSame('done', $pallet->status);
                $this->assertCount(1, $finalizeLogs);
                $this->assertLessThan($finalizeLogs->first(), $reopenLogs->first());
            } else {
                // Ganó el finalize primero: 'done' → 409, y el reopen reabrió.
                $this->assertSame(409, $finalizeStatus, json_encode($responses, JSON_PRETTY_PRINT));
                $this->assertSame('open', $pallet->status);
                $this->assertCount(0, $finalizeLogs);
            }
        }
    }
}
