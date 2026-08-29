<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Rules\CurrencyDecimalRule;
use App\Domain\Auction\Rules\SupportedCurrencyRule;
use App\Domain\Auction\ValueObjects\Money;
use App\Models\Auction\Auction;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

#[BodyParameter('category_id', description: 'معرّف تصنيف المزاد.')]
#[BodyParameter('country_id', description: 'معرّف الدولة التي يوجد فيها المنتج.')]
#[BodyParameter('state_id', description: 'معرّف المحافظة أو المنطقة.')]
#[BodyParameter('city_id', description: 'معرّف المدينة.')]
#[BodyParameter('title', description: 'عنوان المزاد كما يظهر للمستخدمين.')]
#[BodyParameter('description', description: 'الوصف التفصيلي للمنتج المعروض في المزاد.')]
#[BodyParameter('currency_code', description: 'رمز عملة المزاد. تغييره يستلزم إعادة إرسال سعر البدء وسعر الحد الأدنى إن كان محددًا.')]
#[BodyParameter('starting_amount', description: 'سعر بدء المزايدة كنص عشري مطابق لعدد خانات العملة المعتمدة.')]
#[BodyParameter('reserve_amount', description: 'السعر الأدنى الذي يقبل البائع البيع عنده، ولا يجوز أن يقل عن سعر البدء.')]
#[BodyParameter('starts_at', description: 'تاريخ ووقت بدء المزاد، ويجب أن يكون في المستقبل.')]
#[BodyParameter('ends_at', description: 'تاريخ ووقت انتهاء المزاد، ويجب أن يكون بعد تاريخ البدء.')]
#[BodyParameter('latitude', description: 'خط عرض موقع المنتج.')]
#[BodyParameter('longitude', description: 'خط طول موقع المنتج.')]
#[BodyParameter('media', description: 'صور المزاد الجديدة، بحد أقصى اثنتا عشرة صورة. إرسال هذا الحقل يستبدل جميع الصور الحالية للمزاد.')]
final class UpdateDraftAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'effective_currency_code' => strtoupper(
                (string) ($this->input('currency_code') ?? $this->auctionModel()?->currency_code ?? 'JOD')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'currency_code' => ['sometimes', 'string', 'size:3', new SupportedCurrencyRule],
            'starting_amount' => ['sometimes', new CurrencyDecimalRule('effective_currency_code')],
            'reserve_amount' => ['sometimes', 'nullable', new CurrencyDecimalRule('effective_currency_code')],
            'starts_at' => ['sometimes', 'date', 'after:now'],
            'ends_at' => ['sometimes', 'date'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'media' => ['sometimes', 'array', 'max:12'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $auction = $this->auctionModel();

            if ($auction === null) {
                return;
            }

            $this->validateCurrencyChange($validator, $auction);
            $this->validateAmounts($validator, $auction);
            $this->validateSchedule($validator, $auction);
        });
    }

    private function validateCurrencyChange(Validator $validator, Auction $auction): void
    {
        if (! $this->has('currency_code')) {
            return;
        }

        $currency = strtoupper((string) $this->input('currency_code'));

        if ($currency === (string) $auction->currency_code) {
            return;
        }

        if (! $this->has('starting_amount')) {
            $validator->errors()->add('starting_amount', __('auction.validation.amount_required_on_currency_change'));
        }

        if ($auction->reserve_amount_minor !== null && ! $this->has('reserve_amount')) {
            $validator->errors()->add('reserve_amount', __('auction.validation.amount_required_on_currency_change'));
        }
    }

    private function validateAmounts(Validator $validator, Auction $auction): void
    {
        if ($validator->errors()->hasAny(['currency_code', 'starting_amount', 'reserve_amount'])) {
            return;
        }

        $currency = (string) $this->input('effective_currency_code');

        try {
            $starting = $this->has('starting_amount')
                ? Money::fromDecimalString((string) $this->input('starting_amount'), $currency)
                : Money::fromMinorUnits((int) $auction->starting_amount_minor, $currency);

            $reserve = $this->resolveReserve($auction, $currency);
        } catch (InvalidArgumentException) {
            $validator->errors()->add('starting_amount', __('validation.numeric'));

            return;
        }

        if ($reserve !== null && $reserve->minor < $starting->minor) {
            $validator->errors()->add('reserve_amount', __('auction.validation.reserve_below_starting'));
        }
    }

    private function resolveReserve(Auction $auction, string $currency): ?Money
    {
        if ($this->has('reserve_amount')) {
            $value = $this->input('reserve_amount');

            return $value === null ? null : Money::fromDecimalString((string) $value, $currency);
        }

        return $auction->reserve_amount_minor === null
            ? null
            : Money::fromMinorUnits((int) $auction->reserve_amount_minor, $currency);
    }

    private function validateSchedule(Validator $validator, Auction $auction): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        if (! $this->has('starts_at') && ! $this->has('ends_at')) {
            return;
        }

        $startsAt = $this->has('starts_at')
            ? Carbon::parse((string) $this->input('starts_at'))
            : ($auction->starts_at !== null ? Carbon::parse($auction->starts_at->toIso8601String()) : null);

        $endsAt = $this->has('ends_at')
            ? Carbon::parse((string) $this->input('ends_at'))
            : ($auction->ends_at !== null ? Carbon::parse($auction->ends_at->toIso8601String()) : null);

        if ($startsAt === null || $endsAt === null) {
            return;
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $validator->errors()->add('ends_at', __('auction.validation.ends_at_after_starts_at'));
        }
    }

    private function auctionModel(): ?Auction
    {
        $auction = $this->route('auction');

        return $auction instanceof Auction ? $auction : null;
    }
}
