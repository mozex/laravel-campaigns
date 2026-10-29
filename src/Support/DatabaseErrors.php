<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Closure;
use Mozex\Campaigns\CampaignsException;
use PDOException;

/**
 * Database errors without the person in them. A PDO error's message holds
 * the SQL with its bindings (an address, a visitor's IP address and
 * browser), and the queue's failed_jobs, the error tracker and a debug page
 * all show it whole. So the entry points that write a person's details (the
 * feedback job, the pages' POSTs, the webhook, the MCP tools, the
 * suppressions import) rethrow one with its SQLSTATE only, unchained, so no
 * handler walks back to the original. PDOException, not QueryException:
 * Laravel's DeadlockException is a PDOException of its own carrying the
 * same message.
 */
class DatabaseErrors
{
    /**
     * Runs the work, a database error rethrown without its bindings.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     *
     * @throws CampaignsException when the database refused the work
     */
    public static function scrubbed(string $doing, Closure $work): mixed
    {
        try {
            return $work();
        } catch (PDOException $exception) {
            throw static::scrub($doing, $exception);
        }
    }

    /**
     * The error as one that names what was being done and the SQLSTATE.
     */
    public static function scrub(string $doing, PDOException $exception): CampaignsException
    {
        return CampaignsException::databaseFailed($doing, static::sqlState($exception));
    }

    /**
     * The SQLSTATE of the error or of one it wraps (a DeadlockException
     * keeps it on the QueryException it wraps), else its code.
     */
    public static function sqlState(PDOException $exception): string
    {
        for ($current = $exception; $current instanceof PDOException; $current = $current->getPrevious()) {
            $state = $current->errorInfo[0] ?? null;

            if (is_string($state) && $state !== '') {
                return $state;
            }
        }

        return (string) $exception->getCode();
    }
}
