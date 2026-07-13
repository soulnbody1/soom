<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\AuctionCancellationTrigger;
use Illuminate\Support\Carbon;

final readonly class AuctionCancellationContextDTO
{
    public function __construct(
        public int $auctionId,
        public AuctionCancellationTrigger $trigger,
        public ?int $actorId,
        public string $actorType,
        public string $reasonCode,
        public string $reasonText,
        public string $liability = 'neutral',
        public ?int $disputeId = null,
        public ?Carbon $requestedAt = null,
        public array $metadata = [],
    ) {}

    public static function fromLegacy(
        AuctionCancellationTrigger $trigger,
        int $auctionId,
        int $actorId,
        string $actorType,
        string $reason,
        ?string $reasonCode = null,
        ?string $liability = null,
    ): self {
        return new self(
            auctionId: $auctionId,
            trigger: $trigger,
            actorId: $actorId,
            actorType: $actorType,
            reasonCode: $reasonCode ?: self::reasonCodeFromText($reason),
            reasonText: trim($reason),
            liability: $liability ?: self::liabilityFromText($reason),
            requestedAt: Carbon::now(),
        );
    }

    public function operationKey(): string
    {
        return "auction:{$this->auctionId}:cancel";
    }

    public function auditMetadata(): array
    {
        return [
            'trigger' => $this->trigger->value,
            'actor_id' => $this->actorId,
            'actor_type' => $this->actorType,
            'reason_code' => $this->reasonCode,
            'reason_text' => $this->reasonText,
            'liability' => $this->liability,
            'dispute_id' => $this->disputeId,
            'cancellation_operation_key' => $this->operationKey(),
            ...$this->metadata,
        ];
    }

    private static function reasonCodeFromText(string $reason): string
    {
        $normalized = strtolower(trim($reason));

        return match (true) {
            str_contains($normalized, 'platform_fault'), str_contains($normalized, 'platform fault') => 'platform_fault',
            str_contains($normalized, 'seller_fault'), str_contains($normalized, 'seller fault'), str_contains($normalized, 'seller_breach') => 'seller_breach',
            str_contains($normalized, 'fraud') => 'fraud',
            str_contains($normalized, 'compliance') => 'compliance',
            str_contains($normalized, 'buyer_fault'), str_contains($normalized, 'buyer fault') => 'buyer_fault',
            default => 'neutral',
        };
    }

    private static function liabilityFromText(string $reason): string
    {
        return match (self::reasonCodeFromText($reason)) {
            'platform_fault' => 'platform',
            'seller_breach' => 'seller',
            'buyer_fault' => 'buyer',
            'fraud', 'compliance' => 'manual_review',
            default => 'neutral',
        };
    }
}
