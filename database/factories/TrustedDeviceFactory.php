<?php

namespace Database\Factories;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrustedDevice>
 */
class TrustedDeviceFactory extends Factory
{
    /**
     * A browser trusted recently, still inside its trust period.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'label' => fake()->randomElement(['Chrome', 'Edge', 'Firefox']).' on '.fake()->randomElement(['Windows', 'macOS']),
            'last_ip' => fake()->ipv4(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Trust that has run out.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
