<?php

declare(strict_types=1);

namespace App\Services\Message\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChatAttachmentStorage
{
    public const DISK = 'spaces';

    public const DIRECTORY = 'chat_files';

    private const MAX_BYTES = [
        'image' => 5 * 1024 * 1024,
        'pdf' => 10 * 1024 * 1024,
        'audio' => 7 * 1024 * 1024,
        'video' => 15 * 1024 * 1024,
        'zip' => 8 * 1024 * 1024,
    ];

    public function store(UploadedFile $file): array
    {
        $category = $this->categorise($file->getMimeType());

        if ($category === null) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مسموح.']);
        }

        if ($file->getSize() > self::MAX_BYTES[$category]) {
            throw ValidationException::withMessages([
                'file' => 'حجم الملف يتجاوز الحد المسموح لهذا النوع ('.$this->megabytes($category).' ميجابايت).',
            ]);
        }

        return [
            'attachment_path' => $file->storeAs(self::DIRECTORY, $this->filename($file), self::DISK),
            'attachment_type' => $file->getMimeType(),
        ];
    }

    public function discard(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function categorise(?string $mime): ?string
    {
        return match (true) {
            $mime === null => null,
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            str_contains($mime, 'zip') => 'zip',
            default => null,
        };
    }

    private function filename(UploadedFile $file): string
    {
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        return $name.'_'.time().'_'.Str::random(6).'.'.$file->getClientOriginalExtension();
    }

    private function megabytes(string $category): int
    {
        return (int) (self::MAX_BYTES[$category] / 1024 / 1024);
    }
}
