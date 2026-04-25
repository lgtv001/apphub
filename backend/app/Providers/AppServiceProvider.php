<?php

namespace App\Providers;

use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Ensure Sanctum's RequestGuard forgets the cached user when the
        // underlying request changes (e.g. between test HTTP calls).
        RequestGuard::macro('setRequest', function (Request $request) {
            /** @var RequestGuard $this */
            $this->request = $request;
            $this->user    = null;  // reset cached user so the next user() call hits the DB

            return $this;
        });
    }
}
