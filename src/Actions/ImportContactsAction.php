<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use JsonException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ImportChange;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Imports\ImportedContact;
use Mozex\Campaigns\Imports\ImportResult;
use Mozex\Campaigns\Imports\RolledBackRow;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Engagement;
use Mozex\Campaigns\Support\Rows;
use Mozex\Campaigns\Support\SubscriberDetails;

/**
 * Brings contacts in from another system: onto a static list with their
 * details and engagement, and with the opt-outs and blocks that system had.
 * Without a list it only records opt-outs and blocks, and adds engagement to
 * subscribers that exist, such as the app's own accounts.
 *
 * An import never clears an opt-out or a block and never changes a list
 * subscription that exists: it never speaks for the person, so it joins
 * through SubscribeAction with no choice of theirs, whatever the context
 * says. It doesn't overwrite what the app knows: a subscriber linked to an
 * app record keeps the details its list source gives it, and any other
 * subscriber, when the import has a list, gains only the details it lacks;
 * engagement dates only move forward. An address kept only so it is never mailed (a person who left, or
 * a block or opt-out brought in with no list) gains nothing but the import's
 * own block or opt-out, so a person who left gets no details back. On a
 * double opt-in list the contacts wait to confirm, and nothing mails them
 * until SendConfirmationAction runs.
 *
 * Each row is its own transaction, so a long import holds its locks only
 * briefly; a dry run makes every change and rolls it back.
 */
class ImportContactsAction
{
    /**
     * How far ahead of the server's clock an engagement date may be, for a
     * source whose clock runs a little fast; it is stored as now.
     */
    protected const CLOCK_SKEW_MINUTES = 5;

    /**
     * What a seen address's first row carried, and whether the address ends
     * opted out or blocked.
     */
    protected const OPTED_OUT = 1;

    protected const BLOCKED = 2;

    protected const SUPPRESSED = 4;

    public function __construct(
        protected SubscribeAction $subscribe,
        protected BlockAction $block,
        protected OptOutAction $optOut,
    ) {}

