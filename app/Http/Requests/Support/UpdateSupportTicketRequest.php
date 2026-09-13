<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Domain\Support\Enums\SupportTicketPriority;
use App\Domain\Support\Enums\SupportTicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(SupportTicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(SupportTicketPriority::class)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'admin')],
        ];
    }
}
