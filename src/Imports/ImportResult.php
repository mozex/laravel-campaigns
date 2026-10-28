<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Imports;

use Mozex\Campaigns\Enums\ImportChange;

/**
 * What an import did, or would do on a dry run. Problems name a row by its
 * number, never by its address, so the result can be shown anywhere.
 */
readonly class ImportResult
{
    /**
     * @param  int  $rows  The rows read, not counting the ones skipped for another address.
     * @param  array<value-of<ImportChange>, int>  $counts  How many rows made each change; a row can make several.
     * @param  int  $suppressed  The imported addresses that end opted out or blocked, so never mailed.
     * @param  list<string>  $problems  The rows that couldn't be imported, and why.
     */
    public function __construct(
        public bool $dryRun,
        public int $rows,
        public array $counts,
        public int $suppressed,
        public array $problems,
    ) {}

    public function count(ImportChange $change): int
    {
        return $this->counts[$change->value] ?? 0;
    }

    /**
     * @return array{dry_run: bool, rows: int, counts: array<value-of<ImportChange>, int>, never_mailed: int, problems: list<string>}
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'rows' => $this->rows,
            'counts' => $this->counts,
            'never_mailed' => $this->suppressed,
            'problems' => $this->problems,
        ];
    }
}
