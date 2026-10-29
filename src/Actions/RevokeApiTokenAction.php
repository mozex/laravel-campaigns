<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Models\ApiToken;

/**
 * Revokes a token by deleting it: nothing refers to a token's row, and its
 * name is free for the machine's next one.
 */
class RevokeApiTokenAction
{
    /**
     * Returns false when no token has that name.
     */
    public function execute(string $name): bool
    {
        return ApiToken::query()->where('name', trim($name))->delete() > 0;
    }
}
