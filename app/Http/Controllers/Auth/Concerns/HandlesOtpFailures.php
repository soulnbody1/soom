<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Domain\Auth\Enums\OtpVerificationStatus;
use Illuminate\Http\JsonResponse;

trait HandlesOtpFailures
{
    private function otpFailureResponse(OtpVerificationStatus $status): JsonResponse
    {
        return match ($status) {
            OtpVerificationStatus::Expired => response()->json(
                ['message' => 'رمز التحقق منتهي الصلاحية.'], 422
            ),
            OtpVerificationStatus::TooManyAttempts => response()->json(
                ['message' => 'لقد تجاوزت عدد محاولات التحقق. الرجاء طلب رمز جديد.'], 429
            ),
            default => response()->json(['message' => 'رمز التحقق غير صحيح.'], 422),
        };
    }
}
