<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Support\Facades\DB;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Jobs\SendTestJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\TestAddresses;

/**
 * Queues test emails of a campaign's or a template's email to the app's
 * test addresses (campaigns.test_addresses) and nowhere else: one per
 * address and language. A draft campaign's test is its current revision; a
 * started one's is the revision it froze, UTM tags and all. Each test keeps
 * the revision it was made of, and its job makes the links' token, so the
 * raw token never sits in the queue.
 */
class SendTestAction
{
    /**
     * The most test emails one call sends.
     */
    public const MAX = 25;

    /**
     * @param  ?list<string>  $addresses  some of the test addresses; all of them when null or empty
     * @param  ?list<string>  $locales  the languages to send; the default language when null or empty
     * @return list<TestSend>
     *
     * @throws CampaignsException when sending is off, no test address is
     *                            configured, an address isn't one, the
     *                            email lacks a language, or there are too many
     */
    public function execute(Campaign|Template $source, ?array $addresses = null, ?array $locales = null): array
    {
        if (! config('campaigns.enabled')) {
            throw CampaignsException::testSendingDisabled();
        }

        $configured = TestAddresses::configured();

        if ($configured === []) {
            throw CampaignsException::testAddressesNotConfigured();
        }

        $addresses = $addresses === null || $addresses === [] ? $configured : $this->addresses($addresses, $configured);
        $revision = $this->revision($source);
        $contents = $this->contents($revision, $locales);

        if (count($addresses) * count($contents) > static::MAX) {
            throw CampaignsException::tooManyTestSends(static::MAX);
        }

        return DB::transaction(function () use ($source, $revision, $addresses, $contents): array {
            $tests = [];

            foreach ($addresses as $address) {
                foreach ($contents as $content) {
                    $test = TestSend::query()->create([
                        'campaign_id' => $source instanceof Campaign ? $source->id : null,
                        'revision_id' => $revision->id,
                        'email' => $address,
                        'locale' => $content->locale,
                    ]);

                    SendTestJob::dispatch($test->id)->afterCommit();
                    $tests[] = $test;
                }
            }

            return $tests;
        });
    }

    /**
     * @param  list<string>  $given
     * @param  list<string>  $configured
     * @return list<string>
     */
    protected function addresses(array $given, array $configured): array
    {
        $addresses = [];
        $refused = [];

        foreach ($given as $address) {
            $clean = EmailAddress::clean($address);

            if (! in_array($clean, $configured, true)) {
                $refused[] = $clean;
            } elseif (! in_array($clean, $addresses, true)) {
                $addresses[] = $clean;
            }
        }

        if ($refused !== []) {
            throw CampaignsException::notTestAddresses(count(array_unique($refused)));
        }

        return $addresses;
    }

    protected function revision(Campaign|Template $source): Revision
    {
        $revision = $source instanceof Campaign && $source->status !== CampaignStatus::Draft
            ? ($source->frozenRevision ?? $source->currentRevision)
            : $source->currentRevision;

        return $revision ?? throw CampaignsException::noRevision();
    }

    /**
     * The revision's contents to send, one per language asked for.
     *
     * @param  ?list<string>  $locales
     * @return list<RevisionContent>
     */
    protected function contents(Revision $revision, ?array $locales): array
    {
        $held = $revision->contents()->orderBy('id')->get();
        $contents = [];

        foreach ($locales ?: [Locales::default()] as $locale) {
            $wanted = Locales::normalize($locale);
            $content = $held->first(fn (RevisionContent $content): bool => Locales::normalize($content->locale) === $wanted)
                ?? throw CampaignsException::localeNotHeld($locale, $held->pluck('locale')->sort()->values()->all());

            if (! in_array($content, $contents, true)) {
                $contents[] = $content;
            }
        }

        return $contents;
    }
}
