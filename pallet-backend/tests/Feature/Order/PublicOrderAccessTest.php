<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_opaco_permite_ver_el_pedido_sin_exponerlo_en_la_respuesta(): void
    {
        $order = Order::factory()->create(['code' => '12345']);
        $otherOrder = Order::factory()->create();
        OrderItem::factory()->for($order)->create();

        $this->assertSame(48, strlen($order->public_token));
        $this->assertNotSame($order->public_token, $otherOrder->public_token);

        $this->getJson("/api/v1/public/orders/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('code', '12345')
            ->assertJsonMissingPath('public_token');
    }

    public function test_codigo_comercial_ya_no_permite_enumerar_el_pedido(): void
    {
        $order = Order::factory()->create(['code' => '12345']);

        $this->getJson("/api/v1/public/orders/{$order->code}")
            ->assertNotFound();
    }

    public function test_detalle_autenticado_entrega_el_token_para_compartir(): void
    {
        $order = Order::factory()->create();
        $user = User::factory()->withRole()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.public_token', $order->public_token);
    }

    public function test_solicitud_de_faltante_usa_el_mismo_token_opaco(): void
    {
        $order = Order::factory()->create(['code' => '12345']);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        $this->postJson("/api/v1/public/orders/{$order->public_token}/missing-items", [
            'order_item_id' => $item->id,
            'qty_missing' => 2,
        ])->assertCreated();

        $this->postJson("/api/v1/public/orders/{$order->code}/missing-items", [
            'order_item_id' => $item->id,
            'qty_missing' => 2,
        ])->assertNotFound();
    }

    public function test_lectura_publica_limita_rafagas_por_ip_y_recurso(): void
    {
        $order = Order::factory()->create();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->getJson("/api/v1/public/orders/{$order->public_token}")->assertOk();
        }

        $this->getJson("/api/v1/public/orders/{$order->public_token}")
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_escritura_publica_tiene_una_cuota_mas_estricta(): void
    {
        $order = Order::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson("/api/v1/public/orders/{$order->public_token}/missing-items", [])
                ->assertUnprocessable();
        }

        $this->postJson("/api/v1/public/orders/{$order->public_token}/missing-items", [])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_vista_publica_mantiene_queries_acotadas_con_pedido_grande(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->count(20)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson("/api/v1/public/orders/{$order->public_token}")->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(6, $queryCount, "La vista pública ejecutó {$queryCount} queries.");
    }
}
