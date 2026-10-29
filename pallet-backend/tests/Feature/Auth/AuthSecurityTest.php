<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_invalido_devuelve_401_sin_distinguir_usuario_password_o_estado(): void
    {
        $active = User::factory()->create(['email' => 'active@example.com']);
        $inactive = User::factory()->inactive()->create(['email' => 'inactive@example.com']);

        $cases = [
            ['email' => 'missing@example.com', 'password' => 'password'],
            ['email' => $active->email, 'password' => 'incorrecta'],
            ['email' => $inactive->email, 'password' => 'password'],
        ];

        foreach ($cases as $credentials) {
            $this->postJson('/api/v1/auth/login', $credentials)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Credenciales inválidas.']);
        }
    }

    public function test_desactivar_usuario_revoca_todos_sus_tokens_y_audita_el_cambio(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRole()->create();
        $firstToken = $user->createToken('first')->plainTextToken;
        $user->createToken('second');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$user->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'user_deactivated',
            'entity_type' => 'user',
            'entity_id' => $user->id,
        ]);

        Auth::forgetGuards();
        $this->withToken($firstToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_middleware_rechaza_un_token_residual_de_usuario_inactivo(): void
    {
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('residual')->plainTextToken;
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
    }

    public function test_desactivacion_revierte_usuario_y_tokens_si_falla_la_auditoria(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRole()->create();
        $user->createToken('active-session');
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$user->id}/toggle-active")
            ->assertStatus(500);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_cambiar_rol_revoca_sesiones_y_audita_el_privilegio(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRole()->create();
        $token = $user->createToken('before-role-change')->plainTextToken;

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'user_role_changed',
            'entity_type' => 'user',
            'entity_id' => $user->id,
        ]);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reglas_administrativas_prohibidas_devuelven_403(): void
    {
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$admin->id}/role", ['role' => 'user'])
            ->assertForbidden();

        $this->postJson("/api/v1/admin/users/{$admin->id}/toggle-active")
            ->assertForbidden();

        $this->patchJson("/api/v1/admin/users/{$superadmin->id}/role", ['role' => 'user'])
            ->assertForbidden();

        $this->postJson("/api/v1/admin/users/{$superadmin->id}/toggle-active")
            ->assertForbidden();
    }

    public function test_webhook_telegram_falla_cerrado_sin_secret(): void
    {
        config(['services.telegram.webhook_secret' => null]);

        $this->postJson('/api/v1/telegram/webhook', [])
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'Webhook unavailable']);
    }

    public function test_webhook_telegram_rechaza_secret_incorrecto_y_acepta_el_correcto(): void
    {
        config(['services.telegram.webhook_secret' => 'secret-de-prueba']);

        $this->postJson('/api/v1/telegram/webhook', [], [
            'X-Telegram-Bot-Api-Secret-Token' => 'incorrecto',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/telegram/webhook', [], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret-de-prueba',
        ])->assertOk()->assertExactJson(['ok' => true]);
    }
}
