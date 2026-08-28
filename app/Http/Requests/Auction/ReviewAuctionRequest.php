<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('action', description: 'قرار المراجعة: approve لاعتماد المزاد أو reject لرفضه.')]
#[BodyParameter('reason', description: 'مبرر القرار، ويظهر للبائع عند الرفض.')]
final class ReviewAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