    /**
     * @param  iterable<ImportedContact|array<array-key, mixed>>  $contacts  Read one at a time, so a generator or a lazy collection streams a large source.
     * @param  ?CampaignList  $list  The static list the contacts join; null to record only opt-outs, blocks and engagement.
     * @param  ConsentContext  $context  The import's source (its name), notice and meta (which wins over a contact's own); its basis defaults to imported, and its accepted is ignored.
     * @param  bool  $dryRun  Roll every change back and only count it.
     * @param  ?string  $only  Import only the row with this address, to try an import on one contact first.
     *
     * @throws CampaignsException for a dynamic list or an $only that isn't an address
     */
    public function execute(iterable $contacts, ?CampaignList $list, ConsentContext $context, bool $dryRun, ?string $only = null): ImportResult
    {
        // A dynamic list follows the app's records; nothing else joins it.
        if ($list?->kind === ListKind::Dynamic) {
            throw CampaignsException::dynamicList($list->key);
        }

        $onlyAddress = $only === null ? null : EmailAddress::normalize($only) ?? throw CampaignsException::invalidEmail();

        $context = new ConsentContext(
            source: $context->source,
            basis: $context->basis ?? ConsentBasis::Imported,
            noticeVersion: $context->noticeVersion,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            meta: $context->meta,
        );

        $counts = array_fill_keys(array_column(ImportChange::cases(), 'value'), 0);
        $problems = [];
        $row = $rows = 0;

        // Each address seen, as its first row's number shifted past three
        // flags: one int instead of an array keeps a long import's memory
        // small.
        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($contacts as $item) {
            $row++;
            $address = EmailAddress::normalize($this->rawEmail($item));

            if ($onlyAddress !== null && $address !== $onlyAddress) {
                continue;
            }

            $rows++;

            if ($address === null) {
                $problems[] = "Row {$row}: it has no email address that can be mailed.";

                continue;
            }

            $first = $seen[$address] ?? null;
            $joins = $first === null ? $list : null;

            try {
                $contact = $item instanceof ImportedContact ? $item : ImportedContact::fromArray($item);
                $this->checkStorable($contact);

                // A later row's dates are dropped anyway, so a bad one never
                // costs it its opt-out or block.
                $dates = $first === null ? $this->engagement($contact) : array_fill_keys(Engagement::SIGNALS, null);
            } catch (CampaignsException $exception) {
                // A bad value never costs the row its opt-out or block: those
                // are recorded alone, with no details, dates or list.
                $suppression = ImportedContact::suppressionOf($item);

                if ($suppression === null) {
                    $problems[] = "Row {$row}: {$exception->getMessage()}";

                    continue;
                }

                $problems[] = "Row {$row}: {$exception->getMessage()} Its opt-out or block was still recorded.";
                $contact = $suppression;
                $dates = array_fill_keys(Engagement::SIGNALS, null);
                $joins = null;
            }

            // An address counts once, so a dry run's numbers match the real
            // run's; a later row only adds an opt-out or a block the first
            // one lacked, so neither is lost.
            if ($first !== null) {
                $contact = new ImportedContact(
                    $contact->email,
                    optOut: $contact->optOut && ($first & static::OPTED_OUT) === 0,
                    block: ($first & static::BLOCKED) === 0 ? $contact->block : null,
                    meta: $contact->meta,
                );

                if (! $contact->suppresses()) {
                    $problems[] = 'Row '.$row.': the same address as row '.($first >> 3).', which counts instead.';

                    continue;
                }
            }

            try {
                [$changes, $suppressed] = $this->inTransaction(
                    fn (): array => $this->import($contact, $dates, $address, $joins, $context),
                    $dryRun,
                );
            } catch (CampaignsException $exception) {
                $problems[] = "Row {$row}: {$exception->getMessage()}";

                continue;
            } catch (QueryException $exception) {
                // Its message holds the SQL with the row's address, so only
                // its code is kept; whatever broke would break the next row.
                $problems[] = "Row {$row}: the database refused it (SQLSTATE {$exception->getCode()}), so the import stopped there.";

                break;
            }

            if ($first !== null) {
                $changes = array_values(array_filter($changes, fn (ImportChange $change): bool => in_array($change, [ImportChange::Blocked, ImportChange::OptedOut], true)));
                $problems[] = 'Row '.$row.': the same address as row '.($first >> 3).', which counts instead'
                    .($changes === [] ? '.' : '; this row\'s opt-out or block was added to it.');
            }

            foreach ($changes as $change) {
                $counts[$change->value]++;
            }

            // A row that found no subscriber and made none leaves the address
            // to the next row that has it.
            if ($changes === [ImportChange::NotFound]) {
                continue;
            }

            $flags = (($first ?? 0) & (static::OPTED_OUT | static::BLOCKED))
                | ($contact->optOut ? static::OPTED_OUT : 0)
                | ($contact->block !== null ? static::BLOCKED : 0)
                | ($suppressed ? static::SUPPRESSED : 0);

            $seen[$address] = (($first === null ? $row : $first >> 3) << 3) | $flags;
        }

        if ($onlyAddress !== null && $rows === 0) {
            $problems[] = 'No row has that address.';
        }

        $neverMailed = count(array_filter($seen, fn (int $entry): bool => ($entry & static::SUPPRESSED) !== 0));

        return new ImportResult($dryRun, $rows, $counts, $neverMailed, $problems);
    }

