<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BotSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.whatsapp_bot.secret');

        if (empty($secret)) {
            \Illuminate\Support\Facades\Log::warning('BotSecretMiddleware: Secret no configurado en producción', [
                'ip' => $request->ip(),
                'uri' => $request->fullUrl(),
            ]);
            return response()->json(['message' => 'Unauthorized: Bot no configurado.'], 401);
        }

        $provided = $request->header('X-Bot-Secret', '');

        if (! hash_equals((string) $secret, (string) $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
