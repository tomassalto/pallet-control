<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /** GET /api/v1/admin/users */
    public function index()
    {
        // Pendientes (sin rol) primero, luego por id desc
        $users = User::orderByRaw('CASE WHEN role IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'whatsapp_jid', 'email_verified_at', 'created_at']);

        return response()->json($users);
    }

    /** PATCH /api/v1/admin/users/{user}/role */
    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            // null = quitar rol (vuelve a modo lectura), '' ignorado
            'role' => ['nullable', 'in:superadmin,admin,user'],
        ]);

        $newRole = $data['role'] ?? null;

        // Solo superadmin puede dar rol superadmin
        if ($newRole === 'superadmin' && ! $request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'Solo el superadmin puede asignar ese rol'], 403);
        }

        // No puede cambiarse el rol a sí mismo
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'No podés cambiar tu propio rol'], 403);
        }

        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'Solo otro superadmin puede modificar esa cuenta'], 403);
        }

        $updatedUser = DB::transaction(function () use ($user, $newRole) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldRole = $lockedUser->role;

            if ($oldRole !== $newRole) {
                $lockedUser->update(['role' => $newRole]);
                $lockedUser->tokens()->delete();

                ActivityLogger::log(
                    action: 'user_role_changed',
                    entityType: 'user',
                    entityId: $lockedUser->id,
                    description: "Rol del usuario '{$lockedUser->email}' actualizado; sesiones revocadas",
                    oldValues: ['role' => $oldRole],
                    newValues: ['role' => $newRole],
                );
            }

            return $lockedUser->refresh();
        });

        return response()->json(['ok' => true, 'user' => $updatedUser->only(['id', 'name', 'email', 'role', 'is_active'])]);
    }

    /** POST /api/v1/admin/users/{user}/toggle-active */
    public function toggleActive(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'No podés desactivar tu propia cuenta'], 403);
        }

        $result = DB::transaction(function () use ($user) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->isSuperAdmin()) {
                return ['error' => 'No podés desactivar al superadmin', 'status' => 403];
            }

            $wasActive = $lockedUser->is_active;
            $isActive = ! $wasActive;
            $lockedUser->update(['is_active' => $isActive]);

            if (! $isActive) {
                $lockedUser->tokens()->delete();
            }

            ActivityLogger::log(
                action: $isActive ? 'user_reactivated' : 'user_deactivated',
                entityType: 'user',
                entityId: $lockedUser->id,
                description: $isActive
                    ? "Usuario '{$lockedUser->email}' reactivado"
                    : "Usuario '{$lockedUser->email}' desactivado y sesiones revocadas",
                oldValues: ['is_active' => $wasActive],
                newValues: ['is_active' => $isActive],
            );

            return ['is_active' => $isActive];
        });

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], $result['status']);
        }

        return response()->json(['ok' => true, 'is_active' => $result['is_active']]);
    }
}
