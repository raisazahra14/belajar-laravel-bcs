<?php

namespace App\Providers;

use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        $slowQueryThreshold = (int) config('database.slow_query_threshold_ms', 500);
        if ($slowQueryThreshold > 0) {
            DB::whenQueryingForLongerThan(
                $slowQueryThreshold,
                function (Connection $connection, QueryExecuted $event) use ($slowQueryThreshold): void {
                    Log::warning('Waktu query database kumulatif melewati ambang batas.', [
                        'connection' => $connection->getName(),
                        'threshold_ms' => $slowQueryThreshold,
                        'last_query_ms' => $event->time,
                        'sql_template' => $event->sql,
                    ]);
                },
            );
        }

        Gate::define('manage-barang', fn (User $user): bool => $user->role === 'admin');
        Gate::define('update-stock', fn (User $user): bool => in_array($user->role, ['admin', 'manager', 'staff'], true));
        Gate::define('view-stock-reports', fn (User $user): bool => in_array($user->role, ['admin', 'manager'], true));
        Gate::define('reverse-stock', fn (User $user): bool => in_array($user->role, ['admin', 'manager'], true));
        Gate::define('run-stock-prediction', fn (User $user): bool => in_array($user->role, ['admin', 'manager'], true));
        Gate::define('approve-restock', fn (User $user): bool => in_array($user->role, ['admin', 'manager'], true));

        View::composer('layouts.skydash', function ($view): void {
            $dropdown = auth()->check()
                ? app(NotificationCenterService::class)->dropdown(auth()->user())
                : ['unread_count' => 0, 'notifications' => collect()];

            $view->with('notificationDropdown', $dropdown);
        });
    }
}
