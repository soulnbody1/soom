<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\StoreDeviceTokenRequest;
use App\Services\Notification\DeviceTokenRegistry;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'الإشعارات', description: 'إشعارات المستخدم وتسجيل أجهزة استقبال الإشعارات وحالة القراءة.', weight: 10)]
class DeviceTokenController extends Controller
{
    public function __construct(private readonly DeviceTokenRegistry $devices) {}

    #[Endpoint(title: 'تسجيل رمز جهاز', description: 'يسجّل أو يحدّث رمز الجهاز المستخدم لاستقبال الإشعارات الفورية للمستخدم الحالي.')]
    #[Response(200, description: 'تم تسجيل رمز الجهاز بنجاح.')]
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $this->devices->remember(
            $request->user(),
            $request->string('token')->toString(),
            $request->input('platform')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل رمز الجهاز.',
        ]);
    }

    #[Endpoint(title: 'حذف رمز جهاز', description: 'يلغي تسجيل رمز جهاز محدد للمستخدم الحالي ويوقف توجيه الإشعارات إليه.')]
    #[BodyParameter('token', description: 'رمز الجهاز المطلوب إلغاء تسجيله.', required: true, type: 'string')]
    #[Response(200, description: 'تم حذف رمز الجهاز بنجاح.')]
    public function destroy(Request $request): JsonResponse
    {
        $this->devices->forgetForUser($request->user(), $request->input('token'));

        return response()->json([
            'success' => true,
            'message' => 'تم حذف رمز الجهاز.',
        ]);
    }
}
