<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

enum SupportTicketStatus: string
{
    case New = 'new';
    case Open = 'open';
    case WaitingCustomer = 'waiting_customer';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function acceptsCustomerMessages(): bool
    {
        return $this !== self::Closed;
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::New => in_array($next, [self::Open, self::OnHold, self::Resolved, self::Closed], true),
            self::Open => in_array($next, [self::WaitingCustomer, self::OnHold, self::Resolved, self::Closed], true),
            self::WaitingCustomer => in_array($next, [self::Open, self::OnHold, self::Resolved, self::Closed], true),
            self::OnHold => in_array($next, [self::Open, self::Resolved, self::Closed], true),
            self::Resolved => in_array($next, [self::Open, self::Closed], true),
            self::Closed => false,
        };
    }
}
