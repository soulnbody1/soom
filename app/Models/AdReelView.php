<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdReelView extends Model
{
    use BelongsToMarket, HasFactory;

    protected $fillable = [
        'market_id',
        'ad_reel_id',
        'user_id',
        'viewed_at',
    ];

    public function reel()
    {
        return $this->belongsTo(AdReel::class, 'ad_reel_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
