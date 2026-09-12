<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeOption extends Model
{
    use HasFactory;

    protected $table = 'attribute_options';

    protected $fillable = [
        'attribute_id',
        'value',
        'label',
        'parent_option_id'
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'parent_option_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AttributeOption::class, 'parent_option_id');
    }
}
