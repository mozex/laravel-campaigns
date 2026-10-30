<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ForgetSubscriberAction;
use Mozex\Campaigns\Actions\SyncRecordAction;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\DatabaseFailedException;
use Mozex\Campaigns\Enums\SyncChange;
use Mozex\Campaigns\Lists\SyncResult;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\DatabaseErrors;
use PDOException;
use Throwable;

class SyncListCommand extends Command
{
    protected $signature = 'campaigns:sync-list
        {list : The key of the dynamic list, as in campaigns.list_sources}
        {--id= : Sync only the record with this key}
        {--force : Write the changes; without it nothing is written}';

    protected $description = 'Bring a dynamic list in step with the app records behind it';

    public function handle(Campaigns $campaigns, SyncRecordAction $sync, ForgetSubscriberAction $forget): int
    {
        $key = (string) $this->argument('list');

        try {
            $query = $campaigns->listSource($key)->query();
        } catch (CampaignsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @var ?string $id */
        $id = $this->option('id');

        if ($id !== null) {
            $query->whereKey($id);

            // PostgreSQL refuses a key of the wrong type (abc for an integer
            // key) or out of its range with an error, where the others find
            // nothing. Any other error is the database's, and is thrown.
            try {
                $exists = $query->exists();
            } catch (PDOException $exception) {
                if (! in_array(DatabaseErrors::sqlState($exception), ['22P02', '22003'], true)) {
                    throw $exception;
                }

                $exists = false;
            }

            if (! $exists) {
                $this->error("No record has the key {$id}.");

                return self::FAILURE;
            }
        }

        $dryRun = ! $this->option('force');
        $counts = array_fill_keys(array_column(SyncChange::cases(), 'value'), 0);
        $records = $unchanged = $suppressed = 0;
        $problems = [];

        try {
            $query->chunkById(500, function (Collection $chunk) use ($sync, $key, $dryRun, &$counts, &$records, &$unchanged, &$suppressed, &$problems): void {
                foreach ($chunk as $record) {
                    $result = DatabaseErrors::scrubbed(
                        'record '.Str::limit((string) $record->getKey(), 40).'\'s sync',
                        fn (): SyncResult => $this->syncRecord($sync, $key, $record, $dryRun),
                    );
                    $records++;

                    foreach ($result->changes as $change) {
                        $counts[$change->value]++;
                    }

                    if ($result->problem !== null) {
                        $problems[] = "Record {$record->getKey()}: {$result->problem}";
                    } elseif ($result->changes === []) {
                        $unchanged++;
                    }

                    $suppressed += $result->suppressed ? 1 : 0;
                }
            });
        } catch (CampaignsException $exception) {
            // A refusal is the operator's to read; a database error goes to
            // the error tracker too, since a scheduled run's output is lost.
            if ($exception instanceof DatabaseFailedException) {
                report($exception);
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $forgotten = $id === null ? DatabaseErrors::scrubbed('forgetting a subscriber', fn (): int => $this->forgetOrphans($query->getModel(), $forget, $dryRun)) : 0;

        if ($dryRun) {
            $this->warn('Dry run: nothing was written. Run it again with --force to make these changes.');
        }

        $this->table(['Records', 'Count'], [
            ['Checked', $records],
            ...array_map(fn (SyncChange $change): array => [$change->label(), $counts[$change->value]], SyncChange::cases()),
            ['Unchanged', $unchanged],
            ['Forgotten: their record is gone', $forgotten],
            ['Not synced (see below)', count($problems)],
            ['Opted out or blocked, so never mailed', $suppressed],
        ]);

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return self::SUCCESS;
    }

    /**
     * Forgets the subscribers whose record is gone without a model event (a
     * query-builder delete, a database cascade, a mass prune), which the
     * observer never heard of: left alone they would still be mailed. A
     * soft-deleted record counts as gone, as its delete event treats it.
     * The table is read without the model's global scopes, so a scope that
     * hides records (a tenant, say) never makes their people look orphaned.
     */
    protected function forgetOrphans(Model $model, ForgetSubscriberAction $forget, bool $dryRun): int
    {
        $connection = (new Subscriber)->getConnection();

        // The records' subquery runs on the subscribers' connection: a model
        // on another one would be looked for in the wrong database, and
        // every subscriber would look orphaned.
        if ($model->getConnection()->getName() !== $connection->getName()) {
            return 0;
        }

        // Forgetting can't be undone: a table with no rows at all (a
        // truncate, a restore under way) would make everyone look orphaned.
        if (! $model->newQueryWithoutScopes()->exists()) {
            $this->warn("The {$model->getTable()} table holds no records, so no subscriber was forgotten.");

            return 0;
        }

        $records = $model->newQueryWithoutScopes()->select($model->getQualifiedKeyName());

        // A model using SoftDeletes.
        if (method_exists($model, 'getQualifiedDeletedAtColumn')) {
            $records->whereNull($model->getQualifiedDeletedAtColumn());
        }

        $forgotten = 0;

        Subscriber::query()
            ->where('subscribable_type', $model->getMorphClass())
            ->whereNotIn('subscribable_id', $records)
            ->chunkById(500, function (Collection $orphans) use ($forget, $dryRun, &$forgotten): void {
                foreach ($orphans as $orphan) {
                    $this->inTransaction(function () use ($forget, $orphan): void {
                        $forget->execute($orphan);
                    }, $dryRun);

                    $forgotten++;
                }
            });

        return $forgotten;
    }

    /**
     * One record per transaction, so a dry run rolls back what the real run
     * would write and holds its locks only briefly. Its counts match the
     * real run's, except where one record's change would make room for
     * another's, since the first change is rolled back: two records sharing
     * an address both count as new, and a record moving to an address
     * another record is leaving counts as not synced.
     */
    protected function syncRecord(SyncRecordAction $sync, string $key, Model $record, bool $dryRun): SyncResult
    {
        return $this->inTransaction(fn (): SyncResult => $sync->execute($key, $record), $dryRun);
    }

    /**
     * Runs one record's change in a transaction of its own, rolled back on a
     * dry run.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    protected function inTransaction(Closure $change, bool $dryRun): mixed
    {
        $connection = (new Subscriber)->getConnection();
        $connection->beginTransaction();

        try {
            $result = $change();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $dryRun ? $connection->rollBack() : $connection->commit();

        return $result;
    }
}
