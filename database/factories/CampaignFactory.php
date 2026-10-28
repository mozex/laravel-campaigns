<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = $this->faker->unique()->slug(3);

        return [
            'key' => $key,
            'name' => $this->faker->sentence(4),
            'status' => CampaignStatus::Draft,
            'from_name' => 'Example Team',
            'from_email' => 'team@news.example.com',
            'reply_to' => null,
            'tag' => $key,
            'track_opens' => true,
            'track_clicks' => true,
            'utm' => null,
            'target_lists' => null,
            'audience' => null,
            'pace' => null,
            'thresholds' => null,
            'recipient_count' => null,
            'pause_reason' => null,
            'report' => null,
            'started_at' => null,
            'paused_at' => null,
            'completed_at' => null,
            'canceled_at' => null,
        ];
    }

    public function sending(): static
    {
        return $this->state([
            'status' => CampaignStatus::Sending,
            'started_at' => now(),
        ]);
    }
}
