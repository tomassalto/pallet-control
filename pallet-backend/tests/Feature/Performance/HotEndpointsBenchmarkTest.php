<?php

namespace Tests\Feature\Performance;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Baseline de los endpoints candidatos a N+1 / costos crecientes
 * (benchmarks/README.md). Sólo mide; no optimiza nada.
 *
 * Ejecutar con:
 *   RUN_PERFORMANCE_BENCHMARKS=1 php artisan test --filter HotEndpointsBenchmarkTest
 *
 * El dataset es fijo en tamaño (ver SEED_* abajo) para que corridas
 * sucesivas sean comparables entre sí dentro del mismo entorno.
 * Los valores de latencia son locales (SQLite :memory:) y NO
 * representan producción (QA-PERF).
 */
class HotEndpointsBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 30;

    private const ORDERS = 20;

    private const ITEMS_PER_ORDER = 12;

    private const PALLETS_PER_ORDER = 3;

    private const BASES_PER_PALLET = 3;

    private const PHOTOS_PER_BASE = 3;

    private const PHOTOS_PER_PALLET = 2;

    private const TICKETS_PER_ORDER = 2;

    private const PHOTOS_PER_TICKET = 3;

    #[Test]
    #[Group('benchmark')]
    public function mide_los_endpoints_candidatos_a_n1_con_dataset_fijo(): void
    {
        if (getenv('RUN_PERFORMANCE_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Ejecutar con RUN_PERFORMANCE_BENCHMARKS=1.');
        }

        config(['services.telegram.webhook_secret' => 'benchmark-secret']);
        Http::fake();

        $user = User::factory()->withRole()->create();
        [$order, $pallet] = $this->seedDataset($user);

        Sanctum::actingAs($user);

        $results = [];

        $results['GET /api/v1/orders?limit=20'] = $this->measure(
            fn () => $this->getJson('/api/v1/orders?limit=' . self::ORDERS)
        );

        $results['GET /api/v1/orders/{id}'] = $this->measure(
            fn () => $this->getJson("/api/v1/orders/{$order->id}")
        );

        // El endpoint público limita a 20 req/min por IP+recurso. Se limpia el
        // limiter entre iteraciones para poder medir 30 respuestas 200 reales;
        // el control sigue activo — cada request medido lo atraviesa.
        $results['GET /api/v1/public/orders/{token}'] = $this->measure(
            fn () => $this->getJson("/api/v1/public/orders/{$order->public_token}"),
            reset: fn () => Cache::flush()
        );

        $results['GET /api/v1/pallets/{id}'] = $this->measure(
            fn () => $this->getJson("/api/v1/pallets/{$pallet->id}")
        );

        // Rama de sólo lectura del webhook (/vermas): la de mayor profundidad
        // entre las que no escriben filas de negocio.
        $webhookPayload = [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 123456],
                'from' => ['id' => 123456],
                'text' => '/vermas',
            ],
        ];
        $results['POST /api/v1/telegram/webhook (/vermas)'] = $this->measure(
            fn () => $this->postJson('/api/v1/telegram/webhook', $webhookPayload, [
                'X-Telegram-Bot-Api-Secret-Token' => 'benchmark-secret',
            ])
        );

        $summary = [
            'dataset' => [
                'orders' => self::ORDERS,
                'items_per_order' => self::ITEMS_PER_ORDER,
                'pallets_per_order' => self::PALLETS_PER_ORDER,
                'bases_per_pallet' => self::BASES_PER_PALLET,
                'photos_per_base' => self::PHOTOS_PER_BASE,
                'photos_per_pallet' => self::PHOTOS_PER_PALLET,
                'tickets_per_order' => self::TICKETS_PER_ORDER,
                'photos_per_ticket' => self::PHOTOS_PER_TICKET,
            ],
            'iterations_per_endpoint' => self::ITERATIONS,
            'endpoints' => $results,
        ];

        fwrite(STDERR, PHP_EOL . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $this->assertSame(self::ORDERS, Order::count());
    }

    /**
     * Dataset reproducible: cada pedido tiene pallets con bases, fotos,
     * asignaciones de ítems y tickets — la forma que dispara N+1 real.
     *
     * @return array{0: Order, 1: Pallet} pedido y pallet "calientes" a medir
     */
    private function seedDataset(User $user): array
    {
        $hotOrder = null;
        $hotPallet = null;

        for ($o = 0; $o < self::ORDERS; $o++) {
            $order = Order::factory()->create();
            $items = OrderItem::factory()
                ->count(self::ITEMS_PER_ORDER)
                ->for($order)
                ->create(['qty' => 50]);

            for ($p = 0; $p < self::PALLETS_PER_ORDER; $p++) {
                $pallet = Pallet::factory()->create(['created_by' => $user->id]);
                $order->pallets()->attach($pallet->id);

                PalletPhoto::query()->insert(
                    collect(range(1, self::PHOTOS_PER_PALLET))->map(fn ($i) => [
                        'pallet_id' => $pallet->id,
                        'path' => "benchmark/pallet-{$pallet->id}-{$i}.webp",
                        'original_name' => "pallet-{$i}.webp",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );

                $itemCursor = 0;
                for ($b = 0; $b < self::BASES_PER_PALLET; $b++) {
                    $base = PalletBase::factory()->for($pallet)->create([
                        'name' => 'Base ' . ($b + 1),
                    ]);

                    PalletBasePhoto::query()->insert(
                        collect(range(1, self::PHOTOS_PER_BASE))->map(fn ($i) => [
                            'base_id' => $base->id,
                            'path' => "benchmark/base-{$base->id}-{$i}.webp",
                            'original_name' => "base-{$i}.webp",
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])->all()
                    );

                    // Cada ítem se asigna a lo sumo a una base del pedido
                    // (qty 5 ≤ qty 50 → respeta la invariante agregada).
                    if ($itemCursor < $items->count()) {
                        $base->orderItems()->attach($items[$itemCursor]->id, ['qty' => 5]);
                        $itemCursor++;
                    }
                }

                if ($o === 0 && $p === 0) {
                    $hotPallet = $pallet;
                }
            }

            for ($t = 0; $t < self::TICKETS_PER_ORDER; $t++) {
                $ticket = OrderTicket::query()->create([
                    'order_id' => $order->id,
                    'code' => 'T-' . $order->id . '-' . $t,
                ]);
                OrderTicketPhoto::query()->insert(
                    collect(range(1, self::PHOTOS_PER_TICKET))->map(fn ($i) => [
                        'ticket_id' => $ticket->id,
                        'path' => "benchmark/ticket-{$ticket->id}-{$i}.webp",
                        'original_name' => "ticket-{$i}.webp",
                        'order_index' => $i,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            }

            if ($o === 0) {
                $hotOrder = $order;
                foreach ($items->take(4) as $index => $item) {
                    PendingItem::query()->create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'qty_missing' => $index + 1,
                        'status' => $index < 3 ? 'pending' : 'resolved',
                        'created_by' => $user->id,
                        'resolved_by' => $index < 3 ? null : $user->id,
                        'resolved_at' => $index < 3 ? null : now(),
                    ]);
                }
            }
        }

        return [$hotOrder, $hotPallet];
    }

    /**
     * 1 warmup + N iteraciones medidas; luego una pasada instrumentada
     * para conteo de queries y payload.
     *
     * @return array<string, int|float>
     */
    private function measure(callable $request, ?callable $reset = null): array
    {
        $request()->assertOk();

        $durations = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            if ($reset !== null) {
                $reset();
            }
            $start = hrtime(true);
            $request()->assertOk();
            $durations[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($durations);

        $queries = 0;
        $listener = function () use (&$queries) {
            $queries++;
        };
        DB::listen($listener);
        if ($reset !== null) {
            $reset();
        }
        $memoryBefore = memory_get_usage();
        $response = $request()->assertOk();
        $memoryDelta = memory_get_usage() - $memoryBefore;

        return [
            'queries' => $queries,
            'payload_bytes' => strlen($response->getContent()),
            'memory_delta_kb' => round($memoryDelta / 1024, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'p50_ms' => round($this->percentile($durations, 0.50), 2),
            'p95_ms' => round($this->percentile($durations, 0.95), 2),
            'p99_ms' => round($this->percentile($durations, 0.99), 2),
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $index = (int) ceil($percentile * count($values)) - 1;

        return $values[max(0, $index)];
    }
}
