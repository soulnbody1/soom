<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAdInteraction extends Model
{
    protected $fillable = ['user_id', 'ad_id', 'action'];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }
}
