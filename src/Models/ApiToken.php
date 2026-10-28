<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\ApiTokenFactory;

/**
 * A named token an agent signs in with. Only its hash is stored; the token
 * itself is shown once, when it is made.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(ApiTokenFactory::class)]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    protected $table = 'campaign_api_tokens';

    protected $fillable = [
        'name',
        'token_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array{last_used_at: 'datetime', revoked_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
