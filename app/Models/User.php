<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use SoftDeletes, HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'logo',
        'birth_date',
        'gender',
        'country_id',
        'state_id',
        'city_id',
        'password',
        'role',
        'email_verified_at',
        'allow_ad_notifications',
        'fcm_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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


    public function adReelViews()
    {
        return $this->hasMany(AdReelView::class, 'user_id');
    }
    public function favorites()
    {
        return $this->belongsToMany(Ad::class, 'favorites')->withTimestamps();
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function chatUsers()
    {
        return $this->hasMany(Message::class, 'sender_id')
            ->select('receiver_id')
            ->distinct();
    }

    public function getLogoAttribute($value)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('spaces');
        return $value ? $disk->url($value) : null;
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class, 'user_id');
    }

    public function auctionsAsAdvertiser(): HasMany
    {
        return $this->hasMany(Auction::class, 'seller_id');
    }

    public function auctionBids(): HasMany
    {
        return $this->hasMany(AuctionBid::class, 'bidder_id');
    }

    public function wonSettlements(): HasMany
    {
        return $this->hasMany(\App\Models\Auction\AuctionSettlement::class, 'winner_id');
    }

    public function auctionParticipants(): HasMany
    {
        return $this->hasMany(\App\Models\Auction\AuctionParticipant::class, 'user_id');
    }
}
