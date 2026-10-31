<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Generator;
use Illuminate\Console\Command;
use Mozex\Campaigns\Actions\ImportContactsAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ImportChange;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Imports\ImportedContact;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Suppression;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Sender;

/**
 * Blocks here the addresses the provider holds on a sending domain's bounce
 * and complaint lists: the app's other domains (its account emails), whose
 * lists the marketing domain never learns, and the marketing domain itself,
 * the only record of the double opt-in email's bounces and spam reports. A
 * bounce blocks as hard_bounce and a spam report as complaint; neither opts
 * the person out, as a suppression found while sending doesn't. The
 * provider's unsubscribe list is read only for the marketing domain, where it
 * means marketing: an address there is opted out. Through ImportContactsAction
 * with no list, so an address no subscriber has is kept bare, only so it is
 * never mailed, and a dry run is the real run rolled back. The lists are the
 * active driver's (ReadsSuppressions); a driver without them is refused.
 */
class ImportProviderSuppressionsCommand extends Command
{
    /**
     * The consent records' source, which finds these blocks again (to undo
     * them, unblock the addresses whose block carries it).
     */
    public const SOURCE = 'provider_suppressions_import';

    protected $signature = 'campaigns:import-provider-suppressions
        {domain : A sending domain of the app; the marketing domain as campaigns.mail.domain names it}
        {--email= : Import only this address, to try it on one first}
        {--force : Write the changes; without it nothing is written}';

    protected $description = 'Block the addresses the provider holds on a sending domain\'s bounce and complaint lists';

    public function handle(DriverManager $drivers, ImportContactsAction $import): int
    {
        $domain = strtolower(trim((string) $this->argument('domain')));

        if ($domain === '') {
            $this->error('Name the sending domain whose lists to read.');

            return self::FAILURE;
        }

        try {
            $driver = $drivers->active();
        } catch (CampaignsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $driver instanceof ReadsSuppressions) {
            $this->error(CampaignsException::driverLacks($drivers->getDefaultDriver(), 'reads no suppression lists, so there is nothing to import')->getMessage());

            return self::FAILURE;
        }

        try {
            $marketing = $domain === Sender::domain();
        } catch (CampaignsException) {
            $marketing = false;
        }

        /** @var ?string $only */
        $only = $this->option('email');
        $dryRun = ! $this->option('force');
        $address = $only === null ? null : EmailAddress::normalize($only);

        // Refused before the provider is asked, so it never travels in a
        // request.
        if ($only !== null && $address === null) {
            $this->error(CampaignsException::invalidEmail()->getMessage());

            return self::FAILURE;
        }

        try {
            // One address is looked up on its own, rather than read out of the
            // whole lists.
            $suppressions = $address === null
                ? $driver->suppressions($domain)
                : $driver->suppressionsFor($domain, $address);

            $result = $import->execute(
                $this->contacts($suppressions, $marketing),
                null,
                new ConsentContext(source: static::SOURCE, meta: ['domain' => $domain]),
                $dryRun,
                $only,
            );
        } catch (CampaignsException $exception) {
            $this->error($exception->getMessage());

            if (! $dryRun) {
                $this->warn('The addresses imported before it stopped stay imported; running it again picks up the rest.');
            }

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing was written. Run it again with --force to make these changes.');
        }

        $this->table(['Addresses', 'Count'], [
            ['Read', $result->rows],
            ...array_map(fn (ImportChange $change): array => [$change->label(), $result->count($change)], ImportChange::cases()),
            ['Opted out or blocked, so never mailed', $result->suppressed],
            ['Not imported (see below)', count($result->problems)],
        ]);

        foreach ($result->problems as $problem) {
            $this->line($problem);
        }

        return self::SUCCESS;
    }

    /**
     * @param  iterable<Suppression>  $suppressions
     * @return Generator<int, ImportedContact>
     */
    protected function contacts(iterable $suppressions, bool $marketing): Generator
    {
        foreach ($suppressions as $suppression) {
            $meta = array_filter([
                'provider_list' => $suppression->type->value,
                'listed_at' => $suppression->since?->toIso8601String(),
            ]);

            $contact = match ($suppression->type) {
                SuppressionType::Bounce => new ImportedContact($suppression->email, block: BlockReason::HardBounce, meta: $meta),
                SuppressionType::Complaint => new ImportedContact($suppression->email, block: BlockReason::Complaint, meta: $meta),
                SuppressionType::Unsubscribe => $marketing ? new ImportedContact($suppression->email, optOut: true, meta: $meta) : null,
            };

            if ($contact !== null) {
                yield $contact;
            }
        }
    }
}
