<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PaymentMethod extends Model
{
    use HasPublicId;
    use HasFactory;

    protected $fillable = [
        'public_id',
        'name',
        'code',
        'instructions',
        'requires_manual_review',
        'is_active',
    ];

    protected $casts = [
        'requires_manual_review' => 'boolean',
        'is_active' => 'boolean',
    ];
}
