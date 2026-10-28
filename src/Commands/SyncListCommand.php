<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Actions\SyncRecordAction;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\SyncChange;
use Mozex\Campaigns\Lists\SyncResult;
use Mozex\Campaigns\Models\Subscriber;
use Throwable;

class SyncListCommand extends Command
{
    protected $signature = 'campaigns:sync-list
        {list : The key of the dynamic list, as in campaigns.list_sources}
        {--id= : Sync only the record with this key}
        {--force : Write the changes; without it nothing is written}';

    protected $description = 'Bring a dynamic list in step with the app records behind it';

    public function handle(Campaigns $campaigns, SyncRecordAction $sync): int
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

            if (! $query->exists()) {
                $this->error("No record has the key {$id}.");

                return self::FAILURE;
            }
        }

        $dryRun = ! $this->option('force');
        $counts = array_fill_keys(array_map(fn (SyncChange $change): string => $change->value, SyncChange::cases()), 0);
        $records = $unchanged = $suppressed = 0;
        $problems = [];

        try {
            $query->chunkById(500, function (Collection $chunk) use ($sync, $key, $dryRun, &$counts, &$records, &$unchanged, &$suppressed, &$problems): void {
                foreach ($chunk as $record) {
                    $result = $this->syncRecord($sync, $key, $record, $dryRun);
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
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing was written. Run it again with --force to make these changes.');
        }

        $this->table(['Records', 'Count'], [
            ['Checked', $records],
            ...array_map(fn (SyncChange $change): array => [$change->label(), $counts[$change->value]], SyncChange::cases()),
            ['Unchanged', $unchanged],
            ['Not synced (see below)', count($problems)],
            ['Opted out or blocked, so never mailed', $suppressed],
        ]);

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return self::SUCCESS;
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
        $connection = (new Subscriber)->getConnection();
        $connection->beginTransaction();

        try {
            $result = $sync->execute($key, $record);
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $dryRun ? $connection->rollBack() : $connection->commit();

        return $result;
    }
}
