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
use Mozex\Campaigns\Database\Factories\TestSendFactory;

/**
 * A test email of a campaign's or a template's revision, kept apart from the
 * campaign's messages so it never counts in the campaign's numbers. Its links
 * open the real pages but change nothing. The token is made when the email is
 * sent, so token_hash is null until then.
 *
 * @property int $id
 * @property string $uuid
 * @property ?int $campaign_id
 * @property ?int $revision_id
 * @property string $email
 * @property ?string $locale
 * @property ?string $token_hash
 * @property ?string $driver
 * @property ?string $provider_message_id
 * @property ?Carbon $sent_at
 * @property ?Carbon $failed_at
 * @property ?string $failure
 * @property ?Carbon $unsubscribed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Campaign $campaign
 * @property-read ?Revision $revision
 */
#[UseFactory(TestSendFactory::class)]
class TestSend extends Model
{
    /** @use HasFactory<TestSendFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The tag every test email carries, so tests never count in a campaign's
     * tag in the provider's numbers.
     */
    public const TAG = 'campaigns-test';

    protected $table = 'campaign_test_sends';

    protected $fillable = [
        'campaign_id',
        'revision_id',
        'email',
        'locale',
        'token_hash',
        'driver',
        'provider_message_id',
        'sent_at',
        'failed_at',
        'failure',
        'unsubscribed_at',
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
     * @return array{sent_at: 'datetime', failed_at: 'datetime', unsubscribed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Revision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }

    /** @return HasMany<ProviderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'test_send_id');
    }
}
