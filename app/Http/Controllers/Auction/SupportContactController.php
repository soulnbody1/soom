<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'التواصل مع الدعم', description: 'قنوات التواصل مع دعم المزادات المعروضة للمستخدمين.', weight: 16)]
final class SupportContactController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض بيانات التواصل مع الدعم',
        description: 'يعرض قنوات التواصل مع فريق الدعم وأوقات العمل. تُرجع القيمة null لأي قناة غير مهيّأة في بيئة التشغيل.'
    )]
    #[Response(200, description: 'قنوات التواصل مع الدعم وأوقات العمل.')]
    public function show(): JsonResponse
    {
        $contact = (array) config('support.contact', []);

        $payload = [
            'whatsapp' => $this->channel($contact['whatsapp'] ?? null),
            'phone' => $this->channel($contact['phone'] ?? null),
            'email' => $this->channel($contact['email'] ?? null),
            'availability' => $this->channel($contact['availability'] ?? null),
        ];

        return $this->sendResponse($payload, __('auction.messages.support_contact_fetched'));
    }

    private function channel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
