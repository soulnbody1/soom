<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAdInteraction extends Model
{
    use BelongsToMarket, HasFactory;

    protected $fillable = ['market_id', 'user_id', 'ad_id', 'action'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }
}
