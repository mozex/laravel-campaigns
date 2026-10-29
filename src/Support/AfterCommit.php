<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Database\Connection;

/**
 * Tells the app's listeners of a change once the transaction that made it
 * commits (at once when there is none), a listener's failure reported rather
 * than thrown: the change is made by then, and a broken listener must fail
 * neither the sign-up, unsubscribe or event processing that made it nor the
 * other events waiting for the same commit. An event whose listener fails
 * reaches none of the listeners after it, and a test expectation failing
 * inside a listener is reported too, never thrown.
 */
class AfterCommit
{
    public static function dispatch(Connection $connection, object $event): void
    {
        $connection->afterCommit(fn (): mixed => rescue(fn (): mixed => event($event)));
    }
}
