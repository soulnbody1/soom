<?php

declare(strict_types=1);

namespace App\DTO\Auction;

/**
 * Contains ONLY seller-submitted data.
 * Platform-controlled fields (fees, deposits, deadlines, extensions)
 * are injected from AuctionConfigurationVersion.
 */
final readonly class CreateAuctionInputDTO extends BaseAuctionDTO
{
    public function __construct(
        public int $category_id,
        public int $country_id,
        public ?int $state_id,
        public ?int $city_id,
        public string $title,
        public string $description,
        public string $currency_code,
        public string $starting_amount,
        public ?string $reserve_amount,
        public ?string $latitude,
        public ?string $longitude,
        public string $starts_at,
        public string $ends_at,
        public array $media = [],
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            category_id: (int) $data['category_id'],
            country_id: (int) $data['country_id'],
            state_id: isset($data['state_id']) ? (int) $data['state_id'] : null,
            city_id: isset($data['city_id']) ? (int) $data['city_id'] : null,
            title: (string) $data['title'],
            description: strip_tags((string) $data['description']),
            currency_code: strtoupper((string) ($data['currency_code'] ?? 'JOD')),
            starting_amount: (string) $data['starting_amount'],
            reserve_amount: isset($data['reserve_amount']) ? (string) $data['reserve_amount'] : null,
            latitude: isset($data['latitude']) ? (string) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (string) $data['longitude'] : null,
            starts_at: (string) $data['starts_at'],
            ends_at: (string) $data['ends_at'],
            media: $data['media'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->category_id,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'title' => $this->title,
            'description' => $this->description,
            'currency_code' => $this->currency_code,
            'starting_amount' => $this->starting_amount,
            'reserve_amount' => $this->reserve_amount,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
        ];
    }
}
