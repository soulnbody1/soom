<?php

declare(strict_types=1);

namespace App\Http\Requests\Message;

use App\Repositories\Message\Queries\ConversationThreadsQuery;
use Illuminate\Foundation\Http\FormRequest;

final class ConversationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ConversationThreadsQuery::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): ?string
    {
        $search = $this->input('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?: ConversationThreadsQuery::PER_PAGE);
    }

    public function page(): int
    {
        return (int) ($this->input('page') ?: 1);
    }
}
