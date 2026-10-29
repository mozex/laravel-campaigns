<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\EmailAddress;

/**
 * Takes a few addresses off a static list at once, through
 * RemoveFromListAction, one at a time. Nobody is opted out: they stay on
 * their other lists, and adding them again later starts afresh.
 */
class RemoveContactsAction
{
    public const MAX_CONTACTS = 100;

    public function __construct(protected RemoveFromListAction $remove) {}

    /**
     * @param  list<string>  $emails
     * @return array{removed: int, not_on_list: int, not_found: int, invalid: int}
     *
     * @throws CampaignsException for a dynamic list, or too many addresses
     */
    public function execute(CampaignList $list, array $emails, ConsentContext $context): array
    {
        if ($list->kind === ListKind::Dynamic) {
            throw CampaignsException::dynamicList($list->key);
        }

        if ($emails === []) {
            throw CampaignsException::invalidValue('emails', 'must hold at least one address');
        }

        if (count($emails) > static::MAX_CONTACTS) {
            throw CampaignsException::tooManyAddresses(static::MAX_CONTACTS);
        }

        $counts = ['removed' => 0, 'not_on_list' => 0, 'not_found' => 0, 'invalid' => 0];
        $seen = [];

        foreach ($emails as $email) {
            $address = EmailAddress::normalize($email);

            if ($address === null) {
                $counts['invalid']++;

                continue;
            }

            if (isset($seen[$address])) {
                continue;
            }

            $seen[$address] = true;
            $subscriber = Subscriber::query()->where('email', $address)->first();

            if ($subscriber === null) {
                $counts['not_found']++;
            } elseif ($this->remove->execute($subscriber, $list, $context)) {
                $counts['removed']++;
            } else {
                $counts['not_on_list']++;
            }
        }

        return $counts;
    }
}
