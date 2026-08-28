<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
#[QueryParameter('status', description: 'تصفية المزادات بحالة المزاد.')]
#[QueryParameter('category_id', description: 'تصفية المزادات بمعرّف التصنيف.')]
#[QueryParameter('seller_id', description: 'تصفية المزادات بمعرّف البائع.')]
#[QueryParameter('q', description: 'نص البحث في بيانات المزاد.')]
#[QueryParameter('starts_from', description: 'أقدم تاريخ بدء مسموح به في النتائج.')]
#[QueryParameter('starts_to', description: 'أحدث تاريخ بدء مسموح به في النتائج.')]
#[QueryParameter('ends_from', description: 'أقدم تاريخ انتهاء مسموح به في النتائج.')]
#[QueryParameter('ends_to', description: 'أحدث تاريخ انتهاء مسموح به في النتائج.')]
#[QueryParameter('sort', description: 'حقل الترتيب: تاريخ الإنشاء أو تاريخ البدء أو تاريخ الانتهاء.')]
#[QueryParameter('direction', description: 'اتجاه الترتيب تصاعديًا أو تنازليًا.')]
#[QueryParameter('phase', description: 'تصفية المزادات بمرحلتها الزمنية: live للمزادات الجارية، وupcoming للمزادات القادمة، وfinished للمزادات المنتهية.')]
#[QueryParameter('currency', description: 'تصفية المزادات برمز العملة المكوّن من ثلاثة أحرف.')]
#[QueryParameter('overdue_payment', description: 'قصر النتائج على المزادات التي تجاوز فيها الفائز مهلة السداد.')]
#[QueryParameter('overdue_handover', description: 'قصر النتائج على المزادات التي تجاوزت مهلة التسليم.')]
#[QueryParameter('has_dispute', description: 'قصر النتائج على المزادات التي عليها نزاع.')]
#[QueryParameter('awaiting_seller_deposit', description: 'قصر النتائج على المزادات التي تنتظر دفع تأمين البائع.')]
final class AdminAuctionIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'seller_id' => ['nullable', 'integer', 'exists:users,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'starts_from' => ['nullable', 'date'],
            'starts_to' => ['nullable', 'date', 'after_or_equal:starts_from'],
            'ends_from' => ['nullable', 'date'],
            'ends_to' => ['nullable', 'date', 'after_or_equal:ends_from'],
            'sort' => ['nullable', Rule::in(['created_at', 'starts_at', 'ends_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'phase' => ['nullable', Rule::in(['live', 'upcoming', 'finished'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'overdue_payment' => ['nullable', 'boolean'],
            'overdue_handover' => ['nullable', 'boolean'],
            'has_dispute' => ['nullable', 'boolean'],
            'awaiting_seller_deposit' => ['nullable', 'boolean'],
        ];
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'category_id' => $this->validated('category_id'),
            'seller_id' => $this->validated('seller_id'),
            'q' => $this->validated('q'),
            'starts_from' => $this->validated('starts_from'),
            'starts_to' => $this->validated('starts_to'),
            'ends_from' => $this->validated('ends_from'),
            'ends_to' => $this->validated('ends_to'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction'),
            'phase' => $this->validated('phase'),
            'currency' => $this->validated('currency'),
            'overdue_payment' => $this->flagFilter('overdue_payment'),
            'overdue_handover' => $this->flagFilter('overdue_handover'),
            'has_dispute' => $this->flagFilter('has_dispute'),
            'awaiting_seller_deposit' => $this->flagFilter('awaiting_seller_deposit'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function flagFilter(string $key): ?bool
    {
        return $this->filled($key) ? $this->boolean($key) : null;
    }
}
