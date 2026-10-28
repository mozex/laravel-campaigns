<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Subscriber;

/**
 * @extends Factory<CampaignMessage>
 */
class CampaignMessageFactory extends Factory
{
    protected $model = CampaignMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'subscriber_id' => Subscriber::factory(),
            'list_ids' => null,
            'email' => fn (array $attributes): ?string => Subscriber::query()
                ->whereKey($attributes['subscriber_id'])
                ->first()?->email,
            'locale' => 'en',
            'position' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'status' => MessageStatus::Pending,
            'claim_id' => null,
            'token_hash' => null,
            'provider_message_id' => null,
            'claimed_at' => null,
            'sending_at' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'opened_at' => null,
            'clicked_at' => null,
            'unsubscribed_at' => null,
            'complained_at' => null,
            'failed_at' => null,
            'failure_severity' => null,
            'failure_reason' => null,
            'failure_code' => null,
        ];
    }
}
