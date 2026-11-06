<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class AutoLoginForLocal
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal() && config('app.auto_login', false) && ! app()->runningUnitTests() && ! defined('PHPUNIT_COMPOSER_INSTALL')) {
            if ($request->has('auto')) {
                $request->session()->forget('manual_logout');
            }

            if ($request->is('login') && $request->session()->get('manual_logout')) {
                return $next($request);
            }

            if (! Auth::check()) {
                try {
                    $user = User::where('role', 'admin')->first()
                        ?? User::where('email', 'admin@logistikku.test')->first()
                        ?? User::first();

                    if (! $user && Schema::hasTable('users')) {
                        $user = User::create([
                            'name' => 'Administrator',
                            'email' => 'admin@logistikku.test',
                            'role' => 'admin',
                            'password' => Hash::make('password'),
                        ]);
                    }

                    if ($user) {
                        Auth::login($user, true);
                    }
                } catch (\Throwable) {
                    // Abaikan jika database belum siap
                }
            }
        }

        return $next($request);
    }
}
