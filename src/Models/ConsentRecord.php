<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\ConsentRecordFactory;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;

/**
 * One entry in the append-only consent ledger: what changed, on what basis,
 * from where, under which notice, and, when a person acted, their IP and
 * user agent. Entries are written once and never updated.
 *
 * @property int $id
 * @property ?int $subscriber_id
 * @property ?int $list_id
 * @property ConsentAction $action
 * @property ?ConsentBasis $basis
 * @property ?string $source
 * @property ?string $notice_version
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?array<string, mixed> $meta
 * @property Carbon $occurred_at
 */
#[UseFactory(ConsentRecordFactory::class)]
class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'campaign_consent_records';

    protected $fillable = [
        'subscriber_id',
        'list_id',
        'action',
        'basis',
        'source',
        'notice_version',
        'ip_address',
        'user_agent',
        'meta',
        'occurred_at',
    ];

    /**
     * @return array{action: class-string<ConsentAction>, basis: class-string<ConsentBasis>, meta: 'array', occurred_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'action' => ConsentAction::class,
            'basis' => ConsentBasis::class,
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return BelongsTo<CampaignList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(CampaignList::class, 'list_id');
    }
}
