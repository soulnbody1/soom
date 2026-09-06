<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'receiver_id' => User::factory(),
            'ad_id' => null,
            'content' => $this->faker->sentence(),
            'attachment_path' => null,
            'attachment_type' => null,
            'is_read' => false,
        ];
    }

    public function from(User $sender): self
    {
        return $this->state(fn (): array => ['sender_id' => $sender->id]);
    }

    public function to(User $receiver): self
    {
        return $this->state(fn (): array => ['receiver_id' => $receiver->id]);
    }

    public function read(): self
    {
        return $this->state(fn (): array => ['is_read' => true]);
    }

    public function withAttachment(string $path = 'chat_files/sample.png', string $type = 'image/png'): self
    {
        return $this->state(fn (): array => [
            'attachment_path' => $path,
            'attachment_type' => $type,
        ]);
    }

    public function forAd(Ad $ad): self
    {
        return $this->state(fn (): array => ['ad_id' => $ad->id]);
    }
}
