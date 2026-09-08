<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\EFawateercom;

use App\Domain\Auction\Enums\BillRejectionReason;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Models\Auction\PaymentMethod;
use App\Services\Auction\Payments\Providers\EFawateercomPaymentProvider;

final class EFawateercomSettings
{
    public function billerCode(): string
    {
        return trim((string) config('services.'.EFawateercomPaymentProvider::CODE.'.biller_code', ''));
    }

    public function username(): string
    {
        return (string) config('services.'.EFawateercomPaymentProvider::CODE.'.username', '');
    }

    public function password(): string
    {
        return (string) config('services.'.EFawateercomPaymentProvider::CODE.'.password', '');
    }

    public function billStatus(): string
    {
        return (string) $this->option('bill_status', 'BillNew');
    }

    public function billType(): string
    {
        return (string) $this->option('bill_type', 'OneOff');
    }

    public function billReferenceShape(): array
    {
        return (array) $this->option('bill_reference', [
            'length' => 12,
            'charset' => 'numeric',
            'check_digit' => true,
            'no_leading_zero' => true,
        ]);
    }

    public function billTtlSeconds(): int
    {
        return max(60, (int) $this->option('bill_ttl_seconds', 86400));
    }

    public function rejectionCode(BillRejectionReason $reason): int
    {
        $codes = (array) $this->option('bill_error_codes', []);

        return (int) ($codes[$reason->value] ?? $codes['default'] ?? 404);
    }

    public function requestErrorCode(string $key): int
    {
        $codes = (array) $this->option('request_error_codes', []);

        return (int) ($codes[$key] ?? $codes['default'] ?? 400);
    }

    public function serviceTypeFor(PaymentMethod $method, PaymentPurpose $purpose): ?string
    {
        $codes = (array) ($method->provider_purpose_codes ?? []);
        $value = $codes[$purpose->value] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function serviceTypes(PaymentMethod $method): array
    {
        $codes = [];

        foreach (PaymentPurpose::cases() as $purpose) {
            $code = $this->serviceTypeFor($method, $purpose);

            if ($code !== null) {
                $codes[$purpose->value] = $code;
            }
        }

        return $codes;
    }

    public function purposeForServiceType(PaymentMethod $method, string $serviceType): ?PaymentPurpose
    {
        foreach ($this->serviceTypes($method) as $purpose => $code) {
            if (strcasecmp($code, $serviceType) === 0) {
                return PaymentPurpose::from($purpose);
            }
        }

        return null;
    }

    public function method(): ?PaymentMethod
    {
        return PaymentMethod::query()
            ->where('provider_code', EFawateercomPaymentProvider::CODE)
            ->orderByDesc('is_active')
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
    }

    private function option(string $key, mixed $default): mixed
    {
        return config('auction.payments.providers.'.EFawateercomPaymentProvider::CODE.'.'.$key, $default);
    }
}
