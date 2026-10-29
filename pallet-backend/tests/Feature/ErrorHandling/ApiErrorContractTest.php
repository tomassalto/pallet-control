<?php

namespace Tests\Feature\ErrorHandling;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    #[Test]
    public function unexpected_api_errors_are_generic_and_correlatable_even_with_debug_enabled(): void
    {
        config()->set('app.debug', true);

        $exception = new RuntimeException('sensitive database host and query');
        Route::get('/api/v1/testing/error-contract', fn () => throw $exception);

        $response = $this->getJson('/api/v1/testing/error-contract');

        $response
            ->assertInternalServerError()
            ->assertJsonStructure(['message', 'error_id'])
            ->assertJsonPath('message', 'Ocurrió un error inesperado.')
            ->assertHeader('X-Error-Id', $response->json('error_id'))
            ->assertJsonMissing(['detail' => $exception->getMessage()]);

        $this->assertStringNotContainsString($exception->getMessage(), $response->getContent());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $response->json('error_id'),
        );

        $context = app(ExceptionHandler::class)->contextForException($exception);
        $this->assertSame($response->json('error_id'), $context['error_id']);
    }

    #[Test]
    public function expected_validation_errors_keep_their_existing_contract(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => ['email', 'password'],
            ])
            ->assertJsonMissingPath('error_id');
    }
}
