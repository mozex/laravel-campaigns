<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\WebhookTokenFactory;

/**
 * A verified webhook's one-time token (its hash) and the event it carried.
 * The signature covers only the token and a timestamp, so this is what
 * stops a captured request's token from carrying another event.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $provider_event_id
 * @property Carbon $created_at
 */
#[UseFactory(WebhookTokenFactory::class)]
class WebhookToken extends Model
{
    /** @use HasFactory<WebhookTokenFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * How long a token must be kept: twice the day a webhook's signed
     * timestamp may be off from now (ReceivesWebhooks), since that day runs
     * both ways; a request older than that is refused anyway.
     */
    public const KEEP_HOURS = 48;

    protected $table = 'campaign_webhook_tokens';

    protected $fillable = [
        'token_hash',
        'provider_event_id',
    ];

    protected $hidden = [
        'token_hash',
    ];
}
