<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    /**
     * Tampilkan portal dokumentasi API interaktif berbasis Swagger UI (/docs/api).
     */
    public function index(Request $request): View|RedirectResponse
    {
        $redirect = $this->ensureDocumentationAccessible($request, true);
        if ($redirect !== null) {
            return $redirect;
        }

        return view('docs.api', [
            'specUrl' => route('docs.openapi'),
            'apiVersion' => self::API_VERSION,
        ]);
    }

    /**
     * Sajikan file spesifikasi OpenAPI 3.0.3 (YAML) untuk Swagger UI dan klien eksternal.
     */
    public function spec(Request $request): Response
    {
        $this->ensureDocumentationAccessible($request, false);

        $path = base_path('docs/openapi.yaml');
        abort_unless(File::exists($path), 404);

        return response(File::get($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'X-API-Version' => self::API_VERSION,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Terapkan kontrol akses dokumentasi API berbasis konfigurasi lingkungan.
     */
    private function ensureDocumentationAccessible(Request $request, bool $redirectGuests): ?RedirectResponse
    {
        abort_unless((bool) config('app.api_docs_enabled', true), 404);

        $isPublic = (bool) config('app.api_docs_public', ! app()->isProduction());

        if (! $isPublic && ! $this->isAuthenticated($request)) {
            if ($redirectGuests && ! $request->expectsJson()) {
                return redirect()->guest(route('login'));
            }

            abort(401, 'Autentikasi diperlukan untuk mengakses dokumentasi API.');
        }

        return null;
    }

    /**
     * Periksa apakah permintaan memiliki sesi web aktif atau Sanctum Bearer Token yang valid.
     */
    private function isAuthenticated(Request $request): bool
    {
        return $request->user() !== null || $request->user('sanctum') !== null;
    }
}
