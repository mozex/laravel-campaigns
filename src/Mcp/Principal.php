<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Models\ApiToken;

/**
 * Who is calling the MCP server, and what they may do: a package token with
 * its own rights, or a person signed in through one of the app's guards,
 * whose rights are the gate abilities the app defines (campaigns.full, then
 * campaigns.read). Anyone else has none and sees nothing.
 */
class Principal
{
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

        return $user === null ? null : static::for($user);
    }

    public static function for(Authenticatable $user): self
    {
        if ($user instanceof ApiToken) {
            return new self($user, $user->rights);
        }

        $gate = Gate::forUser($user);

        $rights = match (true) {
            $gate->allows(Rights::Full->ability()) => Rights::Full,
            $gate->allows(Rights::Read->ability()) => Rights::Read,
            default => null,
        };

        return new self($user, $rights);
    }

    public function allows(Rights $needed): bool
    {
        return $this->rights?->allows($needed) ?? false;
    }
}
