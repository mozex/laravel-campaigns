<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\ApiTokenFactory;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Support\Token;

/**
 * A named token an agent signs in to the MCP server with. Only its hash is
 * stored; the token itself is shown once, when it is made.
 *
 * It is the authenticated user while its request runs, so it implements
 * Authenticatable itself: the framework's trait reads password and
 * remember-token columns this table doesn't have, which strict models
 * refuse.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property Rights $rights
 * @property ?Carbon $last_used_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(ApiTokenFactory::class)]
class ApiToken extends Model implements Authenticatable
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * How often a token's last use is written: once a minute is enough to
     * tell a token in use from a forgotten one, without a write per call.
     */
    public const LAST_USED_EVERY_SECONDS = 60;

    protected $table = 'campaign_api_tokens';

    protected $fillable = [
        'name',
        'token_hash',
        'rights',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array{rights: 'Mozex\Campaigns\Enums\Rights', last_used_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'rights' => Rights::class,
            'last_used_at' => 'datetime',
        ];
    }

    public static function findByToken(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        return static::query()->where('token_hash', Token::hash($token))->first();
    }

    /**
     * Writes the last use when it is older than a minute, through the query
     * builder so updated_at keeps meaning the token itself changed.
     */
    public function touchLastUsed(): void
    {
        $now = now();

        if ($this->last_used_at !== null && $this->last_used_at->gt($now->copy()->subSeconds(static::LAST_USED_EVERY_SECONDS))) {
            return;
        }

        static::query()->whereKey($this->id)->toBase()->update(['last_used_at' => $now]);

        $this->last_used_at = $now;
        $this->syncOriginalAttribute('last_used_at');
    }

    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'token_hash';
    }

    public function getAuthPassword(): string
    {
        return $this->token_hash;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void
    {
        //
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
