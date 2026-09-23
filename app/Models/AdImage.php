<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AdImage extends Model
{
    use BelongsToMarket, HasFactory;

    protected $fillable = ['market_id', 'ad_id', 'image_path'];

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
