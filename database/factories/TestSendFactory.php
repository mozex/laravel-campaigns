<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\TestSend;

/**
 * @extends Factory<TestSend>
 */
class TestSendFactory extends Factory
{
    protected $model = TestSend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'email' => strtolower($this->faker->unique()->safeEmail()),
            'locale' => 'en',
            'token_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'provider_message_id' => null,
            'sent_at' => null,
            'unsubscribed_at' => null,
        ];
    }
}
