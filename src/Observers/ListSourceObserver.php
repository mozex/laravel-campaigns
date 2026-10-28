<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Observers;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Actions\ForgetSubscriberAction;
use Mozex\Campaigns\Actions\SyncRecordAction;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Throwable;

/**
 * Keeps the dynamic lists of a model that uses HasCampaignSubscriber in step
 * with its records as they are created, changed and deleted.
 *
 * A failure here is reported and never thrown: the app's own save (a
 * sign-up, a checkout) must not fail because of its marketing list, and the
 * sync command repairs what was missed.
 */
class ListSourceObserver
{
    public function __construct(
        protected Campaigns $campaigns,
        protected SyncRecordAction $sync,
        protected ForgetSubscriberAction $forget,
    ) {}

    /**
     * A record made with no consent context, such as an account staff
     * created, joins its lists with their default consent, from source
     * "staff".
     */
    public function created(Model $record): void
    {
        $this->sync($record, $this->campaigns->consentFor($record) ?? new ConsentContext('staff'));
    }

    public function updated(Model $record): void
    {
        $this->sync($record, null);
    }

    /**
     * A soft delete takes the record off its lists and keeps its subscriber,
     * since the record can come back; any other delete forgets the person.
     */
    public function deleted(Model $record): void
    {
        if (method_exists($record, 'isForceDeleting') && ! $record->isForceDeleting()) {
            $this->sync($record, null);

            return;
        }

        $this->safely(function () use ($record): void {
            $subscriber = Subscriber::findFor($record);

            if ($subscriber !== null) {
                $this->forget->execute($subscriber);
            }
        });
    }

    protected function sync(Model $record, ?ConsentContext $context): void
    {
        foreach (array_keys($this->campaigns->listSourcesFor($record)) as $key) {
            $this->safely(fn () => $this->sync->execute($key, $record, $context));
        }
    }

    /**
     * Runs the work in its own savepoint, so a failure rolls back only its
     * writes and leaves the app's own transaction usable.
     *
     * A deadlock or lock timeout inside the app's transaction is the one
     * failure passed on: Laravel rolls nothing back to the savepoint then, and
     * a deadlock has already ended (MySQL) or aborted (PostgreSQL) the whole
     * transaction, so the app has to know.
     *
     * @param  Closure(): mixed  $callback
     */
    protected function safely(Closure $callback): void
    {
        $connection = (new Subscriber)->getConnection();
        $insideTransaction = $connection->transactionLevel() > 0;

        try {
            $connection->transaction($callback);
        } catch (DeadlockException $exception) {
            if ($insideTransaction) {
                throw $exception;
            }

            report($exception);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
