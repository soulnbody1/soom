<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

/**
 * Why a bill lookup returned nothing.
 *
 * Deliberately abstract: providers translate these into their own wire codes,
 * so no protocol error code ever reaches the core.
 */
enum BillRejectionReason: string
{
    /** No payer is registered under the quoted billing reference. */
    case UnknownBillingReference = 'unknown_billing_reference';

    /** The payer exists but owes nothing that can be presented right now. */
    case NoPayableBills = 'no_payable_bills';

    /** The quoted bill reference does not exist for this payer. */
    case BillNotFound = 'bill_not_found';

    /** The bill exists but its obligation is no longer owed. */
    case BillNotPayable = 'bill_not_payable';

    /** The bill exists and has already been settled. */
    case BillAlreadyPaid = 'bill_already_paid';
}
