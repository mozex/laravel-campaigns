<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\TextValue;

/**
 * Saves a new version of a template's or a campaign's email, numbered after
 * the last; nothing is ever overwritten. A campaign's email changes only
 * while it is a draft, since starting or scheduling it freezes the revision
 * that goes out.
 */
class AddRevisionAction
{
    public function __construct(protected ContentRules $rules) {}

    /**
     * @param  list<EmailContent>  $contents  one per locale
     *
     * @throws CampaignsException listing every reason the email can't be saved
     */
    public function execute(Template|Campaign $owner, array $contents, ?string $note = null, ?string $author = null): Revision
    {
        $note = TextValue::optional('note', $note, 255);
        $author = TextValue::optional('author', $author, 255);
        $contents = $this->rules->prepare($contents);

        return $owner->getConnection()->transaction(function () use ($owner, $contents, $note, $author): Revision {
            // The owner's row is locked, so two writers number their
            // revisions one after the other, and a campaign can't be started
            // between the check below and the new revision.
            $locked = $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw CampaignsException::invalidValue('template or campaign', 'was deleted');
            }

            if ($locked instanceof Campaign && $locked->status !== CampaignStatus::Draft) {
                throw CampaignsException::campaignNotDraft($locked->status->value);
            }

            // A locking read, which sees the latest committed revision even
            // inside a caller's transaction whose snapshot predates it.
            // PostgreSQL refuses FOR UPDATE with max(), hence the order.
            $number = 1 + (int) Revision::query()
                ->where('revisable_type', $owner->getMorphClass())
                ->where('revisable_id', $owner->getKey())
                ->orderByDesc('number')
                ->lockForUpdate()
                ->value('number');

            $revision = Revision::create([
                'revisable_type' => $owner->getMorphClass(),
                'revisable_id' => $owner->getKey(),
                'number' => $number,
                'note' => $note,
                'author' => $author,
            ]);

            $revision->contents()->createMany(array_map(fn (EmailContent $content): array => [
                'locale' => $content->locale,
                'subject' => $content->subject,
                'preheader' => $content->preheader,
                'html' => $content->html,
                'text' => (string) $content->text,
                'text_generated' => $content->textGenerated,
            ], $contents));

            $owner->touch();

            return $revision->load('contents');
        });
    }
}
