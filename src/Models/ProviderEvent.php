<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mozex\Campaigns\Database\Factories\ProviderEventFactory;

/**
 * Something the sending provider reported about an email (delivered, failed,
 * opened, clicked, a spam report), stored once by the provider's event id.
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
        'signature_token_hash',
        'message_id',
        'test_send_id',
        'type',
        'is_bot',
        'payload',
        'occurred_at',
    ];

    protected $hidden = [
        'signature_token_hash',
    ];

    /**
     * @return array{is_bot: 'boolean', payload: 'array', occurred_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'payload' => 'array',
            'occurred_at' => 'datetime',
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
