<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

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
            'abilities' => ['nullable', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct'],
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

        $requestedAbilities = $validated['abilities'] ?? null;
        if (is_array($requestedAbilities) && $requestedAbilities !== []) {
            $disallowed = array_values(array_diff($requestedAbilities, $user->defaultApiAbilities()));

            if ($disallowed !== []) {
                return $this->validationErrorResponse([
                    'abilities' => [
                        'Role '.$user->role.' tidak diizinkan meminta ability: '.implode(', ', $disallowed).'.',
                    ],
                ]);
            }
        }

        $deviceName = $validated['device_name'] ?? 'external-client';
        $abilities = $user->resolveApiAbilities($requestedAbilities);
        $expirationMinutes = $this->configuredExpirationMinutes();
        $expiresAt = $expirationMinutes !== null
            ? now()->addMinutes($expirationMinutes)
            : null;
        $newAccessToken = $user->createToken($deviceName, $abilities, $expiresAt);

        return $this->successResponse([
            'token' => $newAccessToken->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'expires_at' => $newAccessToken->accessToken->expires_at?->toIso8601String(),
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 'Autentikasi berhasil');
    }

    /**
     * Cabut token Sanctum yang sedang digunakan pada request aktif (atau seluruh perangkat jika all_devices=true).
     */
    public function logout(Request $request): JsonResponse
    {
        if ($request->boolean('all_devices')) {
            $request->user()->tokens()->delete();

            return $this->successResponse(null, 'Seluruh token akses perangkat berhasil dicabut');
        }

        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 'Token berhasil dicabut');
    }

    /**
     * Tampilkan profil pengguna terautentikasi secara aman tanpa kolom sensitif.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessToken = $user->currentAccessToken();
        $tokenAbilities = $user->defaultApiAbilities();
        $tokenExpiresAt = null;

        if ($accessToken instanceof PersonalAccessToken) {
            if (is_array($accessToken->abilities)) {
                $tokenAbilities = array_values($accessToken->abilities);
            }

            $tokenExpiresAt = $accessToken->expires_at;

            if ($tokenExpiresAt === null && $accessToken->created_at !== null) {
                $expirationMinutes = $this->configuredExpirationMinutes();

                if ($expirationMinutes !== null) {
                    $tokenExpiresAt = $accessToken->created_at->copy()->addMinutes($expirationMinutes);
                }
            }
        }

        return $this->successResponse([
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'token_abilities' => $tokenAbilities,
            'token_expires_at' => $tokenExpiresAt?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ], 'Profil pengguna berhasil diambil');
    }

    /**
     * Ambil masa berlaku token Sanctum (menit) yang terkonfigurasi, atau null bila tidak dibatasi.
     */
    private function configuredExpirationMinutes(): ?int
    {
        $expiration = config('sanctum.expiration');

        return is_numeric($expiration) && (int) $expiration > 0
            ? (int) $expiration
            : null;
    }
}
