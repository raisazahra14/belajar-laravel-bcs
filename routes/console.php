<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('po:check-overdue --notify')
    ->dailyAt('08:00')
    ->description('Pemeriksaan harian PO yang melewati estimasi tanggal tiba');
