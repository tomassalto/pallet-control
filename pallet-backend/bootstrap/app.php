<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BotSecretMiddleware;
use App\Http\Middleware\EnsureHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'has.role' => EnsureHasRole::class,
            'user.active' => EnsureUserIsActive::class,
            'bot.secret' => BotSecretMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $errorReferences = new WeakMap;
        $errorIdFor = function (Throwable $exception) use ($errorReferences): string {
            return $errorReferences[$exception] ??= (string) Str::uuid();
        };

        // La misma referencia viaja en el log técnico, el cuerpo y el header.
        $exceptions->context(fn (Throwable $exception): array => [
            'error_id' => $errorIdFor($exception),
        ]);

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) use ($errorIdFor): Response {
            if (! $request->is('api/*') || $response->getStatusCode() < 500) {
                return $response;
            }

            $errorId = $errorIdFor($exception);

            return response()
                ->json([
                    'message' => 'Ocurrió un error inesperado.',
                    'error_id' => $errorId,
                ], $response->getStatusCode())
                ->header('X-Error-Id', $errorId);
        });
    })->create();
