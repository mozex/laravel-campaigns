<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Imports;

use Mozex\Campaigns\Enums\ImportChange;
use RuntimeException;

/**
 * Carries a dry run's row out of its transaction, which Laravel then rolls
 * back as it does for any exception.
 *
 * @internal
 */
class RolledBackRow extends RuntimeException
{
    /**
     * @param  array{list<ImportChange>, bool}  $result
     */
    public function __construct(public readonly array $result)
    {
        parent::__construct('A dry run rolls back every row it imports.');
    }
}
