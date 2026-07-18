<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayoutDestination extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'user_id',
        'recipient_name',
        'identifier_type',
        'identifier_value',
        'is_default',
        'default_marker',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
