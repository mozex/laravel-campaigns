<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Observers;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Mozex\Campaigns\Actions\ForgetSubscriberAction;
use Mozex\Campaigns\Actions\SyncRecordAction;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\ListSource;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\DatabaseErrors;
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

    /**
     * A record that becomes eligible in this save joins under the consent
     * context around it, so an app that lets a record join only later (an
     * account whose address must be confirmed first) records the notice and
     * the request at the save that makes it eligible. Any other record of the
     * class saved inside the callback (a referrer credited at sign-up) keeps
     * its own consent, and the context's choice never reaches an update, so
     * nobody's opt-out is cleared or declined by a save made for someone else.
     * A list source whose eligibility reads other tables sees no change here
     * and joins the record as a sync.
     */
    public function updated(Model $record): void
    {
        $context = $this->campaigns->consentFor($record);

        foreach (array_keys($this->campaigns->listSourcesFor($record)) as $key) {
            $this->safely(fn () => $this->sync->execute(
                $key,
                $record,
                $context !== null && $this->joinsNow($key, $record) ? $this->withoutChoice($context) : null,
            ));
        }
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

    /**
     * Whether the save made the record eligible for the list: Eloquent keeps
     * the attributes it had before the save until the save is done.
     */
    protected function joinsNow(string $key, Model $record): bool
    {
        $source = $this->campaigns->listSource($key);
        $before = (clone $record)->setRawAttributes($record->getRawOriginal());

        return ! $this->isEligible($source, $before) && $this->isEligible($source, $record);
    }

    protected function isEligible(ListSource $source, Model $record): bool
    {
        return ! (method_exists($record, 'trashed') && $record->trashed()) && $source->isEligible($record);
    }

    protected function withoutChoice(ConsentContext $context): ConsentContext
    {
        return new ConsentContext(
            source: $context->source,
            basis: $context->basis,
            noticeVersion: $context->noticeVersion,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            meta: $context->meta,
        );
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
            // Passed on without its SQL, which holds the person's address,
            // still a deadlock the app's transaction retries on.
            if ($insideTransaction) {
                throw DatabaseErrors::scrub('a record\'s list sync', $exception);
            }

            // Laravel gives a deadlock code 0 when the state is a string
            // ("40001"); the query exception it wraps has the real one.
            report(CampaignsException::syncFailed($exception->getPrevious()?->getCode() ?? $exception->getCode()));
        } catch (QueryException $exception) {
            // Its message is the SQL with the person's address, which an
            // error report must not carry: only its code goes.
            report(CampaignsException::syncFailed($exception->getCode()));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
