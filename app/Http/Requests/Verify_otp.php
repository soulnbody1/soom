<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class Verify_otp extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => 'required|phone:EG,JO,SA,AE|exists:users,phone',
            'otp' => 'required|string|size:4',
            'fcm_token' =>'nullable|string',
            ];
    }
}
