<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportContact extends Model
{
    use BelongsToMarket;

    protected $fillable = [
        'market_id',
        'whatsapp',
        'phone',
        'email',
        'availability',
        'updated_by',
    ];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
