<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Models\SavedAudience;

/**
 * @extends Factory<SavedAudience>
 */
class SavedAudienceFactory extends Factory
{
    protected $model = SavedAudience::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->sentence(3),
            'description' => null,
            'filters' => ['tiers' => ['engaged']],
        ];
    }
}
