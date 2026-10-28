<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mozex\Campaigns\Database\Factories\CampaignListFactory;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;

#[UseFactory(CampaignListFactory::class)]
class CampaignList extends Model
{
    /** @use HasFactory<CampaignListFactory> */
    use HasFactory;

    protected $table = 'campaign_lists';

    protected $fillable = [
        'key',
        'name',
        'description',
        'kind',
        'double_opt_in',
        'default_status',
        'default_basis',
    ];

    /**
     * @return array{kind: class-string<ListKind>, double_opt_in: 'boolean', default_status: class-string<SubscriptionStatus>, default_basis: class-string<ConsentBasis>}
     */
    protected function casts(): array
    {
        return [
            'kind' => ListKind::class,
            'double_opt_in' => 'boolean',
            'default_status' => SubscriptionStatus::class,
            'default_basis' => ConsentBasis::class,
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'list_id');
    }

    /** @return BelongsToMany<Subscriber, $this> */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, 'campaign_list_subscriptions', 'list_id', 'subscriber_id')
            ->withPivot('status', 'basis', 'source')
            ->withTimestamps();
    }
}
