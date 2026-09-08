<?php

declare(strict_types=1);

namespace App\Rules\Auction;

use App\Services\Auction\Payments\Fees\CustomerFeeSchedule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class ValidCustomerFeeSchedule implements ValidationRule
{
    public function __construct(private readonly ?string $basis) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('auction.errors.payment_fee_schedule_invalid'));

            return;
        }

        try {
            CustomerFeeSchedule::fromConfiguration($this->basis, $value);
        } catch (InvalidArgumentException) {
            $fail(__('auction.errors.payment_fee_schedule_invalid'));
        }
    }
}