    /**
     * @param  array<string, ?CarbonInterface>  $dates
     * @return array{list<ImportChange>, bool}
     */
    protected function import(ImportedContact $contact, array $dates, string $address, ?CampaignList $list, ConsentContext $context): array
    {
        // The import's own meta (who ran it, from which file) wins over a
        // contact's, so a row can't rewrite who made the change.
        $context = new ConsentContext(
            source: $context->source,
            basis: $context->basis,
            noticeVersion: $context->noticeVersion,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            meta: [...$contact->meta, ...$context->meta],
        );

        // One locking read by address, before any plain one: it waits for a
        // deletion or an address change in progress and sees the row as it
        // is after it, so the import never writes to a person who just left
        // or to whoever took the row's place. On MySQL it also locks the gap
        // of an address no row holds; a deadlock that causes runs the row
        // again.
        $subscriber = Subscriber::query()->where('email', $address)->lockForUpdate()->first();

        if ($subscriber === null) {
            if ($list === null && ! $contact->suppresses()) {
                return [[ImportChange::NotFound], false];
            }

            // An opt-out or block brought in with no list keeps only the
            // address it is about.
            $subscriber = Rows::firstOrCreate(Subscriber::query(), ['email' => $address], $list === null ? [] : $this->newDetails($contact, $dates));
        }

        $changes = [];
        $keptOnly = ! $subscriber->wasRecentlyCreated && $this->isKeptOnlySuppressed($subscriber);

        if ($subscriber->wasRecentlyCreated) {
            $changes[] = ImportChange::Created;
        } elseif ($keptOnly) {
            $changes[] = ImportChange::KeptSuppressed;
        } elseif ($this->addTo($subscriber, $contact, $dates, withDetails: $list !== null)) {
            $changes[] = ImportChange::Updated;
        }

        if ($list !== null && ! $keptOnly) {
            $joined = $this->subscribe->execute($subscriber, $list, $context);
            $changes[] = $joined->wasRecentlyCreated ? ImportChange::Joined : ImportChange::AlreadyOnList;
        }

        if ($contact->block !== null && $this->block->execute($subscriber, $contact->block, $context)) {
            $changes[] = ImportChange::Blocked;
        }

        if ($contact->optOut && $this->optOut->execute($subscriber, $context)) {
            $changes[] = ImportChange::OptedOut;
        }

        return [$changes, $subscriber->isSuppressed()];
    }

    /**
     * Runs one row in a transaction of its own, rolled back on a dry run.
     * Laravel's transaction() runs it again when the database ended it for
     * a deadlock, which an import racing the app's own writes can meet, and
     * inside a caller's transaction, which a deadlock has already ended,
     * hands the deadlock to the caller with the transaction count right.
     *
     * @param  Closure(): array{list<ImportChange>, bool}  $import
     * @return array{list<ImportChange>, bool}
     */
    protected function inTransaction(Closure $import, bool $dryRun): array
    {
        try {
            return (new Subscriber)->getConnection()->transaction(function () use ($import, $dryRun): array {
                $result = $import();

                return $dryRun ? throw new RolledBackRow($result) : $result;
            }, attempts: 3);
        } catch (RolledBackRow $rolledBack) {
            return $rolledBack->result;
        }
    }

    /**
     * A value that isn't UTF-8 (a file in another encoding), holds a NUL
     * character or can't be written as JSON makes the database, or the
     * encoding before it, fail with the row's address in the error, so it is
     * the row's problem instead.
     *
     * @throws CampaignsException
     */
    protected function checkStorable(ImportedContact $contact): void
    {
        if ($contact->customFields !== []) {
            SetCustomFieldsAction::check($contact->customFields);
        }

        // Read before details() cleans them: cutting a value that isn't
        // UTF-8 turns its bad bytes into "?", which would store "Jos?".
        foreach (['name' => $contact->name, 'first_name' => $contact->firstName, 'locale' => $contact->locale, 'timezone' => $contact->timezone, 'country' => $contact->country] as $field => $value) {
            if ($value !== null && ! mb_check_encoding($value, 'UTF-8')) {
                throw CampaignsException::invalidImportValue($field, "it isn't UTF-8 text");
            }
        }

        $values = [...$this->details($contact), 'custom_fields' => $contact->customFields, 'meta' => $contact->meta];

        foreach ($values as $field => $value) {
            try {
                $json = json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw CampaignsException::invalidImportValue($field, "it can't be stored ({$exception->getMessage()})");
            }

            if (str_contains($json, '\u0000')) {
                throw CampaignsException::invalidImportValue($field, 'it holds a NUL character');
            }
        }
    }

