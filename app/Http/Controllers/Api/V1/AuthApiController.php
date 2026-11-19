<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthApiController extends Controller
{
    /**
     * Terbitkan Sanctum Bearer Token dengan proteksi rate limiter dan email normalization.
     */
    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $throttleKey = sha1($validated['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->tooManyRequestsResponse(
                "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
                'TOO_MANY_REQUESTS',
                $seconds,
            );
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return $this->unauthorizedResponse(
                'Kredensial yang diberikan tidak cocok.',
                'UNAUTHENTICATED',
            );
        }

        RateLimiter::clear($throttleKey);

        $deviceName = $validated['device_name'] ?? 'external-client';
        $token = $user->createToken($deviceName)->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 'Autentikasi berhasil');
    }

    /**
     * Cabut token Sanctum yang sedang digunakan pada request aktif.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 'Token berhasil dicabut');
    }

    /**
     * Tampilkan profil pengguna terautentikasi secara aman tanpa kolom sensitif.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
        ], 'Profil pengguna berhasil diambil');
    }
}
