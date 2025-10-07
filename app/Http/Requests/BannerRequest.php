<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BannerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'image'         => 'required|image|mimes:jpeg,png,jpg,webp|max:2048', 
            'link_url'      => 'nullable|string|max:500',
            'is_active'     => 'nullable|boolean',
            'start_date'    => 'nullable|date',
            'end_date'      => 'nullable|date|after_or_equal:start_date',
            'display_order' => 'nullable|integer',
            'price'         => 'nullable|numeric|min:0.00'

        ];
    }
}
