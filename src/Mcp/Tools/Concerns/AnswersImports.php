<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Mozex\Campaigns\Imports\ImportResult;

/**
 * An import's result as a tool answers it: its problems, one per contact at
 * worst, cut to the first MAX_PROBLEMS, so a file full of mistakes still
 * fits in one answer.
 */
trait AnswersImports
{
    protected const MAX_PROBLEMS = 50;

    /**
     * @return array<string, mixed>
     */
    protected static function importAnswer(ImportResult $result): array
    {
        return [
            ...$result->toArray(),
            'problems' => array_slice($result->problems, 0, static::MAX_PROBLEMS),
            'problems_total' => count($result->problems),
        ];
    }
}
