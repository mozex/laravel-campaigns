<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mozex\Campaigns\Database\Factories\SubscriptionFactory;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;

/**
 * A subscriber's place on one list. Only the hash of a double opt-in token
 * is stored; the raw token exists only in the email that carries it.
 */
#[UseFactory(SubscriptionFactory::class)]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $table = 'campaign_list_subscriptions';

    protected $fillable = [
        'list_id',
        'subscriber_id',
        'status',
        'basis',
        'source',
        'confirm_token_hash',
        'subscribed_at',
        'confirmed_at',
        'unsubscribed_at',
    ];

    protected $hidden = [
        'confirm_token_hash',
    ];

    /**
     * @return array{status: class-string<SubscriptionStatus>, basis: class-string<ConsentBasis>, subscribed_at: 'datetime', confirmed_at: 'datetime', unsubscribed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'basis' => ConsentBasis::class,
            'subscribed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CampaignList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(CampaignList::class, 'list_id');
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
