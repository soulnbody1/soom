<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\ValueObjects\Money;
use App\DTO\Auction\CreateAuctionInputDTO;
use App\DTO\Auction\CreateAuctionRecordDTO;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionConfigurationRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionMediaService;
use App\Services\Auction\Support\AuctionMetricsRecorder;
use App\Services\Auction\Support\AuctionTransaction;

final class CreateAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionMetricsRecorder $metrics,
        private readonly AuctionAudit $audit,
        private readonly AuctionTermsRepository $terms,
        private readonly AuctionConfigurationRepository $configurations,
        private readonly AuctionRepository $auctions,
        private readonly AuctionMediaService $mediaService,
    ) {}

    public function execute(CreateAuctionInputDTO $input, int $sellerId): Auction
    {
        return $this->transaction->run(function () use ($input, $sellerId): Auction {
            $currency = $input->currency_code;
            $config = $this->configurations->getActiveConfiguration();
            $terms = $this->terms->getActiveTermsVersion();

            $starting = Money::fromDecimalString($input->starting_amount, $currency);
            $reserve = $input->reserve_amount !== null
                ? Money::fromDecimalString($input->reserve_amount, $currency)
                : null;

            $recordDTO = new CreateAuctionRecordDTO(
                seller_id: $sellerId,
                category_id: $input->category_id,
                country_id: $input->country_id,
                state_id: $input->state_id,
                city_id: $input->city_id,
                terms_version_id: $terms?->id,
                configuration_version_id: $config->id,
                currency_code: $currency,
                title: $input->title,
                description: $input->description,
                latitude: $input->latitude,
                longitude: $input->longitude,
                status: AuctionStatus::Draft,
                starting_amount_minor: $starting->minor,
                reserve_amount_minor: $reserve?->minor,
                minimum_bid_increment_minor: $config->minimum_bid_increment_minor,
                seller_deposit_amount_minor: $config->seller_deposit_minor,
                bidder_deposit_amount_minor: $config->bidder_deposit_minor,
                platform_fee_type: $config->platform_fee_type,
                platform_fee_basis_points: $config->platform_fee_basis_points,
                platform_fee_fixed_minor: $config->platform_fee_fixed_minor,
                winner_payment_deadline_hours: $config->winner_payment_deadline_hours,
                handover_deadline_hours: $config->handover_deadline_hours,
                starts_at: $input->starts_at,
                ends_at: $input->ends_at,
                original_ends_at: $input->ends_at,
                extension_window_seconds: $config->extension_window_seconds,
                extension_duration_seconds: $config->extension_duration_seconds,
                maximum_extension_count: $config->maximum_extension_count,
            );

            $auction = $this->auctions->createFromDTO($recordDTO);

            $this->metrics->ensure($auction);
            $this->mediaService->storeAuctionMedia($auction, $input->media);
            $this->audit->log('auction.created', $auction, $sellerId, 'user');

            return $auction->load(['media', 'category', 'country', 'state', 'city', 'metric']);
        });
    }
}
