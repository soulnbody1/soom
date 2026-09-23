<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdView extends Model
{
    use BelongsToMarket, HasFactory;

    protected $fillable = ['market_id', 'ad_id', 'user_id', 'ip_address', 'viewed_at'];

    public $timestamps = true;
}
