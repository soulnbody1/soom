<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionAudit;
use App\Application\Auction\Services\AuctionMetricsRecorder;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\ValueObjects\Money;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use App\Models\Auction\AuctionTermsVersion;
use Illuminate\Http\UploadedFile;

final class CreateAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionMetricsRecorder $metrics,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(array $data, int $sellerId): Auction
    {
        return $this->transaction->run(function () use ($data, $sellerId): Auction {
            $currency = strtoupper((string) ($data['currency_code'] ?? 'JOD'));
            $starting = Money::fromDecimalString($data['starting_amount'], $currency);
            $reserve = isset($data['reserve_amount']) && $data['reserve_amount'] !== null
                ? Money::fromDecimalString($data['reserve_amount'], $currency)
                : null;
            $minimumIncrement = Money::fromDecimalString($data['minimum_bid_increment'], $currency);
            $sellerDeposit = Money::fromDecimalString($data['seller_deposit_amount'] ?? '0', $currency);
            $bidderDeposit = Money::fromDecimalString($data['bidder_deposit_amount'] ?? '0', $currency);

            $terms = AuctionTermsVersion::query()->where('is_active', true)->latest('version_number')->first();

            $auction = Auction::create([
                'seller_id' => $sellerId,
                'category_id' => $data['category_id'],
                'country_id' => $data['country_id'],
                'state_id' => $data['state_id'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'terms_version_id' => $terms?->id,
                'currency_code' => $currency,
                'title' => $data['title'],
                'description' => strip_tags((string) $data['description']),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'status' => AuctionStatus::Draft,
                'starting_amount_minor' => $starting->minor,
                'reserve_amount_minor' => $reserve?->minor,
                'minimum_bid_increment_minor' => $minimumIncrement->minor,
                'seller_deposit_amount_minor' => $sellerDeposit->minor,
                'bidder_deposit_amount_minor' => $bidderDeposit->minor,
                'platform_fee_type' => $data['platform_fee_type'] ?? 'percentage',
                'platform_fee_basis_points' => $data['platform_fee_basis_points'] ?? 0,
                'platform_fee_fixed_minor' => isset($data['platform_fee_fixed_amount'])
                    ? Money::fromDecimalString($data['platform_fee_fixed_amount'], $currency)->minor
                    : 0,
                'winner_payment_deadline_hours' => $data['winner_payment_deadline_hours'] ?? 48,
                'handover_deadline_hours' => $data['handover_deadline_hours'] ?? 72,
                'starts_at' => $data['starts_at'] ?? null,
                'original_ends_at' => $data['ends_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'extension_window_seconds' => $data['extension_window_seconds'] ?? 300,
                'extension_duration_seconds' => $data['extension_duration_seconds'] ?? 600,
                'maximum_extension_count' => $data['maximum_extension_count'] ?? 6,
            ]);

            $this->metrics->ensure($auction);
            $this->storeMedia($auction, $data['media'] ?? []);
            $this->audit->log('auction.created', $auction, $sellerId, 'user');

            return $auction->load(['media', 'category', 'country', 'state', 'city', 'metric']);
        });
    }

    /**
     * @param array<int, UploadedFile> $media
     */
    private function storeMedia(Auction $auction, array $media): void
    {
        foreach ($media as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("auctions/{$auction->public_id}", 'spaces');

            AuctionMedia::create([
                'auction_id' => $auction->id,
                'disk' => 'spaces',
                'path' => $path,
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }
    }
}
