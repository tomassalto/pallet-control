<?php

namespace Tests\Integration\Postgres;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
class PendingItemIdValidationTest extends TestCase
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

    // H-050: un id no numérico en una regla `exists` provocaba un error de
    // sintaxis bigint en PostgreSQL y un 500 genérico. Debe devolver 422.
    public function test_ids_no_numericos_devuelven_422_sin_efectos(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();

        $response = $this->postJson('/api/v1/pending-items', [
            'order_id' => 'abc',
            'order_item_id' => 'abc',
            'qty_missing' => 1,
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'validation.integer (and 1 more error)',
                'errors' => [
                    'order_id' => ['validation.integer'],
                    'order_item_id' => ['validation.integer'],
                ],
            ]);
        $this->assertSame(['order_id', 'order_item_id'], array_keys($response->json('errors')));

        $this->assertSame(0, DB::table('pending_items')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());

        $this->postJson('/api/v1/pending-items', [
            'order_id' => $order->id,
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

        $this->assertSame(0, DB::table('pending_items')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    // Los ids enteros y los strings numéricos siguen el mismo contrato que en
    // SQLite: `exists` devuelve 422 para inexistentes y 201 para válidos.
    public function test_ids_enteros_y_strings_numericos_conservan_el_contrato(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->for($order)->create();

        $this->postJson('/api/v1/pending-items', [
            'order_id' => 9999,
            'order_item_id' => $orderItem->id,
            'qty_missing' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_id', ['validation.exists']);

        $this->postJson('/api/v1/pending-items', [
            'order_id' => (string) $order->id,
            'order_item_id' => (string) $orderItem->id,
            'qty_missing' => 1,
        ])->assertCreated();

        $this->assertSame(1, DB::table('pending_items')->count());
    }
}
