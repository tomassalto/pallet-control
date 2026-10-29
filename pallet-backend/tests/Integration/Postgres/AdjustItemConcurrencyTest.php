<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
class AdjustItemConcurrencyTest extends TestCase
{
    /** @var array<int, Process> */
    private array $servers = [];

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
        $this->startServer(18001);
        $this->startServer(18002);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(1);
        }

        parent::tearDown();
    }

    public function test_dos_ajustes_concurrentes_no_superan_la_cantidad_pedida(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);
        $firstBase = PalletBase::factory()->for($pallet, 'pallet')->create();
        $secondBase = PalletBase::factory()->for($pallet, 'pallet')->create();

        $responses = $this->sendConcurrently([
            [18001, $pallet->id, $firstBase->id, $item->id, $token],
            [18002, $pallet->id, $secondBase->id, $item->id, $token],
        ]);

        $statuses = array_column($responses, 'status');
        sort($statuses);

        $this->assertSame([200, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));
        $this->assertSame(6, (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $item->id)
            ->sum('qty'));
        $this->assertDatabaseCount('activity_logs', 1);
    }

    private function startServer(int $port): void
    {
        $server = new Process([
            PHP_BINARY,
            'artisan',
            'serve',
            '--host=127.0.0.1',
            "--port={$port}",
            '--no-reload',
        ], base_path());
        $server->setTimeout(null);
        $server->start();
        $this->servers[] = $server;

        $deadline = microtime(true) + 10;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline && $server->isRunning());

        $this->fail("No se pudo iniciar Laravel en el puerto {$port}: {$server->getErrorOutput()}");
    }

    /**
     * @param array<int, array{int, int, int, int, string}> $requests
     * @return array<int, array{status: int, body: mixed}>
     */
    private function sendConcurrently(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($requests as [$port, $palletId, $baseId, $itemId, $token]) {
            $handle = curl_init("http://127.0.0.1:{$port}/api/v1/pallets/{$palletId}/bases/{$baseId}/adjust-item");
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    "Authorization: Bearer {$token}",
                ],
                CURLOPT_POSTFIELDS => json_encode(['order_item_id' => $itemId, 'qty' => 6], JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($multiHandle, $handle);
            $handles[] = $handle;
        }

        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($multiHandle, 1);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $handle) {
            $body = curl_multi_getcontent($handle);
            $responses[] = [
                'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => json_decode($body, true),
            ];
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }
        curl_multi_close($multiHandle);

        return $responses;
    }
}
