<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class AttachServerTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['server_time'] = Carbon::now()->toIso8601String();
        $payload['meta'] = $meta;

        $response->setData($payload);

        return $response;
    }
}