    /**
     * Adds what an existing subscriber lacks: later engagement dates, and,
     * when the import has a list and no app record stands behind the
     * subscriber, empty details and missing custom fields. The app's record
     * owns a linked subscriber's details, and its next sync would put them
     * back.
     *
     * @param  array<string, ?CarbonInterface>  $dates
     */
    protected function addTo(Subscriber $subscriber, ImportedContact $contact, array $dates, bool $withDetails): bool
    {
        $changed = false;

        foreach ($dates as $column => $date) {
            if ($date === null) {
                continue;
            }

            $changed = Subscriber::query()
                ->whereKey($subscriber->id)
                ->where(fn (Builder $query): Builder => $query->whereNull($column)->orWhere($column, '<', $date))
                ->update([$column => $date, 'updated_at' => now()]) > 0 || $changed;
        }

        if (! $withDetails || $subscriber->subscribable_type !== null) {
            return $changed;
        }

        foreach ($this->details($contact) as $field => $value) {
            if ($subscriber->getAttribute($field) === null) {
                $subscriber->setAttribute($field, $value);
            }
        }

        // The subscriber's own values win and keep their order, so nothing
        // new leaves the column unchanged.
        $subscriber->custom_fields = ($subscriber->custom_fields ?? []) + $contact->customFields ?: null;

        if (! $subscriber->isDirty()) {
            return $changed;
        }

        $subscriber->save();

        return true;
    }

    /**
     * The address is kept only so it is never mailed: blocked or opted out,
     * linked to no app record and on no list. A person who left is such a
     * row (ForgetSubscriberAction), and so is a block or opt-out brought in
     * with no list.
     */
    protected function isKeptOnlySuppressed(Subscriber $subscriber): bool
    {
        return $subscriber->subscribable_type === null
            && $subscriber->isSuppressed()
            && ! $subscriber->subscriptions()->exists();
    }

    /**
     * @param  array<string, ?CarbonInterface>  $dates
     * @return array<string, mixed>
     */
    protected function newDetails(ImportedContact $contact, array $dates): array
    {
        return [
            ...$this->details($contact),
            ...$dates,
            'custom_fields' => $contact->customFields === [] ? null : $contact->customFields,
        ];
    }

    /**
     * The contact's details, cleaned for storing.
     *
     * @return array<string, ?string>
     */
    protected function details(ImportedContact $contact): array
    {
        return SubscriberDetails::clean([
            'name' => $contact->name,
            'first_name' => $contact->firstName,
            'locale' => $contact->locale,
            'timezone' => $contact->timezone,
            'country' => $contact->country,
        ]);
    }

    /**
     * The engagement dates, checked and in the app's timezone, since stored
     * timestamps carry no zone and a date given with an offset would be
     * stored as the wrong moment. A date before 1970 (an export's
     * 0000-00-00 among them) can't be stored, and one in the future would
     * keep the person engaged for good; one a few minutes ahead is a fast
     * clock, stored as now.
     *
     * @return array<string, ?CarbonInterface>
     *
     * @throws CampaignsException for a date before 1970 or in the future
     */
    protected function engagement(ImportedContact $contact): array
    {
        $timezone = (string) config('app.timezone');
        $earliest = CarbonImmutable::create(1970, 1, 2, 0, 0, 0, 'UTC');
        $latest = now()->addMinutes(static::CLOCK_SKEW_MINUTES);

        $dates = [
            'last_opened_at' => $contact->lastOpenedAt,
            'last_clicked_at' => $contact->lastClickedAt,
            'last_active_at' => $contact->lastActiveAt,
        ];

        foreach ($dates as $field => $date) {
            if ($date === null) {
                continue;
            }

            if ($date->lt($earliest)) {
                throw CampaignsException::invalidImportValue($field, 'it is before 2 January 1970, too early to store');
            }

            if ($date->gt($latest)) {
                throw CampaignsException::invalidImportValue($field, 'it is in the future');
            }

            $dates[$field] = $date->min(now())->toImmutable()->setTimezone($timezone);
        }

        return $dates;
    }

    /**
     * The row's address as given, read before the row is, so $only and the
     * duplicate check work on a row that can't be read.
     */
    protected function rawEmail(mixed $item): ?string
    {
        if ($item instanceof ImportedContact) {
            return $item->email;
        }

        $email = is_array($item) ? $item['email'] ?? null : null;

        return is_scalar($email) ? (string) $email : null;
    }
}
