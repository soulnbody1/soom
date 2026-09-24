<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'حالة النظام', description: 'التحقق من توفر خدمة الـAPI وحالة الصيانة.', weight: 99)]
final class StatusController extends Controller
{
    #[Endpoint(title: 'فحص حالة الخدمة', description: 'يعرض حالة توفر الـAPI، ويعيد حالة الصيانة عند تفعيل وضع صيانة الواجهة البرمجية.')]
    #[Response(200, description: 'الخدمة تعمل بصورة طبيعية.')]
    #[Response(503, description: 'الخدمة في وضع الصيانة مؤقتًا.')]
    public function __invoke(): JsonResponse
    {
        if (config('app.api_maintenance')) {
            return response()->json([
                'status' => 'maintenance',
                'message' => 'الموقع تحت الصيانة الآن، برجاء المحاولة لاحقاً.',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'الموقع يعمل الآن.',
        ]);
    }
}
