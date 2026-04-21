<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** GET /api/v1/admin/users */
    public function index()
    {
        $users = User::orderByDesc('id')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'whatsapp_jid', 'email_verified_at', 'created_at']);

        return response()->json($users);
    }

    /** PATCH /api/v1/admin/users/{user}/role */
    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', 'in:superadmin,admin,user'],
        ]);

        // Solo superadmin puede dar rol superadmin
        if ($data['role'] === 'superadmin' && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'Solo el superadmin puede asignar ese rol'], 403);
        }

        // No puede cambiarse el rol a sí mismo
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'No podés cambiar tu propio rol'], 422);
        }

        $user->update(['role' => $data['role']]);

        return response()->json(['ok' => true, 'user' => $user->fresh(['id', 'name', 'email', 'role', 'is_active'])]);
    }

    /** POST /api/v1/admin/users/{user}/toggle-active */
    public function toggleActive(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'No podés desactivar tu propia cuenta'], 422);
        }

        if ($user->isSuperAdmin()) {
            return response()->json(['error' => 'No podés desactivar al superadmin'], 422);
        }

        $user->update(['is_active' => !$user->is_active]);

        return response()->json(['ok' => true, 'is_active' => $user->fresh()->is_active]);
    }
}
