<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $isFirst = User::count() === 0;

        // Registro cerrado cuando REGISTRATION_ENABLED=false y ya existe al menos 1 usuario.
        // El primer registro siempre se permite (crea el superadmin).
        if (! $isFirst && ! config('app.registration_enabled')) {
            return response()->json([
                'message' => 'El registro de nuevos usuarios está deshabilitado.',
            ], 403);
        }

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'              => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        // Todos los usuarios se auto-verifican al registrarse.
        // El control de acceso real es el sistema de roles (role = null = solo lectura).
        $user = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'role'              => $isFirst ? 'superadmin' : null,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('pallet-pwa')->plainTextToken;

        $message = $isFirst
            ? 'Cuenta de superadmin creada. Sesión iniciada.'
            : 'Cuenta creada. Un administrador te asignará un rol para comenzar a operar.';

        return response()->json([
            'user'    => $user,
            'token'   => $token,
            'message' => $message,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está desactivada. Contactá al administrador.'],
            ]);
        }

        $token = $user->createToken('pallet-pwa')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

}
