<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Support\Enums\SupportTicketStatus;
use PHPUnit\Framework\TestCase;

final class SupportTicketStatusTest extends TestCase
{
    public function test_closed_ticket_cannot_transition_or_accept_messages(): void
    {
        $this->assertFalse(SupportTicketStatus::Closed->acceptsCustomerMessages());
        $this->assertFalse(SupportTicketStatus::Closed->canTransitionTo(SupportTicketStatus::Open));
    }

    public function test_resolved_ticket_can_reopen_and_active_ticket_can_resolve(): void
    {
        $this->assertTrue(SupportTicketStatus::Resolved->canTransitionTo(SupportTicketStatus::Open));
        $this->assertTrue(SupportTicketStatus::Open->canTransitionTo(SupportTicketStatus::Resolved));
    }
}
