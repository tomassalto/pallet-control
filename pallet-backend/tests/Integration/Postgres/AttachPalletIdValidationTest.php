<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
class AttachPalletIdValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('La extensión pdo_pgsql no está instalada en este runtime.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Este gate sólo corre contra PostgreSQL.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    // H-050: `pallet_id` con `bail` + `integer` antes de `exists` garantiza
    // que un id no numérico nunca llega a la consulta `bigint` de
    // PostgreSQL — nunca 500 ni SQLSTATE[22P02], siempre 422
    // validation.integer y cero efectos.
    public function test_pallet_id_no_numerico_devuelve_422_sin_efectos(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());
        $order = Order::factory()->create();

        $this->postJson("/api/v1/orders/{$order->id}/attach-pallet", [
            'pallet_id' => 'abc',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.integer',
                'errors' => [
                    'pallet_id' => ['validation.integer'],
                ],
            ]);

        $this->assertSame(0, DB::table('order_pallet')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // Los enteros inexistentes siguen fallando en `exists` y los strings
    // numéricos válidos conservan el éxito (mismo contrato que SQLite).
    public function test_ids_enteros_y_strings_numericos_conservan_el_contrato(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());
        $order = Order::factory()->create();
        $pallet = Pallet::factory()->create();
        $url = "/api/v1/orders/{$order->id}/attach-pallet";

        $this->postJson($url, ['pallet_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pallet_id', ['validation.exists']);

        $this->postJson($url, ['pallet_id' => (string) $pallet->id])
            ->assertCreated();

        $this->assertSame(1, DB::table('order_pallet')->count());
        $this->assertSame(1, DB::table('activity_logs')->count());
    }
}
