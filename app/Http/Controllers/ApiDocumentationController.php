<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    /**
     * Tampilkan portal dokumentasi API interaktif berbasis Swagger UI (/docs/api).
     */
    public function index(): View
    {
        return view('docs.api', [
            'specUrl' => route('docs.openapi'),
            'apiVersion' => self::API_VERSION,
        ]);
    }

    /**
     * Sajikan file spesifikasi OpenAPI 3.0.3 (YAML) untuk Swagger UI dan klien eksternal.
     */
    public function spec(): Response
    {
        $path = base_path('docs/openapi.yaml');
        abort_unless(File::exists($path), 404);

        return response(File::get($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'X-API-Version' => self::API_VERSION,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
