<?php

namespace Tests\Feature\Performance;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PendingItemsBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[Group('benchmark')]
    public function mide_el_listado_de_pendientes_con_120_registros(): void
    {
        if (getenv('RUN_PERFORMANCE_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Ejecutar con RUN_PERFORMANCE_BENCHMARKS=1.');
        }

        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $orderItems = OrderItem::factory()->count(120)->for($order)->create();

        foreach ($orderItems as $index => $orderItem) {
            PendingItem::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'qty_missing' => $index + 1,
                'status' => $index < 80 ? 'pending' : 'resolved',
                'created_by' => $user->id,
                'resolved_by' => $index < 80 ? null : $user->id,
                'resolved_at' => $index < 80 ? null : now(),
            ]);
        }

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/pending-items')->assertOk();

        $durations = [];
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $start = hrtime(true);
            $response = $this->getJson('/api/v1/pending-items')->assertOk();
            $durations[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($durations);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $measuredResponse = $this->getJson('/api/v1/pending-items')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $payload = $measuredResponse->json();
        $items = array_is_list($payload) ? $payload : ($payload['data'] ?? []);

        $result = [
            'dataset' => 120,
            'returned_items' => count($items),
            'payload_bytes' => strlen($measuredResponse->getContent()),
            'queries' => $queryCount,
            'iterations' => count($durations),
            'p50_ms' => round($this->percentile($durations, 0.50), 2),
            'p95_ms' => round($this->percentile($durations, 0.95), 2),
            'p99_ms' => round($this->percentile($durations, 0.99), 2),
        ];

        fwrite(STDERR, PHP_EOL . json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL);

        $this->assertSame(120, $result['dataset']);
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $index = (int) ceil($percentile * count($values)) - 1;

        return $values[max(0, $index)];
    }
}
