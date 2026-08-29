<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentMethodResource extends JsonResource
{
    private bool $withTransferDetails = false;

    private bool $withAdministration = false;

    private array $providerStatus = [];

    public function withTransferDetails(): self
    {
        $this->withTransferDetails = true;

        return $this;
    }

    public function withAdministration(array $providerStatus = []): self
    {
        $this->withAdministration = true;
        $this->providerStatus = $providerStatus;

        return $this;
    }

    public static function detailedCollection($resource): array
    {
        return collect($resource)
            ->map(fn ($method) => (new self($method))->withTransferDetails())
            ->all();
    }

    public static function administeredCollection($resource, array $providerStatus = []): array
    {
        return collect($resource)
            ->map(fn ($method) => (new self($method))->withTransferDetails()->withAdministration($providerStatus))
            ->all();
    }

    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'channel' => $this->channel->value,
            'rail' => $this->rail->value,
            'provider_code' => $this->provider_code,
            'identifier_type' => $this->identifier_type,
            'requires_manual_review' => $this->requires_manual_review,
            'is_active' => $this->is_active,
            'is_sandbox' => $this->is_sandbox,
            'display_order' => $this->display_order,
        ];

        if ($this->withTransferDetails) {
            $payload += [
                'recipient_name' => $this->recipient_name,
                'identifier_value' => $this->identifier_value,
                'instructions' => $this->instructions,
            ];
        }

        if (! $this->withAdministration) {
            return $payload;
        }

        $status = $this->providerStatus[(string) $this->provider_code] ?? null;

        return $payload + [
            'allowed_purposes' => $this->allowed_purposes ?? [],
            'country_codes' => $this->country_codes ?? [],
            'currency_codes' => $this->currency_codes ?? [],
            'min_amount_minor' => $this->min_amount_minor,
            'max_amount_minor' => $this->max_amount_minor,
            'provider' => $status,
        ];
    }
}
