<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentRecordStatus;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
#[QueryParameter('status', description: 'تصفية العمليات بالحالة الموحّدة: بانتظار المراجعة أو معلّقة أو ناجحة أو فاشلة أو مرفوضة أو ملغاة أو منتهية أو معكوسة.')]
#[QueryParameter('purpose', description: 'تصفية العمليات بغرض الدفعة: تأمين البائع أو تأمين المزايد أو سداد مستحقات الفائز.')]
#[QueryParameter('channel', description: 'تصفية العمليات بقناة الدفع: تحويل يدوي أو دفع إلكتروني.')]
#[QueryParameter('provider', description: 'تصفية العمليات بمزوّد الدفع الإلكتروني.')]
#[QueryParameter('auction_id', description: 'تصفية العمليات بالمعرّف العام للمزاد.')]
#[QueryParameter('user_id', description: 'تصفية العمليات بمعرّف صاحب الدفعة.')]
final class AdminPaymentRecordIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(PaymentRecordStatus::cases(), 'value'))],
            'purpose' => ['nullable', Rule::in(array_column(PaymentPurpose::cases(), 'value'))],
            'channel' => ['nullable', Rule::in(array_column(PaymentChannel::cases(), 'value'))],
            'provider' => ['nullable', 'string', 'max:60'],
            'auction_id' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'purpose' => $this->validated('purpose'),
            'channel' => $this->validated('channel'),
            'provider' => $this->validated('provider'),
            'auction_id' => $this->validated('auction_id'),
            'user_id' => $this->validated('user_id'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
