<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionsConfiguration extends Model
{
    protected $table = 'auctions_configurations';

    protected $fillable = [
        'deposit_type',
        'amount',
        'category_id',
        'duration_days',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'duration_days' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('start_date')->orWhere('start_date', '<=', now());
        })->where(function ($q) {
            $q->whereNull('end_date')->orWhere('end_date', '>=', now());
        });
    }
}