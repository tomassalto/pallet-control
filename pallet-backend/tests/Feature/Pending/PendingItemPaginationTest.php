<?php

namespace Tests\Feature\Pending;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PendingItemPaginationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function listado_filtra_y_pagina_con_un_contrato_acotado(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $items = OrderItem::factory()->count(65)->for($order)->create();

        foreach ($items as $index => $orderItem) {
            PendingItem::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'qty_missing' => 1,
                'status' => $index < 55 ? 'pending' : 'resolved',
                'created_by' => $user->id,
                'resolved_by' => $index < 55 ? null : $user->id,
                'resolved_at' => $index < 55 ? null : now(),
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/pending-items?status=pending&page=2&per_page=25')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 55);

        $this->assertSame(['pending'], array_values(array_unique(array_column($response->json('data'), 'status'))));

        $this->getJson('/api/v1/pending-items?status=pending&page=3&per_page=25')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/pending-items?status=pending&per_page=25')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $queryCount, 'El listado paginado excedió su presupuesto de queries.');
    }

    #[Test]
    public function listado_rechaza_filtros_y_limites_fuera_del_allowlist(): void
    {
        Sanctum::actingAs(User::factory()->withRole()->create());

        $this->getJson('/api/v1/pending-items?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson('/api/v1/pending-items?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}
