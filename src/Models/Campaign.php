<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mozex\Campaigns\Database\Factories\CampaignFactory;
use Mozex\Campaigns\Enums\CampaignStatus;

#[UseFactory(CampaignFactory::class)]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

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
        'report',
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
     * @return array{status: class-string<CampaignStatus>, track_opens: 'boolean', track_clicks: 'boolean', utm: 'array', target_lists: 'array', audience: 'array', pace: 'array', thresholds: 'array', recipient_count: 'integer', report: 'array', started_at: 'datetime', paused_at: 'datetime', completed_at: 'datetime', canceled_at: 'datetime'}
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
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
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
}
