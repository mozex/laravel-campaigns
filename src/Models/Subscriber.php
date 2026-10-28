<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Mozex\Campaigns\Database\Factories\SubscriberFactory;
use Mozex\Campaigns\Enums\BlockReason;

/**
 * One email address in the app, optionally linked to the app record it
 * belongs to (usually a user). Opting out is the person's choice and can be
 * undone; a block is a fact about the address.
 *
 * The free-form data the app keeps per subscriber lives in custom_fields, not
 * a column called attributes, which would collide with Eloquent's own
 * $attributes property inside the model.
 */
#[UseFactory(SubscriberFactory::class)]
class Subscriber extends Model
{
    /** @use HasFactory<SubscriberFactory> */
    use HasFactory;

    protected $table = 'campaign_subscribers';

    protected $fillable = [
        'email',
        'name',
        'first_name',
        'locale',
        'timezone',
        'country',
        'custom_fields',
        'last_opened_at',
        'last_clicked_at',
        'last_active_at',
        'silent_since',
        'opted_out_at',
        'blocked_at',
        'blocked_reason',
        'soft_bounces',
    ];

    /**
     * @return array{custom_fields: 'array', last_opened_at: 'datetime', last_clicked_at: 'datetime', last_active_at: 'datetime', silent_since: 'datetime', opted_out_at: 'datetime', blocked_at: 'datetime', blocked_reason: class-string<BlockReason>, soft_bounces: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'last_opened_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'last_active_at' => 'datetime',
            'silent_since' => 'datetime',
            'opted_out_at' => 'datetime',
            'blocked_at' => 'datetime',
            'blocked_reason' => BlockReason::class,
            'soft_bounces' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return BelongsToMany<CampaignList, $this> */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(CampaignList::class, 'campaign_list_subscriptions', 'subscriber_id', 'list_id')
            ->withPivot('status', 'basis', 'source')
            ->withTimestamps();
    }

    /** @return HasMany<ConsentRecord, $this> */
    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /** @return HasMany<CampaignMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }
}
