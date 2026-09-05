<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use App\Domain\Auction\Enums\BillRejectionReason;

final readonly class BillResolution
{
    /**
     * @param  list<PresentableBill>  $bills
     */
    private function __construct(
        public array $bills,
        public ?BillRejectionReason $rejection,
    ) {}

    /**
     * @param  list<PresentableBill>  $bills
     */
    public static function presented(array $bills): self
    {
        return new self($bills, null);
    }

    public static function rejected(BillRejectionReason $reason): self
    {
        return new self([], $reason);
    }

    public function isEmpty(): bool
    {
        return $this->bills === [];
    }

    public function count(): int
    {
        return count($this->bills);
    }

    public function sole(): ?PresentableBill
    {
        return count($this->bills) === 1 ? $this->bills[0] : null;
    }
}
