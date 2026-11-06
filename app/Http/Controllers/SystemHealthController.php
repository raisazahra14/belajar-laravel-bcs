<?php

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function index(Request $request, SystemHealthService $healthService): View
    {
        $probeProcesses = $request->has('deep')
            ? $request->boolean('deep')
            : (! app()->runningUnitTests() && ! app()->environment('testing'));

        $diagnostics = $healthService->checkAll($probeProcesses);

        return view('system-health.index', [
            'title' => 'Diagnostik & Kesehatan Infrastruktur · LogistikKu',
            'diagnostics' => $diagnostics,
        ]);
    }

    public function json(Request $request, SystemHealthService $healthService): JsonResponse
    {
        $probeProcesses = $request->has('deep')
            ? $request->boolean('deep')
            : (! app()->runningUnitTests() && ! app()->environment('testing'));

        $diagnostics = $healthService->checkAll($probeProcesses);

        return response()->json($diagnostics, 200);
    }
}
