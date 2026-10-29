<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Carbon\CarbonInterface;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\PreviewToken;
use Mozex\Campaigns\Support\Rows;
use Mozex\Campaigns\Support\Token;

/**
 * A link to a revision's preview page, and the test email rows its links'
 * tokens open: one per language, made once and reused by every later link
 * to the same revision. They are made here rather than when the page opens,
 * since opening a page changes nothing. A row is never sent (its token is
 * set from the start, and a test email's job claims only a row without
 * one) and names no campaign, so it never shows among a campaign's tests.
 */
class CreatePreviewLinkAction
{
    /**
     * @throws CampaignsException when the app has no key
     */
    public function execute(Revision $revision, CarbonInterface $expires): string
    {
        foreach ($revision->contents()->orderBy('id')->pluck('locale') as $locale) {
            Rows::firstOrCreate(TestSend::query(), ['token_hash' => Token::hash(PreviewToken::for($revision, (string) $locale))], [
                'revision_id' => $revision->id,
                'email' => Personalization::SAMPLE_EMAIL,
                'locale' => $locale,
            ]);
        }

        return Links::preview($revision, $expires);
    }
}
