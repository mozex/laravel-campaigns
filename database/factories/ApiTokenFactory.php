<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Support\Token;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'token_hash' => Token::hash($this->faker->unique()->uuid()),
            'rights' => Rights::Full,
            'last_used_at' => null,
        ];
    }

    public function readOnly(): static
    {
        return $this->state(['rights' => Rights::Read]);
    }

    /**
     * The token an agent would send, stored as its hash.
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => Token::hash($token)]);
    }
}
