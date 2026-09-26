<?php

declare(strict_types=1);

namespace App\Contracts\Market;

interface MarketBootstrapProfile extends MarketProfile
{
    /** @return array{whatsapp: string, phone: string, email: string, availability: string} */
    public function supportContact(): array;

    /** @return array{name: string, prompt_version: string, result_schema_version: int, policy: array<string, mixed>} */
    public function contentReviewPolicy(): array;

    /** @return array<string, mixed> */
    public function contentReviewSettings(): array;
}
