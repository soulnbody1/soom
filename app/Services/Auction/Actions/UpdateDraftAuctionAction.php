<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\Money;
use App\DTO\Auction\UpdateDraftAuctionInputDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionMediaService;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

final class UpdateDraftAuctionAction
{
    private const EDITABLE_ATTRIBUTES = [
        'category_id',
        'state_id',
        'city_id',
        'title',
        'description',
        'latitude',
        'longitude',
    ];

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionRepository $auctions,
        private readonly AuctionTermsRepository $terms,
        private readonly AuctionMediaService $mediaService,
        private readonly AuctionAudit $audit,
    ) {}

    public function execute(Auction $auction, UpdateDraftAuctionInputDTO $input, int $sellerId): Auction
    {
        $storedMedia = [];
        $replacedMedia = [];

        try {
            $updated = $this->transaction->run(function () use ($auction, $input, $sellerId, &$storedMedia, &$replacedMedia): Auction {
                $auction = $this->auctions->lockForStateChange($auction->id);

                if ($auction->seller_id !== $sellerId) {
                    throw AuctionException::domain('seller_only_update_auction');
                }

                if ($auction->status !== AuctionStatus::Draft) {
                    throw AuctionException::domain('auction_not_editable');
                }

                $auction->fill($this->attributesFrom($input, $auction));

                if ($auction->terms_version_id === null) {
                    $auction->forceFill(['terms_version_id' => $this->terms->getActiveTermsVersion()?->id]);
                }

                $changed = array_keys($auction->getDirty());

                if ($changed !== []) {
                    $this->auctions->save($auction);
                }

                if ($input->media !== null) {
                    $replacedMedia = $this->replaceMedia($auction, $input->media, $storedMedia);
                    $changed[] = 'media';
                }

                if ($changed !== []) {
                    $this->audit->log('auction.updated', $auction, $sellerId, 'user', [
                        'changed_fields' => array_values(array_unique($changed)),
                    ]);
                }

                return $auction;
            });
        } catch (Throwable $exception) {
            $this->mediaService->deleteStoredMedia($storedMedia);

            throw $exception;
        }

        $this->mediaService->deleteStoredMedia($replacedMedia);

        return $updated->refresh()->load(['media', 'category', 'country', 'state', 'city', 'metric']);
    }

    private function attributesFrom(UpdateDraftAuctionInputDTO $input, Auction $auction): array
    {
        $attributes = [];

        foreach (self::EDITABLE_ATTRIBUTES as $field) {
            if ($input->has($field)) {
                $attributes[$field] = $input->{$field};
            }
        }

        $currency = $input->has('currency_code') ? $input->currency_code : (string) $auction->currency_code;

        if ($input->has('currency_code')) {
            $attributes['currency_code'] = $currency;
        }

        if ($input->has('currency_code')
            && $currency !== (string) $auction->currency_code
            && ! $input->has('starting_amount')) {
            throw AuctionException::domain('auction_amount_required_on_currency_change');
        }

        if ($input->has('starting_amount')) {
            $attributes['starting_amount_minor'] = $this->toMinor((string) $input->starting_amount, (string) $currency);
        }

        if ($input->has('reserve_amount')) {
            $attributes['reserve_amount_minor'] = $input->reserve_amount === null
                ? null
                : $this->toMinor($input->reserve_amount, (string) $currency);
        }

        if ($input->has('starts_at')) {
            $attributes['starts_at'] = Carbon::parse((string) $input->starts_at);
        }

        if ($input->has('ends_at')) {
            $endsAt = Carbon::parse((string) $input->ends_at);
            $attributes['ends_at'] = $endsAt;
            $attributes['original_ends_at'] = $endsAt;
        }

        return $attributes;
    }

    private function toMinor(string $amount, string $currency): int
    {
        try {
            return Money::fromDecimalString($amount, $currency)->minor;
        } catch (InvalidArgumentException) {
            throw AuctionException::domain('auction_amount_invalid');
        }
    }

    private function replaceMedia(Auction $auction, array $media, array &$storedMedia): array
    {
        $existing = AuctionMedia::query()
            ->where('auction_id', $auction->id)
            ->orderBy('sort_order')
            ->get(['id', 'disk', 'path'])
            ->map(static fn (AuctionMedia $item): array => ['disk' => (string) $item->disk, 'path' => (string) $item->path])
            ->all();

        AuctionMedia::query()->where('auction_id', $auction->id)->delete();

        $storedMedia = $this->mediaService->storeAuctionMedia($auction, $media);

        return $existing;
    }
}
