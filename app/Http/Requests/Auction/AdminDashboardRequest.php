<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Services\Auction\Support\DashboardPeriod;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;

#[QueryParameter('period', description: 'الفترة الزمنية للتقارير، والقيمة الافتراضية 30d. استخدم custom لتحديد فترة يدوية عبر date_from وdate_to.')]
#[QueryParameter('date_from', description: 'بداية الفترة بصيغة Y-m-d، ومطلوبة عندما تكون الفترة custom.')]
#[QueryParameter('date_to', description: 'نهاية الفترة بصيغة Y-m-d، ومطلوبة عندما تكون الفترة custom.')]
final class AdminDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', 'in:today,7d,30d,month,prev_month,year,all,custom'],
            'date_from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function period(): DashboardPeriod
    {
        return DashboardPeriod::resolve(
            (string) $this->validated('period', '30d'),
            $this->validated('date_from'),
            $this->validated('date_to'),
        );
    }
}
