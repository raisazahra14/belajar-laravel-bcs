<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddLegacyApiDeprecationHeaders
{
    /**
     * Pemetaan path legacy non-versioned ke endpoint ekuivalen pada kontrak tunggal /api/v1/*.
     *
     * @var array<string, string>
     */
    private const SUCCESSOR_PATHS = [
        'api/login' => '/api/v1/auth/login',
        'api/logout' => '/api/v1/auth/logout',
        'api/user' => '/api/v1/auth/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $sunset = trim((string) config('app.legacy_api_sunset', 'Wed, 30 Jun 2027 23:59:59 GMT'));
        $successorPath = $this->resolveSuccessorPath($request->path());

        $response->headers->set('Deprecation', 'true');

        if ($sunset !== '') {
            $response->headers->set('Sunset', $sunset);
        }

        $response->headers->set('Link', sprintf('<%s>; rel="successor-version"', $successorPath));

        return $response;
    }

    private function resolveSuccessorPath(string $requestPath): string
    {
        $normalized = ltrim($requestPath, '/');

        if (isset(self::SUCCESSOR_PATHS[$normalized])) {
            return self::SUCCESSOR_PATHS[$normalized];
        }

        if (str_starts_with($normalized, 'api/') && ! str_starts_with($normalized, 'api/v1/')) {
            return '/api/v1/'.substr($normalized, 4);
        }

        return '/api/v1';
    }
}
