<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Models\Subscriber;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    protected $model = Subscriber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = $this->faker->firstName();

        return [
            'email' => strtolower($this->faker->unique()->safeEmail()),
            'name' => $firstName.' '.$this->faker->lastName(),
            'first_name' => $firstName,
            'locale' => 'en',
            'timezone' => null,
            'country' => null,
            'custom_fields' => null,
            'last_opened_at' => null,
            'last_clicked_at' => null,
            'last_active_at' => null,
            'first_mailed_at' => null,
            'opted_out_at' => null,
            'blocked_at' => null,
            'blocked_reason' => null,
            'soft_bounces' => 0,
        ];
    }

    public function optedOut(): static
    {
        return $this->state(['opted_out_at' => now()]);
    }

    public function blocked(BlockReason $reason = BlockReason::HardBounce): static
    {
        return $this->state([
            'blocked_at' => now(),
            'blocked_reason' => $reason,
        ]);
    }
}
