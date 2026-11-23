<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Database\Factories\CampaignListFactory;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property ListKind $kind
 * @property bool $double_opt_in
 * @property SubscriptionStatus $default_status
 * @property ConsentBasis $default_basis
 * @property bool $is_default
 * @property ?Carbon $archived_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
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
        'is_default',
        'archived_at',
    ];

    /**
     * @return array{kind: class-string<ListKind>, double_opt_in: 'boolean', default_status: class-string<SubscriptionStatus>, default_basis: class-string<ConsentBasis>, is_default: 'boolean', archived_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'kind' => ListKind::class,
            'double_opt_in' => 'boolean',
            'default_status' => SubscriptionStatus::class,
            'default_basis' => ConsentBasis::class,
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The list with this key, archived or not.
     *
     * @throws CampaignsException when no list has it
     */
    public static function forKey(string $key): self
    {
        return static::query()->where('key', $key)->first() ?? throw CampaignsException::unknownLists([$key]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * The default lists, which a campaign created without lists of its own
     * goes to. Archiving takes the mark away, and an archived list never
     * counts even when a write raced the archive.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDefaults(Builder $query): void
    {
        $query->active()->where('is_default', true);
    }

    /**
     * The default lists' keys, in key order.
     *
     * @return list<string>
     */
    public static function defaultKeys(): array
    {
        /** @var list<string> */
        return static::query()->defaults()->orderBy('key')->pluck('key')->all();
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
