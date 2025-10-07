<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeCategoryException extends Model
{
    protected $table = 'attribute_category_exceptions';
    protected $fillable = ['attribute_id', 'category_id'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Attribute::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Category::class);
    }
}
