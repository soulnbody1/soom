<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Ad extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'description',
        'price',
        'country_id',
        'state_id',
        'city_id',
        'latitude',
        'longitude',
        'is_featured'
    ];
    protected $casts = [
        'is_favorite' => 'boolean',
        'is_featured' => 'boolean',

    ];
    public function scopeFeatured($query)
    {
        return $query->orderByDesc('is_featured')->latest('id');
    }


    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }


    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'ad_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AdImage::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reel()
    {
        return $this->hasOne(AdReel::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function favoritedByUsers()
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    public function views()
    {
        return $this->hasMany(AdView::class);
    }

    public static array $defaultRelations = [
        'user:id,name,phone,logo',
        'category:id,name',
        'country:id,name',
        'state:id,name',
        'city:id,name',
        'images:id,ad_id,image_path',
        'attributeValues.attribute:id,name'
    ];
    public function scopeWithIsFavorite($query, $user)
    {
        if ($user) {
            $query->withExists([
                'favoritedByUsers as is_favorite' => function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                }
            ]);
        } else {
            $query->addSelect([
                '*',
                DB::raw('false as is_favorite')
            ]);
        }

        return $query;
    }
}
