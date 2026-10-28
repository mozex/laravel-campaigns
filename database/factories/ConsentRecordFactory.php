<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;

/**
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscriber_id' => Subscriber::factory(),
            'list_id' => null,
            'action' => ConsentAction::Subscribe,
            'basis' => ConsentBasis::Consent,
            'source' => 'api',
            'notice_version' => null,
            'ip_address' => null,
            'user_agent' => null,
            'meta' => null,
            'occurred_at' => now(),
        ];
    }
}
