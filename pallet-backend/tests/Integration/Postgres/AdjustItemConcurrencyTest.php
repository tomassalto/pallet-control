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

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18001,
                'method' => 'PATCH',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$firstBase->id}/adjust-item",
                'payload' => ['order_item_id' => $item->id, 'qty' => 6],
                'token' => $token,
            ],
            [
                'port' => 18002,
                'method' => 'PATCH',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$secondBase->id}/adjust-item",
                'payload' => ['order_item_id' => $item->id, 'qty' => 6],
                'token' => $token,
            ],
        ]);

        $statuses = array_column($responses, 'status');
        sort($statuses);

        $this->assertSame([200, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));
        $this->assertSame(6, (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $item->id)
            ->sum('qty'));
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_dos_creaciones_de_base_concurrentes_no_superan_la_cantidad_pedida(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18001,
                'method' => 'POST',
                'path' => "/api/v1/pallets/{$pallet->id}/bases",
                'payload' => [
                    'name' => 'Base concurrente A',
                    'items' => [['order_item_id' => $item->id, 'qty' => 6]],
                ],
                'token' => $token,
            ],
            [
                'port' => 18002,
                'method' => 'POST',
                'path' => "/api/v1/pallets/{$pallet->id}/bases",
                'payload' => [
                    'name' => 'Base concurrente B',
                    'items' => [['order_item_id' => $item->id, 'qty' => 6]],
                ],
                'token' => $token,
            ],
        ]);

        $statuses = array_column($responses, 'status');
        sort($statuses);

        $this->assertSame([201, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));
        $this->assertSame(6, (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $item->id)
            ->sum('qty'));
        $this->assertDatabaseCount('pallet_bases', 1);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_dos_migraciones_concurrentes_no_extraen_mas_del_origen(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $sourcePallet = Pallet::factory()->create();
        $sourcePallet->orders()->attach($order->id);
        $sourceBase = PalletBase::factory()->for($sourcePallet, 'pallet')->create();
        $sourceBase->orderItems()->attach($item->id, ['qty' => 10]);
        $destinationPallet = Pallet::factory()->create();
        $destinationBase = PalletBase::factory()->for($destinationPallet, 'pallet')->create();
        $payload = [
            'items' => [['order_item_id' => $item->id, 'qty' => 6]],
            'destination_pallet_id' => $destinationPallet->id,
            'destination_base_id' => $destinationBase->id,
        ];

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18001,
                'method' => 'POST',
                'path' => "/api/v1/pallets/{$sourcePallet->id}/bases/{$sourceBase->id}/migrate",
                'payload' => $payload,
                'token' => $token,
            ],
            [
                'port' => 18002,
                'method' => 'POST',
                'path' => "/api/v1/pallets/{$sourcePallet->id}/bases/{$sourceBase->id}/migrate",
                'payload' => $payload,
                'token' => $token,
            ],
        ]);

        $statuses = array_column($responses, 'status');
        sort($statuses);

        $this->assertSame([200, 409], $statuses, json_encode($responses, JSON_PRETTY_PRINT));
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $sourceBase->id,
            'order_item_id' => $item->id,
            'qty' => 4,
        ]);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $destinationBase->id,
            'order_item_id' => $item->id,
            'qty' => 6,
        ]);
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
     * @param array<int, array{port: int, method: string, path: string, payload: array<string, mixed>, token: string}> $requests
     * @return array<int, array{status: int, body: mixed}>
     */
    private function sendJsonConcurrently(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($requests as $request) {
            $handle = curl_init("http://127.0.0.1:{$request['port']}{$request['path']}");
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $request['method'],
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    "Authorization: Bearer {$request['token']}",
                ],
                CURLOPT_POSTFIELDS => json_encode($request['payload'], JSON_THROW_ON_ERROR),
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
