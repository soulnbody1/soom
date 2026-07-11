<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

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
            'currency_code' => ['required', 'string', 'size:3'],
            'starting_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reserve_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'minimum_bid_increment' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'seller_deposit_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'bidder_deposit_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'platform_fee_type' => ['nullable', 'in:percentage,fixed'],
            'platform_fee_basis_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'platform_fee_fixed_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'winner_payment_deadline_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'handover_deadline_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'extension_window_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'extension_duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'maximum_extension_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'media' => ['nullable', 'array', 'max:12'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
