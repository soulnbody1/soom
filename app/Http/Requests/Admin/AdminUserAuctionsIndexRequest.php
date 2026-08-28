<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Repositories\User\Queries\UserAuctionsQuery;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[QueryParameter('scope', description: 'نطاق المزادات المطلوب: created للمزادات التي أنشأها، وparticipated للمزادات التي شارك فيها، وwon للمزادات التي فاز بها، وlost للمزادات التي انتهت دون فوزه. القيمة الافتراضية created.')]
#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 15.')]
#[QueryParameter('status', description: 'تصفية المزادات بحالة المزاد.')]
#[QueryParameter('search', description: 'نص البحث في عنوان المزاد.')]
final class AdminUserAuctionsIndexRequest extends FormRequest
{
    use ResolvesAdminPerPage;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', Rule::in(UserAuctionsQuery::SCOPES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn (AuctionStatus $status) => $status->value, AuctionStatus::cases()))],
            'search' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function scope(): string
    {
        return $this->validated('scope') ?? 'created';
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'search' => $this->validated('search'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
