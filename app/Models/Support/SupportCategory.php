<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Domain\Support\Enums\SupportTicketPriority;
use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupportCategory extends Model
{
    use HasPublicId;

    protected $fillable = ['public_id', 'code', 'name_ar', 'name_en', 'default_priority', 'first_response_minutes', 'resolution_minutes', 'is_active', 'sort_order'];

    protected $casts = ['default_priority' => SupportTicketPriority::class, 'is_active' => 'boolean'];

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'category_id');
    }
}
