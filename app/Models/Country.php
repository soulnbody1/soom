<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $table = 'countries';

    protected $fillable = ['name', 'code', 'iso2'];

    public function market()
    {
        return $this->hasOne(Market::class);
    }

    public function states()
    {
        return $this->hasMany(State::class);
    }
}
