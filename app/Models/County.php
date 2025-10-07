<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class County extends Model
{
    protected $table = 'counties';
    protected $fillable = ['name', 'city_id', 'latitude', 'longitude'];

    public function city()
    {
        return $this->belongsTo(City::class);
    }
    //
}
