<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CharitySystem extends Model
{
    use BelongsToMarket;

    protected $fillable = [
        'market_id',
        'image',
        'link_url',
        'is_active',
        'start_date',
        'end_date',
        'display_order',
    ];

    protected $dates = [
        'start_date',
        'end_date',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function getImageAttribute($value)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('spaces');

        return $value ? $disk->url($value) : null;
    }
}
