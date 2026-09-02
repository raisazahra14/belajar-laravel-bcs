<?php

namespace App\Providers;

use App\Models\StockPredictionNotification;
use App\Models\User;
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
        Gate::define('run-stock-prediction', fn (User $user): bool => in_array($user->role, ['admin', 'manager'], true));
        Gate::define('approve-restock', fn (User $user): bool => in_array($user->role, ['admin', 'manager'], true));

        View::composer('layouts.skydash', function ($view): void {
            $predictionQuery = auth()->check()
                ? StockPredictionNotification::unreadFor(auth()->user())
                : null;
            $predictionNotifications = $predictionQuery
                ? (clone $predictionQuery)->with('barang')->latest()->limit(5)->get()
                : collect();
            $unreadPredictionCount = $predictionQuery ? (clone $predictionQuery)->count() : 0;
            $ocrNotifications = auth()->check()
                ? auth()->user()->notifications()->where('type', 'document-verification')->latest()->limit(5)->get()
                : collect();
            $unreadOcrCount = auth()->check()
                ? auth()->user()->unreadNotifications()->where('type', 'document-verification')->count()
                : 0;

            $view->with([
                'unreadPredictions' => $predictionNotifications,
                'unreadPredictionCount' => $unreadPredictionCount,
                'ocrNotifications' => $ocrNotifications,
                'unreadOcrCount' => $unreadOcrCount,
            ]);
        });
    }
}
