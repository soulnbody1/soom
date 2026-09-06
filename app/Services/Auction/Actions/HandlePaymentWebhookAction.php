<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentProviderEvent;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\PaymentProviderEventRepository;
use App\Services\Auction\Payments\Contracts\DerivesStatusFromEvent;
use App\Services\Auction\Payments\Contracts\PaymentProvider;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPayloadRedactor;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class HandlePaymentWebhookAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionPaymentRepository $payments,
        private readonly PaymentProviderEventRepository $events,
        private readonly PaymentProviderFactory $providers,
        private readonly ProviderPayloadRedactor $redactor,
        private readonly SettleOnlinePaymentAction $settle,
    ) {}

    public function execute(string $providerCode, Request $request): string
    {
        if (! $this->providers->isRegistered($providerCode)) {
            throw AuctionException::domain('payment_provider_unknown', ['code' => $providerCode], 404);
        }

        if (strlen((string) $request->getContent()) > $this->maxBodyBytes()) {
            throw AuctionException::domain('payment_webhook_payload_too_large', [], 413);
        }

        $provider = $this->providers->make($providerCode);
        $event = $provider->parseEvent($request);

        if (! $event->signatureVerified) {
            $rejected = $this->recordEvent(
                $providerCode,
                'unverified:'.Str::ulid(),
                $event->eventType,
                $event->providerTransactionId,
                false,
                $event->payload
            );
            $this->markProcessed($rejected, 'signature_invalid');

            throw AuctionException::domain('payment_webhook_signature_invalid', [], 401);
        }

        if ($event->eventId === '' || $event->providerTransactionId === '') {
            throw AuctionException::domain('payment_webhook_invalid', [], 400);
        }

        $record = $this->recordEvent(
            $providerCode,
            $event->eventId,
            $event->eventType,
            $event->providerTransactionId,
            true,
            $event->payload
        );

        if (! $record->wasRecentlyCreated && $this->isSettled($record)) {
            return 'duplicate';
        }

        $transaction = $this->resolveTransaction($providerCode, $event);

        if (! $transaction) {
            $this->markUnresolved($record, 'transaction_not_found');

            return 'unmatched';
        }

        $this->link($record, (int) $transaction->id);

        try {
            $status = $this->statusFor($provider, $event);
        } catch (Throwable) {
            throw AuctionException::domain('payment_provider_unavailable', [], 502);
        }

        $this->settle->execute((int) $transaction->id, $status);
        $this->markProcessed($record, null);

        return 'processed';
    }

    /**
     * The event payload is never trusted for money when the provider can be
     * re-read: we go back to the provider for the authoritative status. Only a
     * provider that declares no inquiry capability may state the outcome in the
     * event itself, and even then the amount is re-checked against the expected
     * obligation before anything is applied.
     */
    private function statusFor(PaymentProvider $provider, ProviderEvent $event): ProviderPaymentStatus
    {
        if (! $provider->capabilities()->supportsInquiry && $provider instanceof DerivesStatusFromEvent) {
            return $provider->statusFromEvent($event);
        }

        return $provider->fetchStatus($event->providerTransactionId);
    }

    private function resolveTransaction(string $providerCode, ProviderEvent $event): ?PaymentTransaction
    {
        return $this->transaction->run(function () use ($providerCode, $event): ?PaymentTransaction {
            $matched = $this->payments->lockTransactionByProviderReference($providerCode, $event->providerTransactionId);

            if ($matched || $event->merchantReference === null || $event->merchantReference === '') {
                return $matched;
            }

            return $this->payments->lockTransactionByMerchantReference($providerCode, $event->merchantReference);
        });
    }

    private function recordEvent(
        string $providerCode,
        string $eventId,
        string $eventType,
        string $providerTransactionId,
        bool $signatureVerified,
        array $payload
    ): PaymentProviderEvent {
        return $this->transaction->run(fn (): PaymentProviderEvent => $this->events->firstOrCreate(
            ['provider' => $providerCode, 'event_id' => mb_substr($eventId, 0, 190)],
            [
                'event_type' => mb_substr($eventType !== '' ? $eventType : 'unknown', 0, 80),
                'provider_transaction_id' => mb_substr($providerTransactionId, 0, 190),
                'signature_verified' => $signatureVerified,
                'payload_redacted' => $this->redactor->redact($payload),
                'received_at' => Carbon::now(),
            ]
        ));
    }

    private function link(PaymentProviderEvent $record, int $transactionId): void
    {
        $this->transaction->run(function () use ($record, $transactionId): void {
            $locked = $this->events->lockById($record->id);
            $locked->forceFill(['payment_transaction_id' => $transactionId]);
            $this->events->save($locked);
        });
    }

    /**
     * A schedule that retries delivery only helps if a retry can still change
     * the outcome. An event we could not match is therefore left unfinished, so
     * a later attempt — once the claim exists, or once the cause is fixed — runs
     * the lookup again instead of being waved through as a duplicate.
     */
    private function isSettled(PaymentProviderEvent $record): bool
    {
        return $record->processed_at !== null && $record->process_error === null;
    }

    private function markUnresolved(PaymentProviderEvent $record, string $error): void
    {
        $this->transaction->run(function () use ($record, $error): void {
            $locked = $this->events->lockById($record->id);
            $locked->forceFill(['process_error' => $error]);
            $this->events->save($locked);
        });
    }

    private function markProcessed(PaymentProviderEvent $record, ?string $error): void
    {
        $this->transaction->run(function () use ($record, $error): void {
            $locked = $this->events->lockById($record->id);
            $locked->forceFill([
                'processed_at' => Carbon::now(),
                'process_error' => $error,
            ]);
            $this->events->save($locked);
        });
    }

    private function maxBodyBytes(): int
    {
        return max(1024, (int) config('auction.payments.webhook_max_body_bytes', 65536));
    }
}
