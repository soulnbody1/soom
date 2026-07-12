<?php

declare(strict_types=1);

namespace App\DTO\Auction;

final readonly class DepositHoldDecisionDTO
{
    public function __construct(
        public int $userId,
        public int $candidateRank,
        public string $policy,
        public string $releaseTrigger,
    ) {}

    public function metadata(): array
    {
        return [
            'candidate_rank' => $this->candidateRank,
            'policy' => $this->policy,
            'release_trigger' => $this->releaseTrigger,
        ];
    }
}
