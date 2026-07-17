<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a Sanctum bearer token on public routes without requiring one.
 *
 * Valid tokens authenticate the user for the request (existing policies and
 * resource gates then apply); absent, invalid, or expired tokens leave the
 * request as a guest instead of aborting like `auth:sanctum` would.
 */
final class OptionalSanctumAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
