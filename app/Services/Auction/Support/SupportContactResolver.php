<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Models\SupportContact;
use App\Models\User;

final class SupportContactResolver
{
    public const CHANNELS = ['whatsapp', 'phone', 'email', 'availability'];

    public function record(): ?SupportContact
    {
        return SupportContact::query()->orderBy('id')->first();
    }

    /**
     * @return array<string, string|null>
     */
    public function resolved(): array
    {
        $record = $this->record();
        $config = (array) config('support.contact', []);

        $payload = [];
        foreach (self::CHANNELS as $channel) {
            $payload[$channel] = $this->clean($record?->{$channel}) ?? $this->clean($config[$channel] ?? null);
        }

        return $payload;
    }

    /**
     * @param  array<string, string|null>  $data
     */
    public function save(array $data, User $admin): SupportContact
    {
        $record = $this->record() ?? new SupportContact;

        $values = ['updated_by' => $admin->id];
        foreach (self::CHANNELS as $channel) {
            $values[$channel] = $this->clean($data[$channel] ?? null);
        }

        $record->fill($values)->save();

        return $record->refresh();
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
