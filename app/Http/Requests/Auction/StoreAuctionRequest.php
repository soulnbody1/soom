<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Rules\CurrencyDecimalRule;
use App\Domain\Auction\Rules\SupportedCurrencyRule;
use App\Domain\Auction\ValueObjects\Money;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('category_id', description: 'معرّف تصنيف المزاد.')]
#[BodyParameter('country_id', description: 'معرّف الدولة التي يوجد فيها المنتج.')]
#[BodyParameter('state_id', description: 'معرّف المحافظة أو المنطقة.')]
#[BodyParameter('city_id', description: 'معرّف المدينة.')]
#[BodyParameter('title', description: 'عنوان المزاد كما يظهر للمستخدمين.')]
#[BodyParameter('description', description: 'الوصف التفصيلي للمنتج المعروض في المزاد.')]
#[BodyParameter('currency_code', description: 'رمز عملة المزاد، ويحدّد عدد الخانات العشرية المسموح بها في المبالغ.')]
#[BodyParameter('starting_amount', description: 'سعر بدء المزايدة كنص عشري مطابق لعدد خانات العملة المختارة.')]
#[BodyParameter('reserve_amount', description: 'السعر الأدنى الذي يقبل البائع البيع عنده، ولا يجوز أن يقل عن سعر البدء.')]
#[BodyParameter('starts_at', description: 'تاريخ ووقت بدء المزاد، ويجب أن يكون في المستقبل.')]
#[BodyParameter('ends_at', description: 'تاريخ ووقت انتهاء المزاد، ويجب أن يكون بعد تاريخ البدء.')]
#[BodyParameter('latitude', description: 'خط عرض موقع المنتج.')]
#[BodyParameter('longitude', description: 'خط طول موقع المنتج.')]
#[BodyParameter('media', description: 'صور المزاد، بحد أقصى اثنتا عشرة صورة.')]
final class StoreAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
            'currency_code' => ['required', 'string', 'size:3', new SupportedCurrencyRule],
            'starting_amount' => ['required', new CurrencyDecimalRule('currency_code')],
            'reserve_amount' => ['nullable', new CurrencyDecimalRule('currency_code')],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'media' => ['nullable', 'array', 'max:12'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Skip if the underlying amount/currency fields already failed their rules.
            if ($validator->errors()->hasAny(['currency_code', 'starting_amount', 'reserve_amount'])) {
                return;
            }

            if ($this->input('reserve_amount') === null) {
                return;
            }

            $currency = (string) $this->input('currency_code');
            $starting = Money::fromDecimalString((string) $this->input('starting_amount'), $currency);
            $reserve = Money::fromDecimalString((string) $this->input('reserve_amount'), $currency);

            if ($reserve->minor < $starting->minor) {
                $validator->errors()->add(
                    'reserve_amount',
                    __('auction.validation.reserve_below_starting'),
                );
            }
        });
    }
}
