<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'list_id' => CampaignList::factory(),
            'subscriber_id' => Subscriber::factory(),
            'status' => SubscriptionStatus::Subscribed,
            'basis' => ConsentBasis::Consent,
            'source' => 'api',
            'confirm_token_hash' => null,
            'subscribed_at' => now(),
            'confirmed_at' => null,
            'unsubscribed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Pending,
            'confirm_token_hash' => hash('sha256', $this->faker->uuid()),
            'subscribed_at' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Unsubscribed,
            'unsubscribed_at' => now(),
        ]);
    }
}
