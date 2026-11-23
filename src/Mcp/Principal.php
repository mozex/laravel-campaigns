<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Mozex\Campaigns\Contracts\HasCampaignRights;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Models\ApiToken;

/**
 * Who is calling the MCP server, and what they may do: whatever their model
 * answers through HasCampaignRights, which a package token implements with
 * its own rights and an app's user model with the app's own checks. Anyone
 * else has none and sees nothing.
 */
class Principal
{
    public const NAME_LENGTH = 255;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly ?Rights $rights,
    ) {}

    /**
     * The signed-in principal of this request, or null when nobody is.
     */
    public static function current(): ?self
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        // Worked out once a request: every one of the server's tools asks
        // before it is listed, and an app's answer may query its database.
        $known = request()->attributes->get(self::class);

        if ($known instanceof self && $known->user === $user) {
            return $known;
        }

        $principal = static::for($user);
        request()->attributes->set(self::class, $principal);

        return $principal;
    }

    public static function for(Authenticatable $user): self
    {
        return new self($user, $user instanceof HasCampaignRights ? $user->campaignRights() : null);
    }

    public function allows(Rights $needed): bool
    {
        return $this->rights?->allows($needed) ?? false;
    }

    /**
     * Who this is in the records an agent's changes leave: a token's name,
     * else the person's name or email, else their id; at most
     * NAME_LENGTH characters.
     */
    public function name(): string
    {
        if ($this->user instanceof ApiToken) {
            return $this->user->name;
        }

        foreach (['name', 'email'] as $field) {
            $value = data_get($this->user, $field);

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, static::NAME_LENGTH);
            }
        }

        return mb_substr((string) $this->user->getAuthIdentifier(), 0, static::NAME_LENGTH);
    }
}
