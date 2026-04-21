<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    /**
     * Usage: middleware('admin') or middleware('admin:superadmin')
     */
    public function handle(Request $request, Closure $next, string $level = 'admin'): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        if ($level === 'superadmin' && !$user->isSuperAdmin()) {
            return response()->json(['error' => 'Solo el superadmin puede hacer esto'], 403);
        }

        if ($level === 'admin' && !$user->isAdmin()) {
            return response()->json(['error' => 'Se requieren permisos de administrador'], 403);
        }

        return $next($request);
    }
}
