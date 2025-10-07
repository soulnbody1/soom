<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdView extends Model
{
    protected $fillable = ['ad_id', 'user_id', 'ip_address', 'viewed_at'];

    public $timestamps = true;
}
