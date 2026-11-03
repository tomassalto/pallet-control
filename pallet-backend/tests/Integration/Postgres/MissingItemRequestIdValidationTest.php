<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
class MissingItemRequestIdValidationTest extends TestCase
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

    // H-050: `order_item_id` no tenía `integer` previo a `exists`, así que un
    // id no numérico llegaba a la consulta y PostgreSQL respondía
    // SQLSTATE[22P02] → 500. Debe devolver 422 validation.integer.
    public function test_order_item_id_no_numerico_devuelve_422_sin_efectos(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/v1/public/orders/{$order->public_token}/missing-items", [
            'order_item_id' => 'abc',
            'qty_missing' => 1,
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.integer',
                'errors' => [
                    'order_item_id' => ['validation.integer'],
                ],
            ]);

        $this->assertSame(0, DB::table('missing_item_requests')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // Los enteros inexistentes siguen fallando en `exists` y los strings
    // numéricos válidos conservan el alta (mismo contrato que SQLite).
    public function test_ids_enteros_y_strings_numericos_conservan_el_contrato(): void
    {
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();
        $url = "/api/v1/public/orders/{$order->public_token}/missing-items";

        $this->postJson($url, [
            'order_item_id' => 9999,
            'qty_missing' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_item_id', ['validation.exists']);

        $this->postJson($url, [
            'order_item_id' => (string) $orderItem->id,
            'qty_missing' => 1,
        ])->assertCreated();

        $this->assertSame(1, DB::table('missing_item_requests')->count());
    }
}
