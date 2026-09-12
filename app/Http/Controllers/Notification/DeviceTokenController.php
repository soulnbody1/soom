<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\StoreDeviceTokenRequest;
use App\Services\Notification\DeviceTokenRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function __construct(private readonly DeviceTokenRegistry $devices) {}

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

    public function destroy(Request $request): JsonResponse
    {
        $this->devices->forgetForUser($request->user(), $request->input('token'));

        return response()->json([
            'success' => true,
            'message' => 'تم حذف رمز الجهاز.',
        ]);
    }
}
