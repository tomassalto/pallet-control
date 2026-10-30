<?php

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    #[Test]
    public function readiness_confirma_que_la_base_de_datos_responde(): void
    {
        $this->getJson('/ready')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'service' => 'pallet-control',
            ]);
    }

    #[Test]
    public function readiness_devuelve_503_sin_filtrar_detalles_si_falla_la_base(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException('sensitive database host'));

        $response = $this->getJson('/ready');

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'ok' => false,
                'service' => 'pallet-control',
            ]);

        $this->assertStringNotContainsString('sensitive database host', $response->getContent());
    }
}
