<?php

namespace App\Repositories;

use App\Models\Message;
use Illuminate\Support\Str;

class MessageRepository
{
    public function create(array $data): Message
    {
        return Message::create($data);
    }

    public function storeAttachment($file): array
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName);
        $extension = $file->getClientOriginalExtension();
        $filename = $safeName.'_'.time().'_'.Str::random(6).'.'.$extension;
        $path = $file->storeAs('chat_files', $filename, 'spaces');

        return [
            'attachment_path' => $path,
            'attachment_type' => $file->getMimeType(),
        ];
    }
}
