<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Repositories\Auction\AuctionTermsRepository;
use Illuminate\Support\Carbon;

final class AuctionTermsAcceptanceRecorder
{
    public function __construct(
        private readonly AuctionTermsRepository $terms,
    ) {}

    public function resolveVersionId(Auction $auction, string $termsVersionPublicId, ?int $requiredVersionId = null): int
    {
        $required = $requiredVersionId ?? ($auction->terms_version_id === null ? null : (int) $auction->terms_version_id);

        if ($required === null) {
            throw AuctionException::domain('terms_missing');
        }

        $version = $this->terms->findVersionByPublicId($termsVersionPublicId);

        if (! $version || (int) $version->id !== $required) {
            throw AuctionException::domain('terms_version_mismatch', [], 409);
        }

        return $required;
    }

    public function record(
        Auction $auction,
        int $userId,
        int $termsVersionId,
        ?int $participantId,
        ?string $ipAddress,
        ?string $userAgent
    ): AuctionTermsAcceptance {
        return $this->terms->firstOrCreateAcceptance(
            [
                'auction_id' => $auction->id,
                'user_id' => $userId,
                'terms_version_id' => $termsVersionId,
            ],
            [
                'participant_id' => $participantId,
                'ip_hash' => $ipAddress ? hash('sha256', $ipAddress.config('app.key')) : null,
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                'accepted_at' => Carbon::now(),
            ]
        );
    }
}
