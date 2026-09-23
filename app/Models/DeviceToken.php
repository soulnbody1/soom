<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'token', 'platform', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'device_token_market')->withTimestamps();
    }
}
