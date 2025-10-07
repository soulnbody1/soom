<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    protected $fillable = ['name', 'type', 'is_required', 'is_multiple', 'parent_attribute_id'];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'parent_attribute_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Attribute::class, 'parent_attribute_id');
    }
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'attribute_category')
            ->withPivot('is_inheritable')
            ->withTimestamps();
    }
}
