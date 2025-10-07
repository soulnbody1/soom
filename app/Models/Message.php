<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'content',
        'attachment_path',
        'attachment_type',
        'is_read',
        'ad_id',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    // العلاقة مع المرسل
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // العلاقة مع المستقبل
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    // هل تحتوي الرسالة على ملف؟
    public function hasAttachment(): bool
    {
        return !is_null($this->attachment_path);
    }

    // هل الملف المرفق صورة؟
    public function isImage(): bool
    {
        return str_starts_with($this->attachment_type, 'image/');
    }

    // هل الملف المرفق PDF؟
    public function isPdf(): bool
    {
        return $this->attachment_type === 'application/pdf';
    }

    // هل الملف صوتي؟
    public function isAudio(): bool
    {
        return str_starts_with($this->attachment_type, 'audio/');
    }

    // هل الملف فيديو؟
    public function isVideo(): bool
    {
        return str_starts_with($this->attachment_type, 'video/');
    }

    // الحصول على رابط الملف الكامل
    public function attachmentUrl(): ?string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('spaces');
        return $this->attachment_path
            ? $disk->url($this->attachment_path)
            : null;
    }

    public function ad()
    {
        return $this->belongsTo(Ad::class, 'ad_id');
    }
}
