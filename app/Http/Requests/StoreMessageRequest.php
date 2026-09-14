<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public const MAX_CONTENT_LENGTH = 5000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_id' => [
                'required',
                'integer',
                Rule::notIn([(int) $this->user()?->id]),
                'exists:users,id',
            ],
            'ad_id' => ['bail', 'nullable', 'string', 'size:26', 'ulid', Rule::exists('ads', 'public_id')->whereNull('deleted_at')],
            'content' => ['nullable', 'string', 'max:'.self::MAX_CONTENT_LENGTH, 'required_without:file'],
            'TemporaryCode' => ['nullable', 'string', 'max:100'],
            'file' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf,mp3,wav,mp4,zip', 'max:15360', 'required_without:content'],
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_id.not_in' => 'لا يمكنك إرسال رسالة إلى نفسك.',
            'content.required_without' => 'لا يمكن إرسال رسالة فارغة.',
            'file.required_without' => 'لا يمكن إرسال رسالة فارغة.',
        ];
    }

    public function receiverId(): int
    {
        return (int) $this->input('receiver_id');
    }

    public function payload(): array
    {
        return [
            'ad_id' => $this->input('ad_id') !== null
                ? \App\Models\Ad::query()->where('public_id', $this->input('ad_id'))->value('id')
                : null,
            'content' => $this->input('content'),
            'temporary_code' => $this->input('TemporaryCode'),
            'file' => $this->file('file') instanceof UploadedFile ? $this->file('file') : null,
        ];
    }
}
