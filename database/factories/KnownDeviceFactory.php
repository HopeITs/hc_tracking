<?php

namespace Database\Factories;

use App\Http\UserAgent;
use App\Models\KnownDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnownDevice>
 */
class KnownDeviceFactory extends Factory
{
    /**
     * A browser/platform pair the user signed in from recently.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $agent = new UserAgent(
            fake()->randomElement(['Chrome', 'Edge', 'Firefox', 'Safari']),
            fake()->randomElement(['Windows', 'macOS', 'Android', 'iOS']),
        );

        return [
            'user_id' => User::factory(),
            'fingerprint' => $agent->fingerprint(),
            'label' => $agent->description(),
            'last_ip' => fake()->ipv4(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * The device a given User-Agent header describes.
     */
    public function forUserAgent(?string $header): static
    {
        $agent = UserAgent::parse($header);

        return $this->state([
            'fingerprint' => $agent->fingerprint(),
            'label' => $agent->description(),
        ]);
    }
}
