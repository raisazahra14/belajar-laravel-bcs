<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ApiStatusController extends Controller
{
    /**
     * Tampilkan status operasional dan metadata kontrak RESTful API v1.
     */
    public function __invoke(): JsonResponse
    {
        return $this->successResponse([
            'service' => 'LogistikKu RESTful API',
            'architecture' => 'Stateless RESTful API (Laravel Sanctum Bearer Token)',
            'maturity_model' => 'Richardson Maturity Model Level 2-3 (REST + HATEOAS)',
            'status' => 'operational',
            'supported_verbs' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'documentation' => 'docs/arsitektur_rest_api.md',
            'links' => [
                'self' => route('api.v1.status'),
            ],
        ], 'Layanan RESTful API LogistikKu v1 siap digunakan');
    }
}
