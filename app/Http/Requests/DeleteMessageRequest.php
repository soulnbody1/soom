<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DeleteMessageRequest extends FormRequest
{
    public const MAX_MESSAGE_IDS = 200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'message_ids' => ['nullable', 'array', 'max:'.self::MAX_MESSAGE_IDS],
            'message_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = $this->messageIds();

            if ($ids === [] || $validator->errors()->has('message_ids')) {
                return;
            }

            if (Message::whereIn('id', $ids)->count() !== count($ids)) {
                $validator->errors()->add('message_ids', 'بعض الرسائل المحددة غير موجودة.');
            }
        });
    }

    /**
     * @return list<int>
     */
    public function messageIds(): array
    {
        $ids = $this->input('message_ids');

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }
}
