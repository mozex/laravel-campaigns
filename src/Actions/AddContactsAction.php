<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Imports\ImportResult;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Adds a few contacts to a static list at once, as an agent or a screen
 * does by hand. It goes through ImportContactsAction, so a contact is
 * treated exactly as an imported one: an opt-out or a block stays, a
 * subscription that exists is left as it is, an existing subscriber gains
 * only the details it lacks, and an address kept only so it is never mailed
 * (a person who left) joins nothing. On a double opt-in list the contacts
 * wait to confirm, and nothing mails them until SendConfirmationAction runs.
 */
class AddContactsAction
{
    public const MAX_CONTACTS = 100;

    /**
     * The fields a contact may carry: who they are, not what another system
     * knew of them (engagement, opt-outs and blocks come only through an
     * import).
     */
    public const FIELDS = ['email', 'name', 'first_name', 'locale', 'timezone', 'country', 'custom_fields'];

    public function __construct(protected ImportContactsAction $import) {}

    /**
     * @param  list<array<array-key, mixed>>  $contacts  keyed by FIELDS
     * @param  ConsentBasis  $basis  Why the app may email them: whoever adds them says.
     *
     * @throws CampaignsException for an archived or dynamic list, too many contacts, or a field a contact can't carry
     */
    public function execute(CampaignList $list, array $contacts, ConsentBasis $basis, ConsentContext $context): ImportResult
    {
        if ($list->archived_at !== null) {
            throw CampaignsException::listArchived($list->key);
        }

        if ($contacts === []) {
            throw CampaignsException::invalidValue('contacts', 'must hold at least one contact');
        }

        if (count($contacts) > static::MAX_CONTACTS) {
            throw CampaignsException::tooManyContacts(static::MAX_CONTACTS);
        }

        foreach ($contacts as $number => $contact) {
            $unknown = array_diff(array_map(strval(...), array_keys($contact)), static::FIELDS);

            if ($unknown !== []) {
                $named = array_map(fn (string $field): string => mb_strimwidth($field, 0, 40, '...'), array_slice(array_values($unknown), 0, 5));

                throw CampaignsException::invalidValue('contact '.($number + 1), 'can only carry '.implode(', ', static::FIELDS).', not '.implode(', ', $named));
            }
        }

        return $this->import->execute($contacts, $list, new ConsentContext(
            source: $context->source,
            basis: $basis,
            noticeVersion: $context->noticeVersion,
            meta: $context->meta,
        ), dryRun: false);
    }
}
