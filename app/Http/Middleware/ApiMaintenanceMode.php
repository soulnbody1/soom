<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.api_maintenance')) {
            return response()->json([
                'status'  => 'maintenance',
                'message' => 'الموقع تحت الصيانة الآن، برجاء المحاولة لاحقاً.'
            ], 503);
        }

        return $next($request);
    }
}
