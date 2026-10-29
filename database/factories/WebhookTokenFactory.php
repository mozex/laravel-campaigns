<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Models\WebhookToken;

/**
 * @extends Factory<WebhookToken>
 */
class WebhookTokenFactory extends Factory
{
    protected $model = WebhookToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'provider_event_id' => $this->faker->unique()->regexify('[A-Za-z0-9]{22}'),
        ];
    }
}
