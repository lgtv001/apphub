<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reset cached auth guards at the start of each request.
 *
 * Laravel's auth manager caches resolved guard instances in memory. In test
 * environments (where a single PHP process handles multiple HTTP requests),
 * the cached user from a previous request would be returned without hitting
 * the database, causing revoked tokens to appear valid. This middleware
 * clears the guard cache so each request authenticates fresh from the DB.
 */
class ResetAuthGuards
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::forgetGuards();

        return $next($request);
    }
}
