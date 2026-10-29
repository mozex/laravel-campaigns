<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\UtmTags;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\MailboxProvider;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Events\CampaignStartedEvent;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\Engagement;
use Throwable;

/**
 * Freezes a campaign a start claimed (status starting): its email, with UTM
 * tags added to links to the app's own sites, and its audience, one message
 * per recipient with the target lists it qualified through, its address and
 * locale at that moment, and its place in the send order. All of it is one
 * transaction with the move to sending or scheduled, so a recipient count
 * that differs from the one confirmed leaves nothing behind, and the
 * campaign goes back to being a draft with start_error saying why.
 */
class FreezeCampaignAction
{
    /**
     * The positions a tier spans: the engaged go first, then the active,
     * then the silent. Within a tier, recipients take turns by mailbox
     * provider, so no provider sees a burst.
     */
    public const TIER_STEP = 1_000_000_000_000;

    protected const CHUNK = 500;

    public function __construct(protected ContentRules $rules) {}

    /**
     * @param  string  $run  the start this freeze belongs to (campaigns.start_run)
     *
     * @throws Throwable for an error that isn't a refusal, after returning the campaign to a draft
     */
    public function execute(Campaign $campaign, int $expectedRecipients, string $run): Campaign
    {
        try {
            $frozen = $campaign->getConnection()->transaction(fn (): ?Campaign => $this->freeze($campaign, $expectedRecipients, $run));
        } catch (CampaignsException|ValidationException $exception) {
            static::refuse($campaign, static::reason($exception), $run);

            return $campaign->refresh();
        } catch (QueryException $exception) {
            // Its message lists the inserts' values, recipients' addresses
            // among them, so only its SQLSTATE goes on, never the original.
            $sanitized = CampaignsException::freezeFailed($exception->getCode());
            static::refuse($campaign, $sanitized->getMessage(), $run);

            throw $sanitized;
        } catch (Throwable $exception) {
            static::refuse($campaign, 'It couldn\'t be started: an error happened on the server, and it was reported. Start it again, or ask the app\'s developer.', $run);

            throw $exception;
        }

        if ($frozen === null) {
            return $campaign->refresh();
        }

        if ($frozen->status === CampaignStatus::Sending) {
            CampaignStartedEvent::dispatch($frozen);
        }

        return $frozen;
    }

    /**
     * The campaign's email with its UTM tags on the links to the app's own
     * sites, or null when there is nothing to tag.
     *
     * @return ?list<EmailContent>
     */
    public static function taggedContents(Campaign $campaign, Revision $revision): ?array
    {
        $tags = new UtmTags($campaign->utmTags(), UtmTags::configuredHosts());
        $changed = false;
        $contents = [];

        foreach ($revision->contentsToCopy() as $content) {
            $html = $tags->html($content->html);
            $text = $content->text === null ? null : $tags->text($content->text);
            $changed = $changed || $html !== $content->html || $text !== $content->text;

            $contents[] = new EmailContent(
                locale: $content->locale,
                subject: $content->subject,
                html: $html,
                text: $text,
                preheader: $content->preheader,
            );
        }

        return $changed ? $contents : null;
    }

    /**
     * Moves a campaign still being started by this run back to a draft,
     * saying why. Returns whether it did.
     */
    public static function refuse(Campaign $campaign, string $reason, string $run): bool
    {
        return Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', CampaignStatus::Starting)
            ->where('start_run', $run)
            ->update([
                'status' => CampaignStatus::Draft,
                'start_error' => $reason,
                'scheduled_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    protected function freeze(Campaign $campaign, int $expected, string $run): ?Campaign
    {
        $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first();

        // Cancelled while it waited, already frozen, or started again since.
        if ($locked === null || $locked->status !== CampaignStatus::Starting || $locked->start_run !== $run) {
            return null;
        }

        $revision = $this->revision($locked);
        $count = $this->messages($locked);

        if ($count !== $expected) {
            throw CampaignsException::recipientCountChanged($expected, $count);
        }

        $scheduled = $locked->scheduled_at !== null && $locked->scheduled_at->isFuture();

        $locked->forceFill([
            'status' => $scheduled ? CampaignStatus::Scheduled : CampaignStatus::Sending,
            'frozen_revision_id' => $revision->id,
            'recipient_count' => $count,
            'start_error' => null,
            'started_at' => $scheduled ? null : now(),
            'pace_started_at' => $scheduled ? null : now(),
        ])->save();

        return $locked;
    }

    /**
     * The revision that goes out: the current one, or a copy of it with UTM
     * tags, which carries its check results, since only the tags differ.
     */
    protected function revision(Campaign $campaign): Revision
    {
        $current = $campaign->currentRevision()->with('contents')->first() ?? throw CampaignsException::noRevision();
        $tagged = static::taggedContents($campaign, $current);

        if ($tagged === null) {
            return $current;
        }

        $contents = $this->rules->prepare($tagged);

        $revision = Revision::create([
            'revisable_type' => $campaign->getMorphClass(),
            'revisable_id' => $campaign->id,
            'number' => $current->number + 1,
            'note' => 'The email as it was sent, with UTM tags added to the links',
            'author' => $current->author,
            'check_results' => $current->check_results,
            'checked_at' => $current->checked_at,
            'frozen_from_id' => $current->id,
        ]);

        $revision->contents()->createMany(array_map(fn (EmailContent $content): array => [
            'locale' => $content->locale,
            'subject' => $content->subject,
            'preheader' => $content->preheader,
            'html' => $content->html,
            'text' => (string) $content->text,
            'text_generated' => $content->textGenerated,
        ], $contents));

        return $revision;
    }

    /**
     * Writes one pending message per recipient and returns how many.
     */
    protected function messages(Campaign $campaign): int
    {
        $tiers = EngagementTier::cases();
        $providers = count(MailboxProvider::cases());
        $ranks = [];
        $rows = [];
        $now = now();

        $count = CampaignAudience::for($campaign)->each(function (Subscriber $subscriber, array $lists) use ($campaign, $tiers, $providers, &$ranks, &$rows, $now): void {
            $tier = Engagement::tier($subscriber);
            $provider = MailboxProvider::fromEmail($subscriber->email);
            $rank = $ranks[$tier->value][$provider->value] ?? 0;
            $ranks[$tier->value][$provider->value] = $rank + 1;

            $rows[] = [
                'uuid' => (string) Str::orderedUuid(),
                'campaign_id' => $campaign->id,
                'subscriber_id' => $subscriber->id,
                'list_ids' => json_encode($lists, JSON_THROW_ON_ERROR),
                'email' => $subscriber->email,
                'locale' => $subscriber->locale,
                'position' => (int) array_search($tier, $tiers, true) * static::TIER_STEP + $rank * $providers + $provider->index(),
                'status' => MessageStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= static::CHUNK) {
                CampaignMessage::query()->insert($rows);
                $rows = [];
            }
        }, static::CHUNK);

        if ($rows !== []) {
            CampaignMessage::query()->insert($rows);
        }

        return $count;
    }

    protected static function reason(CampaignsException|ValidationException $exception): string
    {
        if ($exception instanceof CampaignsException) {
            return $exception->getMessage();
        }

        return 'The named audience refused its parameters: '.implode(' ', Arr::flatten($exception->errors()));
    }
}
