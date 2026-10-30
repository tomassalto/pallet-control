<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
class AggregateAllocationGuardTest extends TestCase
{
    /** @var array<int, Process> */
    private array $servers = [];

    /** @var array<int, Process> */
    private array $processes = [];

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
        $this->startServer(18011);
        $this->startServer(18012);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(1);
        }
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(3);
            }
        }

        parent::tearDown();
    }

    public function test_triggers_rechazan_sobreasignacion_y_reduccion_directa(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $baseA = PalletBase::factory()->create();
        $baseB = PalletBase::factory()->create();

        $this->allocation($baseA->id, $item->id, 6);

        try {
            $this->allocation($baseB->id, $item->id, 6);
            $this->fail('La sobreasignación directa debía ser rechazada por el trigger.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }

        try {
            DB::table('order_items')->where('id', $item->id)->update(['qty' => 5]);
            $this->fail('La reducción por debajo de lo asignado debía ser rechazada.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }

        // El límite exacto sigue siendo válido.
        DB::table('order_items')->where('id', $item->id)->update(['qty' => 6]);
        $this->assertSame(6, (int) DB::table('order_items')->where('id', $item->id)->value('qty'));
    }

    public function test_carrera_real_entre_dos_conexiones_serializa_la_suma(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $baseA = PalletBase::factory()->create();
        $baseB = PalletBase::factory()->create();
        [$host, $port, $database, $user, $password] = $this->pgsqlParams();

        // Conexión A: abre transacción e inserta 6 en base A. El trigger
        // toma el FOR UPDATE del order_item dentro de esa transacción.
        $connA = new PDO(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $connA->beginTransaction();
        $connA->exec(
            'INSERT INTO pallet_base_order_items (base_id, order_item_id, qty, created_at, updated_at) ' .
            "VALUES ({$baseA->id}, {$item->id}, 6, now(), now())"
        );

        // Conexión B: proceso aparte que intenta insertar 6 en base B.
        // Queda bloqueada esperando el lock del order_item que A retiene.
        $script = storage_path('conn_b_' . uniqid() . '.php');
        file_put_contents($script, <<<'PHP'
            <?php
            [$dsn, $user, $pass, $baseId, $itemId] = array_slice($argv, 1);
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT INTO pallet_base_order_items (base_id, order_item_id, qty, created_at, updated_at) "
                    . "VALUES ($baseId, $itemId, 6, now(), now())");
                $pdo->commit();
                echo "COMMIT\n";
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo "SQLSTATE {$e->getCode()}\n";
                exit(2);
            }
            PHP);

        $connB = new Process([
            PHP_BINARY,
            $script,
            "pgsql:host={$host};port={$port};dbname={$database}",
            $user,
            $password,
            (string) $baseB->id,
            (string) $item->id,
        ], base_path());
        $connB->setTimeout(30);
        $connB->start();
        $this->processes[] = $connB;

        // Determinismo: esperar a que B esté efectivamente bloqueado en
        // un lock antes de commitear A. Margen amplio: en CI lento el
        // spawn de PHP + conexión puede tardar varios segundos.
        $deadline = microtime(true) + 30;
        $blocked = false;
        while (microtime(true) < $deadline) {
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND pid <> pg_backend_pid()"
            );
            if ($waiting > 0) {
                $blocked = true;
                break;
            }
            usleep(100_000);
        }
        $this->assertTrue($blocked, 'La conexión B nunca quedó esperando el lock del order_item.');

        $connA->commit();
        $connB->wait();

        $this->assertStringContainsString('SQLSTATE 23514', $connB->getOutput());
        $this->assertSame(6, $this->assignedTotal($item->id));

        @unlink($script);
    }

    public function test_import_vs_adjust_concurrentes_respeta_la_invariante(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['ean' => '7790000000001', 'qty' => 10]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18011,
                'method' => 'POST',
                'path' => "/api/v1/orders/{$order->id}/import",
                'payload' => ['raw' => implode("\t", ['7790000000001', 'Producto A', 4, 4, '', '', '', '', ''])],
                'token' => $token,
            ],
            [
                'port' => 18012,
                'method' => 'PATCH',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item",
                'payload' => ['order_item_id' => $item->id, 'qty' => 6],
                'token' => $token,
            ],
        ]);

        $statuses = array_column($responses, 'status');
        sort($statuses);

        // Gana quien confirma primero: import 201 + adjust 409, o
        // adjust 200 + import 409. Nunca ambos.
        $this->assertContains(
            $statuses,
            [[200, 409], [201, 409]],
            json_encode($responses, JSON_PRETTY_PRINT),
        );

        $item->refresh();
        $this->assertLessThanOrEqual(
            $item->qty,
            $this->assignedTotal($item->id),
        );
    }

    public function test_import_que_omite_item_vs_adjust_concurrente_respeta_la_invariante(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $itemA = OrderItem::factory()->for($order)->create(['ean' => '7790000000001', 'qty' => 10]);
        OrderItem::factory()->for($order)->create(['ean' => '7790000000002', 'qty' => 5]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        // El archivo sólo trae B: importar quiere borrar A (sin asignar)
        // mientras adjust quiere asignarle 6 unidades en la base.
        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18011,
                'method' => 'POST',
                'path' => "/api/v1/orders/{$order->id}/import",
                'payload' => ['raw' => implode("\t", ['7790000000002', 'Producto B', 5, 5, '', '', '', '', ''])],
                'token' => $token,
            ],
            [
                'port' => 18012,
                'method' => 'PATCH',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item",
                'payload' => ['order_item_id' => $itemA->id, 'qty' => 6],
                'token' => $token,
            ],
        ]);

        $importStatus = $responses[0]['status'];
        $adjustStatus = $responses[1]['status'];

        foreach ($responses as $response) {
            $this->assertLessThan(500, $response['status'], json_encode($responses, JSON_PRETTY_PRINT));
        }

        // Nunca ambos 2xx: o import borró A y adjust no lo encontró, o
        // adjust asignó primero e import detectó el ítem ausente asignado.
        if ($importStatus === 201) {
            $this->assertNotSame(200, $adjustStatus, json_encode($responses, JSON_PRETTY_PRINT));
            $this->assertDatabaseMissing('order_items', ['id' => $itemA->id]);
            $this->assertSame(0, $this->assignedTotal($itemA->id));
        } else {
            $this->assertSame(409, $importStatus, json_encode($responses, JSON_PRETTY_PRINT));
            $this->assertSame(200, $adjustStatus, json_encode($responses, JSON_PRETTY_PRINT));
            $this->assertDatabaseHas('order_items', ['id' => $itemA->id]);
            $this->assertSame(6, $this->assignedTotal($itemA->id));
        }

        $qty = DB::table('order_items')->where('id', $itemA->id)->value('qty');
        if ($qty !== null) {
            $this->assertLessThanOrEqual((int) $qty, $this->assignedTotal($itemA->id));
        }
    }

    public function test_detach_vs_adjust_concurrentes_no_producen_deadlock(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18011,
                'method' => 'DELETE',
                'path' => "/api/v1/orders/{$order->id}/detach-pallet/{$pallet->id}",
                'payload' => [],
                'token' => $token,
            ],
            [
                'port' => 18012,
                'method' => 'PATCH',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item",
                'payload' => ['order_item_id' => $item->id, 'qty' => 6],
                'token' => $token,
            ],
        ]);

        // Caminos cruzados de locks: ambos ordenan pallet → bases →
        // order_items → order → asignaciones, así que ninguno puede
        // terminar en 500 (deadlock).
        foreach ($responses as $response) {
            $this->assertNotSame(500, $response['status'], json_encode($responses, JSON_PRETTY_PRINT));
        }

        $item->refresh();
        $this->assertLessThanOrEqual($item->qty, $this->assignedTotal($item->id));
    }

    public function test_migrate_vs_adjust_concurrentes_no_producen_deadlock(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 7]);
        $destPallet = Pallet::factory()->create();
        $destPallet->orders()->attach($order->id);
        $destBase = PalletBase::factory()->for($destPallet, 'pallet')->create();

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18011,
                'method' => 'POST',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate",
                'payload' => [
                    'items' => [['order_item_id' => $item->id, 'qty' => 4]],
                    'destination_pallet_id' => $destPallet->id,
                    'destination_base_id' => $destBase->id,
                ],
                'token' => $token,
            ],
            [
                'port' => 18012,
                'method' => 'PATCH',
                'path' => "/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item",
                'payload' => ['order_item_id' => $item->id, 'qty' => 6],
                'token' => $token,
            ],
        ]);

        // Ambos recorridos ordenan pallet → bases → order_items →
        // asignaciones: ninguno puede terminar en 500 por deadlock.
        foreach ($responses as $response) {
            $this->assertNotSame(500, $response['status'], json_encode($responses, JSON_PRETTY_PRINT));
        }

        $item->refresh();
        $this->assertLessThanOrEqual($item->qty, $this->assignedTotal($item->id));
    }

    public function test_import_vs_crear_base_concurrentes_no_producen_deadlock(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('postgres-concurrency')->plainTextToken;
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['ean' => '7790000000001', 'qty' => 10]);
        $pallet = Pallet::factory()->create();
        $pallet->orders()->attach($order->id);

        $responses = $this->sendJsonConcurrently([
            [
                'port' => 18011,
                'method' => 'POST',
                'path' => "/api/v1/orders/{$order->id}/import",
                'payload' => ['raw' => implode("\t", ['7790000000001', 'Producto A', 10, 10, '', '', '', '', ''])],
                'token' => $token,
            ],
            [
                'port' => 18012,
                'method' => 'POST',
                'path' => "/api/v1/pallets/{$pallet->id}/bases",
                'payload' => [
                    'name' => 'Base concurrente',
                    'items' => [['order_item_id' => $item->id, 'qty' => 6]],
                ],
                'token' => $token,
            ],
        ]);

        foreach ($responses as $response) {
            $this->assertNotSame(500, $response['status'], json_encode($responses, JSON_PRETTY_PRINT));
        }

        $item->refresh();
        $this->assertLessThanOrEqual($item->qty, $this->assignedTotal($item->id));
    }

    public function test_migracion_down_up_recupera_la_guarda(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $base = PalletBase::factory()->create();
        $this->allocation($base->id, $item->id, 6);

        $migration = require database_path('migrations/2026_10_30_000004_add_aggregate_allocation_guard.php');

        $migration->down();

        $triggers = DB::select(
            "SELECT tgname FROM pg_trigger WHERE tgname LIKE 'trg_%' AND NOT tgisinternal"
        );
        $this->assertSame([], $triggers);

        // Con la guarda caída, la sobreasignación que el preflight
        // detecta puede crearse.
        $baseB = PalletBase::factory()->create();
        $this->allocation($baseB->id, $item->id, 6);

        try {
            $migration->up();
            $this->fail('El preflight debía rechazar la sobreasignación.');
        } catch (\RuntimeException) {
        }

        DB::table('pallet_base_order_items')->where('base_id', $baseB->id)->update(['qty' => 3]);
        $migration->up();

        $baseC = PalletBase::factory()->create();
        try {
            $this->allocation($baseC->id, $item->id, 9);
            $this->fail('La guarda debía volver a estar activa.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    private function allocation(int $baseId, int $itemId, int $qty): void
    {
        DB::table('pallet_base_order_items')->insert([
            'base_id' => $baseId,
            'order_item_id' => $itemId,
            'qty' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignedTotal(int $itemId): int
    {
        return (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $itemId)
            ->sum('qty');
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private function pgsqlParams(): array
    {
        $config = config('database.connections.pgsql');

        return [
            (string) $config['host'],
            (string) $config['port'],
            (string) $config['database'],
            (string) $config['username'],
            (string) $config['password'],
        ];
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
