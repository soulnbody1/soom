<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Models\Auction\PaymentTransaction;
use Illuminate\Contracts\View\View;

final class PaymentReturnController extends Controller
{
    public function show(string $paymentTransaction): View
    {
        $transaction = PaymentTransaction::where('public_id', $paymentTransaction)->first();

        $status = $transaction?->status ?? PaymentTransactionStatus::Pending;

        return view('payments.return', [
            'found' => $transaction !== null,
            'reference' => $paymentTransaction,
            'status' => $status->value,
            'outcome' => $this->outcome($status),
            'amount' => $transaction === null ? null : Money::fromMinorUnits(
                (int) $transaction->amount_minor,
                (string) $transaction->currency_code
            )->toDecimalString(),
            'currency' => $transaction?->currency_code,
            'deepLink' => route('open.soom'),
        ]);
    }

    private function outcome(PaymentTransactionStatus $status): string
    {
        return match ($status) {
            PaymentTransactionStatus::Succeeded => 'success',
            PaymentTransactionStatus::Pending => 'pending',
            default => 'failed',
        };
    }
}
