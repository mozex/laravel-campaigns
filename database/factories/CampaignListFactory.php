<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;

/**
 * @extends Factory<CampaignList>
 */
class CampaignListFactory extends Factory
{
    protected $model = CampaignList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'description' => null,
            'kind' => ListKind::Static,
            'double_opt_in' => false,
            'default_status' => SubscriptionStatus::Subscribed,
            'default_basis' => ConsentBasis::Consent,
        ];
    }

    public function dynamic(): static
    {
        return $this->state([
            'kind' => ListKind::Dynamic,
            'default_basis' => ConsentBasis::SoftOptIn,
        ]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }

    public function doubleOptIn(): static
    {
        return $this->state([
            'double_opt_in' => true,
            'default_status' => SubscriptionStatus::Pending,
        ]);
    }
}
