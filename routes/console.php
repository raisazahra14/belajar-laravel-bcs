<?php

use App\Services\SystemHealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('system:heartbeat', function () {
    $timestamp = SystemHealthService::recordSchedulerHeartbeat();
    $this->info("Scheduler heartbeat recorded at {$timestamp}");
})->purpose('Catat heartbeat aktif task scheduler untuk pemantauan kesehatan sistem');

Schedule::command('system:heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Pencatatan heartbeat berkala untuk verifikasi liveness task scheduler');

Schedule::command('po:check-overdue --notify')
    ->dailyAt('08:00')
    ->description('Pemeriksaan harian PO yang melewati estimasi tanggal tiba');

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->description('Pembersihan harian token Sanctum yang telah kedaluwarsa lebih dari 24 jam');
