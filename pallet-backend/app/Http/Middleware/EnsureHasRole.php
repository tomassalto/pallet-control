<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasRole
{
    /**
     * Usuarios sin rol asignado (role = null) solo pueden leer.
     * Cualquier método de escritura (POST, PUT, PATCH, DELETE) devuelve 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Lectura siempre permitida
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        $user = $request->user();

        if (is_null($user?->role)) {
            return response()->json([
                'message' => 'Tu cuenta está pendiente de activación. Un administrador debe asignarte un rol antes de poder realizar cambios.',
            ], 403);
        }

        return $next($request);
    }
}
