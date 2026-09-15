<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => Message::ROLE_USER,
            'content' => $this->faker->sentence(),
            'meta' => null,
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => Message::ROLE_ASSISTANT]);
    }
}
