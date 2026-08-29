<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentRail;
use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PaymentMethod extends Model
{
    use HasFactory;
    use HasPublicId;

    public const IDENTIFIER_TYPES = [
        'cliq_alias',
        'phone',
        'iban',
        'account_number',
        'wallet_number',
        'other',
    ];

    protected $fillable = [
        'public_id',
        'name',
        'code',
        'channel',
        'rail',
        'provider_code',
        'is_sandbox',
        'recipient_name',
        'identifier_type',
        'identifier_value',
        'instructions',
        'requires_manual_review',
        'is_active',
        'display_order',
        'allowed_purposes',
        'country_codes',
        'currency_codes',
        'min_amount_minor',
        'max_amount_minor',
    ];

    protected $casts = [
        'channel' => PaymentChannel::class,
        'rail' => PaymentRail::class,
        'is_sandbox' => 'boolean',
        'requires_manual_review' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'allowed_purposes' => 'array',
        'country_codes' => 'array',
        'currency_codes' => 'array',
        'min_amount_minor' => 'integer',
        'max_amount_minor' => 'integer',
    ];

    public function isOnline(): bool
    {
        return $this->channel === PaymentChannel::Online;
    }
}
