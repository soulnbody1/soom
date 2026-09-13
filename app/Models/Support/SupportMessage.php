<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Domain\Support\Enums\SupportAuthorType;
use App\Domain\Support\Enums\SupportMessageVisibility;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class SupportMessage extends Model
{
    use HasPublicId;

    protected $fillable = ['public_id', 'ticket_id', 'author_id', 'author_type', 'visibility', 'body', 'client_message_id'];

    protected $casts = ['author_type' => SupportAuthorType::class, 'visibility' => SupportMessageVisibility::class];

    protected static function booted(): void
    {
        self::creating(function (SupportMessage $message): void {
            if (empty($message->public_id)) {
                $message->public_id = (string) Str::ulid();
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
