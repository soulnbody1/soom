<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum NextActionCode: string
{
    case None = 'none';
    case Login = 'login';
    case Register = 'register';
    case AcceptTerms = 'accept_terms';
    case SubmitBidderDeposit = 'submit_bidder_deposit';
    case AwaitDepositReview = 'await_deposit_review';
    case PlaceBid = 'place_bid';
    case AwaitResult = 'await_result';
    case PaySettlement = 'pay_settlement';
    case AwaitPaymentReview = 'await_payment_review';
    case ConfirmReceipt = 'confirm_receipt';
    case ConfirmHandover = 'confirm_handover';
    case SubmitSellerDeposit = 'submit_seller_deposit';
    case SubmitForReview = 'submit_for_review';
    case OpenDispute = 'open_dispute';
    case ContactSupport = 'contact_support';
}
