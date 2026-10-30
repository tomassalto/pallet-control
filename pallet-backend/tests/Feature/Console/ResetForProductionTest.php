<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResetForProductionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reset_sin_confirmacion_no_modifica_datos(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:reset')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    #[Test]
    public function reset_confirmado_borra_datos_operativos_y_conserva_productos(): void
    {
        Storage::fake('public');
        config()->set('filesystems.default', 'public');

        $product = Product::query()->create([
            'ean' => '7791234567890',
            'ean_last4' => '7890',
            'name' => 'Producto conservado',
        ]);
        $customer = Customer::query()->create(['name' => 'Cliente a borrar']);
        $user = User::factory()->create();

        Storage::disk('public')->put('pallets/photo.webp', 'photo');
        Storage::disk('public')->put('orders/ticket.webp', 'ticket');

        $this->artisan('app:reset', ['--confirm' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('public')->assertMissing('pallets/photo.webp');
        Storage::disk('public')->assertMissing('orders/ticket.webp');
    }

    #[Test]
    public function reset_se_cancela_antes_de_borrar_si_el_esquema_no_es_el_esperado(): void
    {
        $user = User::factory()->create();
        Schema::drop('jobs');

        $this->artisan('app:reset', ['--confirm' => true])
            ->expectsOutputToContain('Reset cancelado')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    #[Test]
    public function el_entrypoint_no_permite_disparar_el_reset_por_variable(): void
    {
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));

        $this->assertIsString($entrypoint);
        $this->assertStringNotContainsString('RESET_ON_STARTUP', $entrypoint);
        $this->assertStringNotContainsString('app:reset', $entrypoint);
    }
}
