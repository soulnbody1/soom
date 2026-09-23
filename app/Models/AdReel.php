<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdReel extends Model
{
    use BelongsToMarket, HasFactory;

    protected $table = 'ad_reels';

    protected $fillable = [
        'market_id',
        'ad_id',
        'video_path',
        'thumbnail_path',
        'duration',
    ];

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }

    public function views()
    {
        return $this->hasMany(AdReelView::class, 'ad_reel_id');
    }
}
