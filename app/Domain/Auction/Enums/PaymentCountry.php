<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentCountry: string
{
    case Jordan = 'JO';
    case Egypt = 'EG';

    public static function codes(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Jordan => 'Jordan',
            self::Egypt => 'Egypt',
        };
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::Jordan => 'JOD',
            self::Egypt => 'EGP',
        };
    }
}
