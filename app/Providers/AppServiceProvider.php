<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\JwtRequestGuard;
use App\Services\JwtService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::extend('jwt', function (Application $app): JwtRequestGuard {
            $guard = new JwtRequestGuard(
                fn (Request $request) => $app->make(JwtService::class)->authenticate($request->bearerToken()),
                $app['request'],
            );
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        RateLimiter::for('authentication', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? strtolower($email) : '';

            return [
                Limit::perMinute(10)->by('auth:'.$request->ip()),
                Limit::perMinute(5)->by('login:'.$identity.'|'.$request->ip()),
            ];
        });
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('api:'.($request->user()?->id ?? $request->ip())));
    }
}
