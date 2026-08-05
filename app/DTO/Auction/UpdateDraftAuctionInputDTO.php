<?php

declare(strict_types=1);

namespace App\DTO\Auction;

final readonly class UpdateDraftAuctionInputDTO extends BaseAuctionDTO
{
    public function __construct(
        public array $provided,
        public ?int $category_id,
        public ?int $country_id,
        public ?int $state_id,
        public ?int $city_id,
        public ?string $title,
        public ?string $description,
        public ?string $currency_code,
        public ?string $starting_amount,
        public ?string $reserve_amount,
        public ?string $latitude,
        public ?string $longitude,
        public ?string $starts_at,
        public ?string $ends_at,
        public ?array $media,
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            provided: array_keys($data),
            category_id: array_key_exists('category_id', $data) ? (int) $data['category_id'] : null,
            country_id: array_key_exists('country_id', $data) ? (int) $data['country_id'] : null,
            state_id: array_key_exists('state_id', $data) && $data['state_id'] !== null ? (int) $data['state_id'] : null,
            city_id: array_key_exists('city_id', $data) && $data['city_id'] !== null ? (int) $data['city_id'] : null,
            title: array_key_exists('title', $data) ? (string) $data['title'] : null,
            description: array_key_exists('description', $data) ? strip_tags((string) $data['description']) : null,
            currency_code: array_key_exists('currency_code', $data) ? strtoupper((string) $data['currency_code']) : null,
            starting_amount: array_key_exists('starting_amount', $data) ? (string) $data['starting_amount'] : null,
            reserve_amount: array_key_exists('reserve_amount', $data) && $data['reserve_amount'] !== null ? (string) $data['reserve_amount'] : null,
            latitude: array_key_exists('latitude', $data) && $data['latitude'] !== null ? (string) $data['latitude'] : null,
            longitude: array_key_exists('longitude', $data) && $data['longitude'] !== null ? (string) $data['longitude'] : null,
            starts_at: array_key_exists('starts_at', $data) ? (string) $data['starts_at'] : null,
            ends_at: array_key_exists('ends_at', $data) ? (string) $data['ends_at'] : null,
            media: array_key_exists('media', $data) ? (array) ($data['media'] ?? []) : null,
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }

    public function toArray(): array
    {
        return [
            'provided' => $this->provided,
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
