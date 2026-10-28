<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;

/**
 * @extends Factory<ProviderEvent>
 */
class ProviderEventFactory extends Factory
{
    protected $model = ProviderEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_event_id' => $this->faker->unique()->regexify('[A-Za-z0-9]{22}'),
            'signature_token_hash' => null,
            'message_id' => CampaignMessage::factory(),
            'test_send_id' => null,
            'type' => FeedbackType::Delivered,
            'is_bot' => false,
            'payload' => null,
            'occurred_at' => now(),
        ];
    }
}
