<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Services\Auction\Support\DashboardPeriod;
use Illuminate\Foundation\Http\FormRequest;

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
