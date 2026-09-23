<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Market\MarketContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdReelViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ad_reel_id' => ['required', 'integer', Rule::exists('ad_reels', 'id')->where(
                fn (Builder $query) => $query->whereExists(
                    fn (Builder $ads) => $ads->selectRaw('1')->from('ads')
                        ->whereColumn('ads.id', 'ad_reels.ad_id')
                        ->where('ads.market_id', app(MarketContext::class)->marketId())
                        ->whereNull('ads.deleted_at')
                )
            )],
        ];
    }

    public function messages(): array
    {
        return [
            'ad_reel_id.required' => 'رقم الإعلان مطلوب',
            'ad_reel_id.exists' => 'الإعلان غير موجود',
        ];
    }
}
