<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Models\Asset;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'campaigns/assets/'.$this->faker->uuid().'.png';

        return [
            'disk' => 'public',
            'path' => $path,
            'url' => 'https://cdn.example.com/'.$path,
            'name' => $this->faker->word().'.png',
            'mime' => 'image/png',
            'size' => $this->faker->numberBetween(1_000, 500_000),
            'width' => 600,
            'height' => 300,
            'author' => null,
        ];
    }
}
