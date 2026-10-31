<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\ProviderEventFactory;
use Mozex\Campaigns\Enums\FeedbackType;

/**
 * Something the sending provider reported about an email (delivered, failed,
 * opened, clicked, a spam report), stored once by the provider's event id.
 *
 * @property int $id
 * @property string $provider_event_id
 * @property string $driver
 * @property ?int $message_id
 * @property ?int $test_send_id
 * @property FeedbackType $type
 * @property bool $is_bot
 * @property ?array<string, mixed> $payload
 * @property Carbon $occurred_at
 * @property ?Carbon $processed_at
 * @property ?Carbon $created_at
 */
#[UseFactory(ProviderEventFactory::class)]
class ProviderEvent extends Model
{
    /** @use HasFactory<ProviderEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'campaign_provider_events';

    protected $fillable = [
        'provider_event_id',
        'driver',
        'message_id',
        'test_send_id',
        'type',
        'is_bot',
        'payload',
        'occurred_at',
        'processed_at',
    ];

    /**
     * @return array{type: class-string<FeedbackType>, is_bot: 'boolean', payload: 'array', occurred_at: 'datetime', processed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'type' => FeedbackType::class,
            'is_bot' => 'boolean',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CampaignMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CampaignMessage::class, 'message_id');
    }

    /** @return BelongsTo<TestSend, $this> */
    public function testSend(): BelongsTo
    {
        return $this->belongsTo(TestSend::class);
    }
}
