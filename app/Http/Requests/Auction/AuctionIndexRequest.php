<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Support\Market\MarketContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
#[QueryParameter('page', description: 'رقم الصفحة المطلوبة.')]
#[QueryParameter('search', description: 'نص البحث في عنوان المزاد ووصفه.')]
#[QueryParameter('status', description: 'تصفية المزادات بحالة المزاد.')]
#[QueryParameter('phase', description: 'تصفية المزادات بمرحلتها الزمنية: live للمزادات الجارية، وupcoming للمزادات القادمة، وfinished للمزادات المنتهية.')]
#[QueryParameter('category_id', description: 'تصفية المزادات بمعرّف التصنيف.')]
#[QueryParameter('currency', description: 'تصفية المزادات برمز العملة المكوّن من ثلاثة أحرف.')]
#[QueryParameter('sort', description: 'ترتيب النتائج: latest للأحدث، وstarting_soon للأقرب بدءًا، وending_soon للأقرب انتهاءً، وprice_asc وprice_desc حسب السعر الحالي.')]
final class AuctionIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'phase' => ['nullable', Rule::in(['live', 'upcoming', 'finished'])],
            'category_id' => [
                'nullable',
                'integer',
                $this->categoryExistsRule(),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'sort' => ['nullable', Rule::in(['latest', 'starting_soon', 'ending_soon', 'price_asc', 'price_desc'])],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'search' => $this->input('search'),
            'status' => $this->input('status'),
            'phase' => $this->input('phase'),
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'currency' => $this->input('currency'),
            'sort' => $this->input('sort'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }

    private function categoryExistsRule(): Exists
    {
        $context = app(MarketContext::class);

        if ($context->state()->mode->isGlobal()) {
            return Rule::exists('categories', 'id');
        }

        return Rule::exists('market_category', 'category_id')
            ->where('market_id', $context->marketId())
            ->where('is_visible', true);
    }
}
