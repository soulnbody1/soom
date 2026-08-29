<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Rules\CurrencyDecimalRule;
use App\Domain\Auction\Rules\SupportedCurrencyRule;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('amount', description: 'قيمة المزايدة كنص عشري مطابق لعدد خانات العملة، ويجب ألا تقل عن المزايدة الحالية مضافًا إليها الحد الأدنى للزيادة.')]
#[BodyParameter('currency_code', description: 'رمز عملة المزايدة، ويجب أن يطابق عملة المزاد.')]
#[BodyParameter('idempotency_key', description: 'مفتاح منع التكرار الذي يولّده العميل. إعادة الإرسال بالمفتاح نفسه تُرجع المزايدة الأصلية دون تسجيل مزايدة جديدة.')]
#[BodyParameter('client_request_id', description: 'معرّف اختياري من جهة العميل لتتبع الطلب في السجلات.')]
final class PlaceBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', new CurrencyDecimalRule('currency_code')],
            'currency_code' => ['required', 'string', 'size:3', new SupportedCurrencyRule],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'client_request_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
