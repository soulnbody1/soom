<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\ValueObjects\BillingReference;
use App\Models\Auction\PaymentBillingReference;

final class PaymentBillingReferenceRepository
{
    public function findForUser(int $userId, string $provider): ?PaymentBillingReference
    {
        return PaymentBillingReference::where('user_id', $userId)
            ->where('provider', $provider)
            ->first();
    }

    public function findByReference(string $provider, BillingReference $reference): ?PaymentBillingReference
    {
        return PaymentBillingReference::where('provider', $provider)
            ->where('reference', $reference->value)
            ->first();
    }

    public function create(int $userId, string $provider, BillingReference $reference): PaymentBillingReference
    {
        return PaymentBillingReference::create([
            'user_id' => $userId,
            'provider' => $provider,
            'reference' => $reference->value,
            'allocated_at' => now(),
        ]);
    }
}
