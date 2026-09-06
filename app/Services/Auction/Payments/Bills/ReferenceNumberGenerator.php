<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use InvalidArgumentException;

/**
 * Generates opaque payer-facing reference numbers.
 *
 * The shape (length, charset, trailing check digit, leading-zero rule) is
 * configuration, because each scheme constrains it differently. Nothing about
 * the platform is derivable from the output: it is drawn from a CSPRNG and
 * carries no database identifier, auction, purpose, or personal data.
 */
final class ReferenceNumberGenerator
{
    public const MAX_LENGTH = 50;

    private const NUMERIC = '0123456789';

    private const ALPHANUMERIC = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function generate(array $shape): string
    {
        $length = self::length($shape);
        $checkDigit = (bool) ($shape['check_digit'] ?? true);
        $alphabet = $this->alphabet((string) ($shape['charset'] ?? 'numeric'));

        if ($checkDigit && $alphabet !== self::NUMERIC) {
            throw new InvalidArgumentException('A check digit requires a numeric reference charset.');
        }

        $leading = self::forbidsLeadingZero($shape) ? ltrim($alphabet, '0') : $alphabet;
        $bodyLength = $checkDigit ? $length - 1 : $length;
        $body = '';

        for ($index = 0; $index < $bodyLength; $index++) {
            $pool = $index === 0 ? $leading : $alphabet;
            $body .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return $checkDigit ? $body.self::checkDigit($body) : $body;
    }

    /**
     * Lets callers reject a mistyped reference before it costs a lookup.
     */
    public static function isValid(string $value, array $shape): bool
    {
        if (strlen($value) !== self::length($shape)) {
            return false;
        }

        if (self::forbidsLeadingZero($shape) && str_starts_with($value, '0')) {
            return false;
        }

        if (! (bool) ($shape['check_digit'] ?? true)) {
            return true;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            return false;
        }

        return self::checkDigit(substr($value, 0, -1)) === (int) $value[strlen($value) - 1];
    }

    private static function length(array $shape): int
    {
        return min(self::MAX_LENGTH, max(4, (int) ($shape['length'] ?? 12)));
    }

    private static function forbidsLeadingZero(array $shape): bool
    {
        return (bool) ($shape['no_leading_zero'] ?? false);
    }

    private static function checkDigit(string $digits): int
    {
        $sum = 0;
        $alternate = true;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($alternate) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $alternate = ! $alternate;
        }

        return (10 - ($sum % 10)) % 10;
    }

    private function alphabet(string $charset): string
    {
        return match ($charset) {
            'numeric' => self::NUMERIC,
            'alphanumeric' => self::ALPHANUMERIC,
            default => throw new InvalidArgumentException("Unsupported reference charset [{$charset}]."),
        };
    }
}
