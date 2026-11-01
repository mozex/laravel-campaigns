<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Models\Revision;

/**
 * @extends Factory<CampaignLink>
 */
class CampaignLinkFactory extends Factory
{
    protected $model = CampaignLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = 'https://example.com/'.$this->faker->unique()->slug();

        return [
            'revision_id' => Revision::factory(),
            'key' => Str::random(CampaignLink::KEY_LENGTH),
            'url' => $url,
            'url_hash' => CampaignLink::hash($url),
        ];
    }
}
