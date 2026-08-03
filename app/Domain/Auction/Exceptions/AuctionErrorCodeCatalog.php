<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use Illuminate\Support\Facades\Lang;

final class AuctionErrorCodeCatalog
{
    private const PARAMETERISED_KEYS = [
        'invalid_transition',
        'unsupported_currency',
        'invalid_decimal_places',
    ];

    private ?array $messageToCode = null;

    public function codeFor(string $message): ?string
    {
        return $this->map()[$message] ?? null;
    }

    private function map(): array
    {
        if ($this->messageToCode !== null) {
            return $this->messageToCode;
        }

        $errors = Lang::get('auction.errors');
        $map = [];

        if (is_array($errors)) {
            foreach ($errors as $key => $message) {
                if (! is_string($message) || in_array($key, self::PARAMETERISED_KEYS, true)) {
                    continue;
                }

                $map[$message] ??= (string) $key;
            }
        }

        return $this->messageToCode = $map;
    }
}
