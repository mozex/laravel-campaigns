<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;

/**
 * @extends Factory<RevisionContent>
 */
class RevisionContentFactory extends Factory
{
    protected $model = RevisionContent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'revision_id' => Revision::factory(),
            'locale' => 'en',
            'subject' => $this->faker->sentence(5),
            'preheader' => null,
            'html' => '<p>Hello {{first_name|there}}</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
            'text' => "Hello {{first_name|there}}\n\nUnsubscribe: {{unsubscribe_url}}",
            'text_generated' => false,
        ];
    }
}
