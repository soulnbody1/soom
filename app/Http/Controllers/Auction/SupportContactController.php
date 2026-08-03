<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

final class SupportContactController extends Controller
{
    use ApiResponseTrait;

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
