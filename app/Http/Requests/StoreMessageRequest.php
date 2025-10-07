<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'receiver_id' => 'required|exists:users,id',
            'ad_id' => 'nullable|exists:ads,id',
            'content' => 'nullable|string',
            'TemporaryCode' => 'string',
            'file' => 'nullable|file|mimes:jpeg,png,jpg,pdf,mp3,wav,mp4,zip|max:15360', 
        ];
    }
}
