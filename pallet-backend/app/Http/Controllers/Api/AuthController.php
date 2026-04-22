<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'              => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $isFirst = User::count() === 0;

        $user = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'role'              => $isFirst ? 'superadmin' : null,
            'email_verified_at' => $isFirst ? now() : null,
        ]);

        if ($isFirst) {
            // Primer usuario → superadmin, ya verificado, puede entrar directo
            $token = $user->createToken('pallet-pwa')->plainTextToken;

            return response()->json([
                'user'    => $user,
                'token'   => $token,
                'message' => 'Cuenta de superadmin creada. Sesión iniciada.',
            ], 201);
        }

        // Usuarios siguientes: mandar email de verificación
        $this->sendVerificationEmail($user);

        return response()->json([
            'message' => 'Cuenta creada. Revisá tu correo para verificarla antes de iniciar sesión.',
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

        // Superadmin nunca queda bloqueado por verificación de email
        if (!$user->isSuperAdmin() && !$user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Debés verificar tu correo antes de iniciar sesión.'],
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

    public function verifyEmail(Request $request, string $id)
    {
        // Validar URL firmada
        if (!$request->hasValidSignature()) {
            return redirect(config('app.url') . '/login?error=link-expirado');
        }

        $user = User::findOrFail($id);

        if (!hash_equals(sha1($user->email), (string) $request->get('hash'))) {
            return redirect(config('app.url') . '/login?error=link-invalido');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect(config('app.url') . '/login?verified=1');
    }

    public function resendVerification(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            // No revelar si el email existe
            return response()->json(['message' => 'Si el correo existe, recibirás el enlace.']);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Tu correo ya está verificado.']);
        }

        $this->sendVerificationEmail($user);

        return response()->json(['message' => 'Nuevo enlace de verificación enviado.']);
    }

    protected function sendVerificationEmail(User $user): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(48),
            ['id' => $user->getKey(), 'hash' => sha1($user->email)]
        );

        Mail::to($user->email, $user->name)
            ->send(new VerifyEmailMail($user->name, $url));
    }
}
