<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Ad extends Model
{
    use BelongsToMarket, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'market_id',
        'user_id',
        'category_id',
        'title',
        'description',
        'price',
        'currency_code',
        'country_id',
        'state_id',
        'city_id',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
        'is_featured' => 'boolean',

    ];

    public function scopeFeatured(Builder $query): Builder
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
        'attributeValues.attribute:id,name',
        'market:id,code,web_host',
    ];

    public function scopeWithIsFavorite(Builder $query, ?object $user): Builder
    {
        if ($user) {
            $query->withExists([
                'favoritedByUsers as is_favorite' => function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                },
            ]);
        } else {
            // Only seed the column list when nothing has been selected yet. Appending a
            // bare `*` after an existing select (for example one added by withCount) is
            // invalid SQL.
            if ($query->getQuery()->columns === null) {
                $query->addSelect($query->getQuery()->from.'.*');
            }

            $query->addSelect(DB::raw('false as is_favorite'));
        }

        return $query;
    }
}
