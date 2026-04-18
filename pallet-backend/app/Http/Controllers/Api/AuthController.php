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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $verificationUrl = $this->makeVerificationUrl($user);

        return response()->json([
            'user' => $user,
            'verification_url' => $verificationUrl,
            'message' => 'Cuenta creada. Revisá tu correo para verificarla.',
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Debes verificar tu correo antes de iniciar sesión.'],
            ]);
        }

        // opcional: borrar tokens viejos (1 sesión por usuario)
        // $user->tokens()->delete();

        $token = $user->createToken('pallet-pwa')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }

    public function verifyEmail(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'El correo ya estaba verificado.',
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return response()->json([
            'message' => 'Correo verificado correctamente.',
        ]);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Tu correo ya está verificado.',
            ]);
        }

        $verificationUrl = $this->makeVerificationUrl($user);

        return response()->json([
            'message' => 'Nuevo enlace de verificación generado.',
            'verification_url' => $verificationUrl,
        ]);
    }

    protected function makeVerificationUrl(User $user): string
    {
        $base = config('app.url') ?? 'http://localhost';

        return rtrim($base, '/') . '/api/v1/auth/verify/' . $user->getKey();
    }
}
