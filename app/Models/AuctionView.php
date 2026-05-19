<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuctionView extends Model
{
    protected $fillable = ['auction_id', 'user_id', 'ip_address', 'viewed_at'];

    public $timestamps = true;
}