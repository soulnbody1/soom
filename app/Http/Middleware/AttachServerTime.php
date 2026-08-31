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

    public const REQUEST_FLAG = 'auction.attach_server_time';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::REQUEST_FLAG, true);

        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        if (str_contains((string) $response->getContent(), '"server_time"')) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        $response->setData(self::stamp($payload));

        return $response;
    }

    public static function stamp(array $payload): array
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['server_time'] = Carbon::now()->toIso8601String();
        $payload['meta'] = $meta;

        return $payload;
    }
}
