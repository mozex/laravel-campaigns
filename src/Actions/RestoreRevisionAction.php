<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;

/**
 * Brings back an earlier version by saving a copy of it as the newest, so
 * the history keeps every version in the order it was current. The copy
 * meets today's rules, like any new revision.
 */
class RestoreRevisionAction
{
    public function __construct(protected AddRevisionAction $addRevision) {}

    /**
     * @throws CampaignsException
     */
    public function execute(Revision $revision, ?string $author = null): Revision
    {
        $owner = $revision->revisable;

        if (! $owner instanceof Template && ! $owner instanceof Campaign) {
            throw CampaignsException::invalidValue('revision', 'belongs to nothing that can be edited');
        }

        return $this->addRevision->execute($owner, $revision->contentsToCopy(), "Restored from revision {$revision->number}", $author);
    }
}
