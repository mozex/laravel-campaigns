<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Models\Template;

/**
 * A revision of a new template, numbered after the owner's last one. Its
 * contents come from withContent(), or from RevisionContent's factory.
 *
 * @extends Factory<Revision>
 */
class RevisionFactory extends Factory
{
    protected $model = Revision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'revisable_type' => fn (): string => (new Template)->getMorphClass(),
            'revisable_id' => Template::factory(),
            'number' => fn (array $attributes): int => 1 + (int) Revision::query()
                ->where('revisable_type', $attributes['revisable_type'])
                ->where('revisable_id', $attributes['revisable_id'])
                ->max('number'),
            'note' => null,
            'author' => null,
            'check_results' => null,
            'checked_at' => null,
        ];
    }

    public function of(Model $owner): static
    {
        return $this->state([
            'revisable_type' => $owner->getMorphClass(),
            'revisable_id' => $owner->getKey(),
        ]);
    }

    /**
     * Gives the revision one locale's content.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withContent(string $locale = 'en', array $attributes = []): static
    {
        return $this->has(RevisionContent::factory()->state(['locale' => $locale, ...$attributes]), 'contents');
    }
}
