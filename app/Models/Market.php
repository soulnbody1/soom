<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Market extends Model
{
    protected $fillable = [
        'country_id',
        'code',
        'web_host',
        'api_host',
        'currency_code',
        'timezone',
        'phone_country_code',
        'default_locale',
        'features',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $market): void {
            $market->code = strtoupper($market->code);
            $market->currency_code = strtoupper($market->currency_code);
            $market->web_host = self::canonicalHost($market->web_host);
            $market->api_host = self::canonicalHost($market->api_host);

            if ($market->is_active && ($market->web_host === null || $market->api_host === null)) {
                throw new \DomainException('An active market must have both web and API hosts.');
            }
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function webUrl(string $path = ''): ?string
    {
        if ($this->web_host === null) {
            return null;
        }

        return 'https://'.$this->web_host.'/'.ltrim($path, '/');
    }

    private static function canonicalHost(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));

        return $host === '' ? null : $host;
    }
}
