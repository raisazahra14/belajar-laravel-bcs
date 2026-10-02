<?php

namespace App\Providers;

use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
