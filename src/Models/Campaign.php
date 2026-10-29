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
use Mozex\Campaigns\Database\Factories\CampaignFactory;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Concerns\HasRevisions;

/**
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property string $name
 * @property CampaignStatus $status
 * @property ?string $from_name
 * @property string $from_email
 * @property ?string $reply_to
 * @property string $tag
 * @property bool $track_opens
 * @property bool $track_clicks
 * @property ?array<string, string> $utm
 * @property ?list<int> $target_lists
 * @property ?array<string, mixed> $audience
 * @property ?array<string, mixed> $pace
 * @property ?array<string, mixed> $thresholds
 * @property ?int $recipient_count
 * @property ?string $pause_reason
 * @property ?string $start_error
 * @property ?string $start_run
 * @property ?array<string, mixed> $report
 * @property ?int $template_id
 * @property ?int $frozen_revision_id
 * @property ?int $duplicated_from_id
 * @property ?Carbon $scheduled_at
 * @property ?Carbon $pace_started_at
 * @property ?Carbon $rate_halved_until
 * @property ?Carbon $started_at
 * @property ?Carbon $paused_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $canceled_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(CampaignFactory::class)]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    use HasRevisions;
    use HasUuids;

    protected $table = 'campaigns';

    protected $fillable = [
        'key',
        'name',
        'status',
        'from_name',
        'from_email',
        'reply_to',
        'tag',
        'track_opens',
        'track_clicks',
        'utm',
        'target_lists',
        'audience',
        'pace',
        'thresholds',
        'recipient_count',
        'pause_reason',
        'start_error',
        'start_run',
        'report',
        'template_id',
        'frozen_revision_id',
        'duplicated_from_id',
        'scheduled_at',
        'pace_started_at',
        'rate_halved_until',
        'started_at',
        'paused_at',
        'completed_at',
        'canceled_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{status: class-string<CampaignStatus>, track_opens: 'boolean', track_clicks: 'boolean', utm: 'array', target_lists: 'array', audience: 'array', pace: 'array', thresholds: 'array', recipient_count: 'integer', report: 'array', scheduled_at: 'datetime', pace_started_at: 'datetime', rate_halved_until: 'datetime', started_at: 'datetime', paused_at: 'datetime', completed_at: 'datetime', canceled_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'utm' => 'array',
            'target_lists' => 'array',
            'audience' => 'array',
            'pace' => 'array',
            'thresholds' => 'array',
            'recipient_count' => 'integer',
            'report' => 'array',
            'scheduled_at' => 'datetime',
            'pace_started_at' => 'datetime',
            'rate_halved_until' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /**
     * The UTM tags its links get when it starts: the config's source and
     * medium and its own key, under whatever it set itself; none when it
     * switched them off (an empty utm).
     *
     * @return array<string, string>
     */
    public function utmTags(): array
    {
        if ($this->utm === []) {
            return [];
        }

        return array_filter([
            'source' => (string) config('campaigns.utm.source', 'newsletter'),
            'medium' => (string) config('campaigns.utm.medium', 'email'),
            'campaign' => $this->key,
            ...$this->utm ?? [],
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The domain it sends from, its From address's, which the pace's daily
     * cap and a provider's refusal are counted by.
     */
    public function sendingDomain(): string
    {
        return strtolower(substr($this->from_email, (int) strrpos($this->from_email, '@') + 1));
    }

    /** @return HasMany<CampaignMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    /** @return HasMany<TestSend, $this> */
    public function testSends(): HasMany
    {
        return $this->hasMany(TestSend::class);
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<Revision, $this> */
    public function frozenRevision(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'frozen_revision_id');
    }

    /** @return BelongsTo<self, $this> */
    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicated_from_id');
    }
}
