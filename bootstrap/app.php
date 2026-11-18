<?php

use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Http\Middleware\EnsureUserHasRole;
use App\Traits\ApiResponses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApiV1Request = static fn (Request $request): bool => $request->is('api/v1') || $request->is('api/v1/*');

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $e): bool => $isApiV1Request($request) || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) use ($isApiV1Request) {
            if (! $isApiV1Request($request)) {
                return null;
            }

            $responder = new class
            {
                use ApiResponses {
                    badRequestResponse as public;
                    unauthorizedResponse as public;
                    forbiddenResponse as public;
                    notFoundResponse as public;
                    methodNotAllowedResponse as public;
                    conflictResponse as public;
                    unsupportedMediaTypeResponse as public;
                    validationErrorResponse as public;
                    tooManyRequestsResponse as public;
                    errorResponse as public;
                }
            };

            return match (true) {
                $e instanceof ValidationException => $responder->validationErrorResponse(
                    $e->errors(),
                    'Validasi gagal',
                    'VALIDATION_ERROR',
                ),
                $e instanceof AuthenticationException => $responder->unauthorizedResponse(
                    'Autentikasi diperlukan atau token tidak valid.',
                    'UNAUTHENTICATED',
                ),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => $responder->forbiddenResponse(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Anda tidak memiliki hak akses untuk operasi ini.',
                    'FORBIDDEN',
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => $responder->notFoundResponse(
                    'Data atau endpoint API yang diminta tidak ditemukan.',
                    'RESOURCE_NOT_FOUND',
                ),
                $e instanceof InvalidPurchaseOrderTransitionException => $responder->errorResponse(
                    $e->getMessage(),
                    strtoupper($e->errorCode),
                    $e->httpStatus(),
                ),
                $e instanceof ThrottleRequestsException => $responder->tooManyRequestsResponse(
                    'Terlalu banyak permintaan. Silakan coba lagi beberapa saat lagi.',
                    'TOO_MANY_REQUESTS',
                    isset($e->getHeaders()['Retry-After']) ? (int) $e->getHeaders()['Retry-After'] : null,
                ),
                $e instanceof ConflictHttpException => $responder->conflictResponse(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Terjadi konflik data atau status transaksi.',
                    'CONFLICT',
                ),
                $e instanceof DeadlockException => $responder->conflictResponse(
                    'Terjadi kepadatan atau konflik transaksi database. Silakan coba lagi beberapa saat lagi.',
                    'CONFLICT',
                ),
                $e instanceof BadRequestHttpException => $responder->badRequestResponse(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Permintaan tidak valid.',
                    [],
                    'BAD_REQUEST',
                ),
                $e instanceof JsonException => $responder->badRequestResponse(
                    'Format payload JSON tidak valid.',
                    [],
                    'BAD_REQUEST',
                ),
                $e instanceof MethodNotAllowedHttpException => $responder->errorResponse(
                    'Metode HTTP tidak diizinkan untuk endpoint ini.',
                    'METHOD_NOT_ALLOWED',
                    405,
                    [],
                    $e->getHeaders(),
                ),
                $e instanceof HttpExceptionInterface => (static function (HttpExceptionInterface $httpException) use ($responder) {
                    $status = $httpException->getStatusCode();

                    if ($status === 415 && $httpException->getMessage() === '') {
                        return $responder->unsupportedMediaTypeResponse();
                    }

                    $code = match ($status) {
                        400 => 'BAD_REQUEST',
                        401 => 'UNAUTHENTICATED',
                        403 => 'FORBIDDEN',
                        404 => 'RESOURCE_NOT_FOUND',
                        405 => 'METHOD_NOT_ALLOWED',
                        409 => 'CONFLICT',
                        415 => 'UNSUPPORTED_MEDIA_TYPE',
                        422 => 'VALIDATION_ERROR',
                        429 => 'TOO_MANY_REQUESTS',
                        503 => 'SERVICE_UNAVAILABLE',
                        default => 'HTTP_ERROR_'.$status,
                    };
                    $defaultMessage = match ($status) {
                        400 => 'Permintaan tidak valid.',
                        401 => 'Autentikasi diperlukan atau token tidak valid.',
                        403 => 'Anda tidak memiliki hak akses untuk operasi ini.',
                        404 => 'Data atau endpoint API yang diminta tidak ditemukan.',
                        405 => 'Metode HTTP tidak diizinkan untuk endpoint ini.',
                        409 => 'Terjadi konflik data atau status transaksi.',
                        415 => 'Format Content-Type tidak didukung. Gunakan application/json.',
                        422 => 'Validasi gagal',
                        429 => 'Terlalu banyak permintaan. Silakan coba lagi beberapa saat lagi.',
                        503 => 'Layanan API sedang tidak tersedia atau dalam pemeliharaan.',
                        default => 'Terjadi kesalahan pada permintaan HTTP.',
                    };
                    $message = $httpException->getMessage() !== ''
                        ? $httpException->getMessage()
                        : $defaultMessage;

                    return $responder->errorResponse(
                        $message,
                        $code,
                        $status,
                        [],
                        $httpException->getHeaders(),
                    );
                })($e),
                default => $responder->errorResponse(
                    config('app.debug') && $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'Terjadi kesalahan internal pada server.',
                    'INTERNAL_SERVER_ERROR',
                    500,
                ),
            };
        });
    })->create();
