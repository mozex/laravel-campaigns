<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\CampaignMessageFactory;
use Mozex\Campaigns\Enums\MessageStatus;

/**
 * One campaign's email to one subscriber. Its uuid travels to the provider
 * with the message, so the provider's events and logs lead back to it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $campaign_id
 * @property ?int $subscriber_id
 * @property ?list<int> $list_ids
 * @property ?string $email
 * @property ?string $locale
 * @property int $position
 * @property MessageStatus $status
 * @property ?string $claim_id
 * @property ?string $token_hash
 * @property ?string $provider_message_id
 * @property ?Carbon $claimed_at
 * @property ?Carbon $sending_at
 * @property ?Carbon $sent_at
 * @property ?Carbon $delivered_at
 * @property ?Carbon $opened_at
 * @property ?Carbon $clicked_at
 * @property ?Carbon $unsubscribed_at
 * @property ?Carbon $complained_at
 * @property ?Carbon $failed_at
 * @property ?string $failure_severity
 * @property ?string $failure_reason
 * @property ?int $failure_code
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(CampaignMessageFactory::class)]
class CampaignMessage extends Model
{
    /** @use HasFactory<CampaignMessageFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'campaign_messages';

    protected $fillable = [
        'campaign_id',
        'subscriber_id',
        'list_ids',
        'email',
        'locale',
        'position',
        'status',
        'claim_id',
        'token_hash',
        'provider_message_id',
        'claimed_at',
        'sending_at',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'unsubscribed_at',
        'complained_at',
        'failed_at',
        'failure_severity',
        'failure_reason',
        'failure_code',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{list_ids: 'array', position: 'integer', status: class-string<MessageStatus>, claimed_at: 'datetime', sending_at: 'datetime', sent_at: 'datetime', delivered_at: 'datetime', opened_at: 'datetime', clicked_at: 'datetime', unsubscribed_at: 'datetime', complained_at: 'datetime', failed_at: 'datetime', failure_code: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'list_ids' => 'array',
            'position' => 'integer',
            'status' => MessageStatus::class,
            'claimed_at' => 'datetime',
            'sending_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'complained_at' => 'datetime',
            'failed_at' => 'datetime',
            'failure_code' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return HasMany<ProviderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'message_id');
    }
}
