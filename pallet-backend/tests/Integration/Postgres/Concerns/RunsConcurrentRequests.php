<?php

namespace Tests\Integration\Postgres\Concerns;

use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;

/**
 * Infraestructura compartida de los tests de concurrencia PostgreSQL:
 * procesos `artisan serve`, requests JSON disparadas en paralelo vía
 * curl_multi y una "compuerta" (transacción lateral con FOR UPDATE)
 * que hace determinista el solape de dos requests.
 */
trait RunsConcurrentRequests
{
    /** @var array<int, Process> */
    private array $servers = [];

    /** @var array<int, Process> */
    private array $processes = [];

    protected function stopServersAndProcesses(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(1);
        }
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(3);
            }
        }
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

    /**
     * Abre una conexión lateral con una transacción que toma FOR UPDATE
     * sobre la fila elegida por $sql (p.ej. "SELECT id FROM pallets
     * WHERE id = ? FOR UPDATE"). Las requests disparadas después quedan
     * bloqueadas en su primer lock hasta que se haga commit/rollback.
     */
    private function openGateLock(string $sql, int $id): PDO
    {
        [$host, $port, $database, $user, $password] = $this->pgsqlParams();
        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->beginTransaction();
        $statement = $pdo->prepare($sql);
        $statement->execute([$id]);
        $statement->fetchAll();

        return $pdo;
    }

    /**
     * Dispara las requests sin esperar la respuesta. Hay que llamar a
     * collectJsonResponses() con lo devuelto para drenar curl_multi.
     *
     * @param array<int, array{port: int, method: string, path: string, payload: array<string, mixed>, token: string}> $requests
     * @return array{0: CurlMultiHandle, 1: array<int, CurlHandle>}
     */
    private function startJsonRequests(array $requests): array
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
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multiHandle, $handle);
            $handles[] = $handle;
        }

        // Un primer exec para que las requests salgan efectivamente.
        curl_multi_exec($multiHandle, $running);

        return [$multiHandle, $handles];
    }

    /**
     * @param array<int, CurlHandle> $handles
     * @return array<int, array{status: int, body: mixed}>
     */
    private function collectJsonResponses($multiHandle, array $handles): array
    {
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

    /**
     * Espera hasta que al menos $min backends estén bloqueados en un
     * lock (wait_event_type = 'Lock'). Determinismo del solape: la
     * compuerta sólo se libera cuando ambas requests ya están esperando.
     */
    private function waitForLockWaits(int $min, int $seconds = 30, ?\CurlMultiHandle $pump = null): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($pump !== null) {
                curl_multi_exec($pump, $running);
                curl_multi_select($pump, 0.05);
            }
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND pid <> pg_backend_pid()"
            );
            if ($waiting >= $min) {
                return;
            }
            usleep(100_000);
        }

        $this->fail("Nunca hubo {$min} backends esperando un lock.");
    }
}
