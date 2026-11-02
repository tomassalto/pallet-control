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
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
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
 * Cada endpoint se mide sobre DOS cardinalidades (dataset chico y grande).
 * La única afirmación sobre N+1 que este test puede hacer — y hace — es
 * que el conteo de queries NO crece entre ambas para el mismo endpoint.
 * Nada se concluye de datasets que no se midieron.
 *
 * Las latencias son métricas observacionales (SQLite :memory:, proceso
 * único): sirven para comparar antes/después en este entorno, no como
 * gate ni como SLO productivo (QA-PERF).
 */
class HotEndpointsBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 30;

    private const FAKER_SEED = 20261102;

    /**
     * Budgets de queries = conteo observado 2026-11-02 + 1 de margen.
     * Son assertions reales: si el conteo supera el budget, el test falla.
     */
    private const QUERY_BUDGETS = [
        'orders_list' => 8,
        'order_show' => 12,
        'public_order_show' => 12,
        'pallet_show' => 9,
        'telegram_vermas' => 10,
    ];

    /** Dataset chico: misma forma, menor cardinalidad. */
    private const SMALL = [
        'orders' => 4,
        'items_per_order' => 4,
        'pallets_per_order' => 1,
        'bases_per_pallet' => 1,
        'photos_per_base' => 1,
        'photos_per_pallet' => 1,
        'tickets_per_order' => 1,
        'photos_per_ticket' => 1,
    ];

    /** Dataset grande: la base del baseline de latencia. */
    private const LARGE = [
        'orders' => 20,
        'items_per_order' => 12,
        'pallets_per_order' => 3,
        'bases_per_pallet' => 3,
        'photos_per_base' => 3,
        'photos_per_pallet' => 2,
        'tickets_per_order' => 2,
        'photos_per_ticket' => 3,
    ];

    #[Test]
    #[Group('benchmark')]
    public function mide_los_endpoints_candidatos_a_n1_con_dos_cardinalidades(): void
    {
        if (getenv('RUN_PERFORMANCE_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Ejecutar con RUN_PERFORMANCE_BENCHMARKS=1.');
        }

        config(['services.telegram.webhook_secret' => 'benchmark-secret']);
        Http::fake();

        $user = User::factory()->withRole()->create();
        Sanctum::actingAs($user);

        // ── Pasada chica: sólo conteo de queries ────────────────────────
        $this->seedDataset($user, self::SMALL, hotIndex: 0);
        [$smallOrder, $smallPallet] = $this->hotEntities();
        $smallCounts = $this->measureQueryCounts($smallOrder, $smallPallet, self::SMALL['orders']);

        // ── Pasada grande: conteo + latencias + payload ─────────────────
        $this->wipeDataset();
        $this->seedDataset($user, self::LARGE, hotIndex: 0);
        [$largeOrder, $largePallet] = $this->hotEntities();
        $large = $this->measureAll($largeOrder, $largePallet, self::LARGE['orders']);

        // ── Assertions: el conteo no crece con la cardinalidad ──────────
        foreach ($smallCounts as $key => $count) {
            $this->assertSame(
                $count,
                $large[$key]['queries'],
                "queries de {$key} crecieron entre dataset chico y grande: {$count} → {$large[$key]['queries']}"
            );
            $this->assertLessThanOrEqual(
                self::QUERY_BUDGETS[$key],
                $large[$key]['queries'],
                "queries de {$key} superan el budget " . self::QUERY_BUDGETS[$key]
            );
        }

        $summary = [
            'faker_seed' => self::FAKER_SEED,
            'dataset_small' => self::SMALL,
            'dataset_large' => self::LARGE,
            'iterations_per_endpoint' => self::ITERATIONS,
            'query_counts_small' => $smallCounts,
            'endpoints_large' => $large,
        ];

        fwrite(STDERR, PHP_EOL . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * Mide conteo de queries + payload por endpoint (sin latencias).
     *
     * @return array<string, int>
     */
    private function measureQueryCounts(Order $order, Pallet $pallet, int $limit): array
    {
        return [
            'orders_list' => $this->countQueries(fn () => $this->getJson("/api/v1/orders?limit={$limit}")),
            'order_show' => $this->countQueries(fn () => $this->getJson("/api/v1/orders/{$order->id}")),
            'public_order_show' => $this->countQueries(fn () => $this->getJson("/api/v1/public/orders/{$order->public_token}")),
            'pallet_show' => $this->countQueries(fn () => $this->getJson("/api/v1/pallets/{$pallet->id}")),
            'telegram_vermas' => $this->countQueries(fn () => $this->telegramWebhook('/vermas')),
        ];
    }

    /**
     * Mide cada endpoint completo: warmup + iteraciones de latencia,
     * y una pasada instrumentada para queries/payload/memoria.
     *
     * @return array<string, array<string, int|float>>
     */
    private function measureAll(Order $order, Pallet $pallet, int $limit): array
    {
        return [
            'orders_list' => $this->measure(
                fn () => $this->getJson("/api/v1/orders?limit={$limit}")
            ),
            'order_show' => $this->measure(
                fn () => $this->getJson("/api/v1/orders/{$order->id}")
            ),
            'public_order_show' => $this->measure(
                fn () => $this->getJson("/api/v1/public/orders/{$order->public_token}"),
                reset: fn () => Cache::flush()
            ),
            'pallet_show' => $this->measure(
                fn () => $this->getJson("/api/v1/pallets/{$pallet->id}")
            ),
            'telegram_vermas' => $this->measure(
                fn () => $this->telegramWebhook('/vermas')
            ),
        ];
    }

    /**
     * Pasada instrumentada única: query log habilitado sólo durante la
     * request — sin listeners que se acumulen entre endpoints.
     */
    private function countQueries(callable $request): int
    {
        $request()->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $request()->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries;
    }

    /**
     * 1 warmup + N iteraciones medidas; después una pasada instrumentada
     * para queries, payload y memoria. Instrumentación uniforme para los
     * cinco endpoints.
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

        if ($reset !== null) {
            $reset();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $memoryBefore = memory_get_usage();
        $response = $request()->assertOk();
        $memoryDelta = memory_get_usage() - $memoryBefore;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

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

    private function telegramWebhook(string $text): TestResponse
    {
        return $this->postJson('/api/v1/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 123456],
                'from' => ['id' => 123456],
                'text' => $text,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'benchmark-secret',
        ]);
    }

    /**
     * Dataset determinista en tamaño; Faker queda sembrado una vez por
     * pasada para que payloads sean comparables entre corridas.
     *
     * @param array<string, int> $cfg
     */
    private function seedDataset(User $user, array $cfg, int $hotIndex): void
    {
        fake()->seed(self::FAKER_SEED);

        for ($o = 0; $o < $cfg['orders']; $o++) {
            $order = Order::factory()->create();
            $items = OrderItem::factory()
                ->count($cfg['items_per_order'])
                ->for($order)
                ->create(['qty' => 50]);

            for ($p = 0; $p < $cfg['pallets_per_order']; $p++) {
                $pallet = Pallet::factory()->create(['created_by' => $user->id]);
                $order->pallets()->attach($pallet->id);

                PalletPhoto::query()->insert(
                    collect(range(1, $cfg['photos_per_pallet']))->map(fn ($i) => [
                        'pallet_id' => $pallet->id,
                        'path' => "benchmark/pallet-{$pallet->id}-{$i}.webp",
                        'original_name' => "pallet-{$i}.webp",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );

                $itemCursor = 0;
                for ($b = 0; $b < $cfg['bases_per_pallet']; $b++) {
                    $base = PalletBase::factory()->for($pallet)->create([
                        'name' => 'Base ' . ($b + 1),
                    ]);

                    PalletBasePhoto::query()->insert(
                        collect(range(1, $cfg['photos_per_base']))->map(fn ($i) => [
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
            }

            for ($t = 0; $t < $cfg['tickets_per_order']; $t++) {
                $ticket = OrderTicket::query()->create([
                    'order_id' => $order->id,
                    'code' => 'T-' . $order->id . '-' . $t,
                ]);
                OrderTicketPhoto::query()->insert(
                    collect(range(1, $cfg['photos_per_ticket']))->map(fn ($i) => [
                        'ticket_id' => $ticket->id,
                        'path' => "benchmark/ticket-{$ticket->id}-{$i}.webp",
                        'original_name' => "ticket-{$i}.webp",
                        'order_index' => $i,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            }

            if ($o === $hotIndex) {
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
    }

    /**
     * El pedido "caliente" es el primero creado (id menor), con todo su
     * grafo poblado; el pallet caliente es su primer pallet.
     *
     * @return array{0: Order, 1: Pallet}
     */
    private function hotEntities(): array
    {
        $order = Order::query()->orderBy('id')->firstOrFail();
        $pallet = Pallet::query()
            ->whereHas('orders', fn ($q) => $q->where('orders.id', $order->id))
            ->orderBy('id')
            ->firstOrFail();

        return [$order, $pallet];
    }

    /**
     * Elimina sólo las tablas que puebla el dataset, para poder sembrar
     * la cardinalidad grande dentro del mismo test (RefreshDatabase ya
     * envolvió todo en una transacción).
     */
    private function wipeDataset(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'pending_items',
            'pallet_base_order_items',
            'pallet_base_photos',
            'pallet_photos',
            'order_ticket_photos',
            'order_tickets',
            'order_pallet',
            'pallet_bases',
            'pallets',
            'order_items',
            'orders',
            'customers',
        ] as $table) {
            DB::table($table)->delete();
        }
        Schema::enableForeignKeyConstraints();
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $index = (int) ceil($percentile * count($values)) - 1;

        return $values[max(0, $index)];
    }
}
