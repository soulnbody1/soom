<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProtectApiDocumentation
{
    public function handle(Request $request, Closure $next): Response
    {
        $docsHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        abort_unless(
            is_string($docsHost) && hash_equals(strtolower($docsHost), strtolower($request->host())),
            404
        );

        $expectedUsername = (string) config('scramble.auth.username');
        $expectedPassword = (string) config('scramble.auth.password');

        abort_if($expectedUsername === '' || $expectedPassword === '', 404);

        $authenticated = hash_equals($expectedUsername, (string) $request->getUser())
            && hash_equals($expectedPassword, (string) $request->getPassword());

        if (! $authenticated) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Soom API Documentation", charset="UTF-8"',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }
}
