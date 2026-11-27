<?php

namespace App\Providers;

use App\Models\DocumentVerification;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\DocumentVerificationPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Services\NotificationCenterService;
use App\Services\SystemHealthService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('ocr-direct', function (Request $request): Limit {
            return Limit::perMinute(6)->by((string) ($request->user()?->id ?: $request->ip()));
        });

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

        Gate::define('manage-barang', fn (User $user): bool => $user->isAdmin());
        Gate::define('view-business-analytics', fn (User $user): bool => $user->canViewBusinessAnalytics());
        Gate::define('view-financials', fn (User $user): bool => $user->canViewFinancials());
        Gate::define('view-all-verifications', fn (User $user): bool => $user->canViewAllVerifications());
        Gate::define('update-stock', fn (User $user): bool => $user->hasAnyRole(User::ROLES_ALL));
        Gate::define('view-stock-reports', fn (User $user): bool => $user->hasAnyRole(User::ROLES_MANAGEMENT));
        Gate::define('reverse-stock', fn (User $user): bool => $user->hasAnyRole(User::ROLES_MANAGEMENT));
        Gate::define('run-stock-prediction', fn (User $user): bool => $user->hasAnyRole(User::ROLES_MANAGEMENT));
        Gate::define('approve-restock', fn (User $user): bool => $user->hasAnyRole(User::ROLES_MANAGEMENT));
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(DocumentVerification::class, DocumentVerificationPolicy::class);

        View::composer('layouts.skydash', function ($view): void {
            $dropdown = auth()->check()
                ? app(NotificationCenterService::class)->dropdown(auth()->user())
                : ['unread_count' => 0, 'notifications' => collect()];

            $view->with('notificationDropdown', $dropdown);
        });

        Queue::looping(static function (): void {
            SystemHealthService::recordQueueWorkerHeartbeat();
        });

        Queue::after(static function (JobProcessed $event): void {
            if ($event->connectionName !== 'sync') {
                SystemHealthService::recordQueueWorkerHeartbeat();
            }
        });
    }
}
