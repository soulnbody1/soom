<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AdImage extends Model
{
    protected $fillable = ['ad_id', 'image_path'];

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }

    public function getImagePathAttribute($value)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('spaces');
        return $value ? $disk->url($value) : null;
    }
}
