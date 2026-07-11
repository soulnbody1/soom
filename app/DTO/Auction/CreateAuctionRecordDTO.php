<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\DTO\Auction\Contracts\PersistenceDTO;

/**
 * Trusted persistence DTO for auction creation.
 * Includes platform-controlled values from AuctionConfigurationVersion.
 */
final readonly class CreateAuctionRecordDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public int $seller_id,
        public int $category_id,
        public int $country_id,
        public ?int $state_id,
        public ?int $city_id,
        public ?int $terms_version_id,
        public ?int $configuration_version_id,
        public string $currency_code,
        public string $title,
        public string $description,
        public ?string $latitude,
        public ?string $longitude,
        public AuctionStatus $status,
        public int $starting_amount_minor,
        public ?int $reserve_amount_minor,
        public int $minimum_bid_increment_minor,
        public int $seller_deposit_amount_minor,
        public int $bidder_deposit_amount_minor,
        public string $platform_fee_type,
        public int $platform_fee_basis_points,
        public int $platform_fee_fixed_minor,
        public int $winner_payment_deadline_hours,
        public int $handover_deadline_hours,
        public ?string $starts_at,
        public ?string $ends_at,
        public ?string $original_ends_at,
        public int $extension_window_seconds,
        public int $extension_duration_seconds,
        public int $maximum_extension_count,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'seller_id' => $this->seller_id,
            'category_id' => $this->category_id,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'terms_version_id' => $this->terms_version_id,
            'configuration_version_id' => $this->configuration_version_id,
            'currency_code' => $this->currency_code,
            'title' => $this->title,
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'starting_amount_minor' => $this->starting_amount_minor,
            'reserve_amount_minor' => $this->reserve_amount_minor,
            'minimum_bid_increment_minor' => $this->minimum_bid_increment_minor,
            'seller_deposit_amount_minor' => $this->seller_deposit_amount_minor,
            'bidder_deposit_amount_minor' => $this->bidder_deposit_amount_minor,
            'platform_fee_type' => $this->platform_fee_type,
            'platform_fee_basis_points' => $this->platform_fee_basis_points,
            'platform_fee_fixed_minor' => $this->platform_fee_fixed_minor,
            'winner_payment_deadline_hours' => $this->winner_payment_deadline_hours,
            'handover_deadline_hours' => $this->handover_deadline_hours,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'original_ends_at' => $this->original_ends_at,
            'extension_window_seconds' => $this->extension_window_seconds,
            'extension_duration_seconds' => $this->extension_duration_seconds,
            'maximum_extension_count' => $this->maximum_extension_count,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
