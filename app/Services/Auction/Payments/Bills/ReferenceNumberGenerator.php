<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use InvalidArgumentException;

/**
 * Generates opaque payer-facing reference numbers.
 *
 * The shape (length, charset, trailing check digit) is configuration, because no
 * protocol we integrate with has told us what it must be yet. Nothing about the
 * platform is derivable from the output: it is drawn from a CSPRNG and carries
 * no database identifier, auction, purpose, or personal data.
 */
final class ReferenceNumberGenerator
{
    private const NUMERIC = '0123456789';

    private const ALPHANUMERIC = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function generate(array $shape): string
    {
        $length = max(4, (int) ($shape['length'] ?? 12));
        $checkDigit = (bool) ($shape['check_digit'] ?? true);
        $alphabet = $this->alphabet((string) ($shape['charset'] ?? 'numeric'));

        if ($checkDigit && $alphabet !== self::NUMERIC) {
            throw new InvalidArgumentException('A check digit requires a numeric reference charset.');
        }

        $bodyLength = $checkDigit ? $length - 1 : $length;
        $body = '';

        for ($index = 0; $index < $bodyLength; $index++) {
            $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $checkDigit ? $body.self::checkDigit($body) : $body;
    }

    /**
     * Lets callers reject a mistyped reference before it costs a lookup.
     */
    public static function isValid(string $value, array $shape): bool
    {
        $length = max(4, (int) ($shape['length'] ?? 12));

        if (strlen($value) !== $length) {
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
