<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    protected $fillable = ['name', 'image', 'parent_id'];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->with('children');
    }

    public function ads()
    {
        return $this->hasMany(Ad::class);
    }

    public function attributes()
    {
        return $this->belongsToMany(Attribute::class, 'attribute_category')
            ->withPivot('is_inheritable')
            ->withTimestamps();
    }

    public function getAllAttributesWithInheritance()
    {
        $attributes = $this->attributes()
            ->with('options')
            ->get();

        $parent = $this->parent;

        while ($parent) {
            $inherited = $parent->attributes()
                ->wherePivot('is_inheritable', true)
                ->with('options')
                ->get();

            $attributes = $attributes->merge($inherited);
            $parent = $parent->parent;
        }

        return $attributes->unique('id');
    }

    public function getImageAttribute($value)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('spaces');
        return $value ? $disk->url($value) : null;
    }

    public function attributeExceptions()
    {
        return $this->hasMany(AttributeCategoryException::class);
    }
}
