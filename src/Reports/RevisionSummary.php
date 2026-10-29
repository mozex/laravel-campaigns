<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Carbon\CarbonInterface;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\ShownText;

/**
 * A revision of a template's or a campaign's email as the tools show it: a
 * row in its history, or the whole of it with links to download its files.
 * The HTML itself never goes into an answer (up to 90 KB a language): it
 * goes out through the download links.
 */
class RevisionSummary
{
    /** How much of a note and an author a history's row shows. */
    public const ROW_TEXT_LENGTH = 150;

    /**
     * A revision in a history. Its contents' locales are read from the
     * loaded contents (load only id, revision_id and locale for a page of
     * them), and a frozen copy's source from frozenFrom.
     *
     * @return array<string, mixed>
     */
    public static function row(Revision $revision): array
    {
        return [
            'number' => $revision->number,
            'note' => ShownText::of($revision->note, static::ROW_TEXT_LENGTH),
            'author' => ShownText::of($revision->author, static::ROW_TEXT_LENGTH),
            'created_at' => $revision->created_at?->toIso8601String(),
            'locales' => $revision->locales(),
            'frozen_from' => $revision->frozen_from_id === null ? null : $revision->frozenFrom?->number,
            'checks' => CheckSummary::status($revision),
        ];
    }

    /**
     * The revision whole: each language's subject, preheader and size, and
     * the links to download its files until $expires. Subjects and
     * preheaders aren't in the files, so an agent sending them back passes
     * them with the upload.
     *
     * @return array<string, mixed>
     */
    public static function detail(Revision $revision, CarbonInterface $expires): array
    {
        $default = Locales::normalize(Locales::default());

        $versions = $revision->contents
            ->sortBy(fn (RevisionContent $content): string => (Locales::normalize($content->locale) === $default ? '0' : '1').$content->locale)
            ->map(fn (RevisionContent $content): array => [
                'locale' => $content->locale,
                'subject' => ShownText::of($content->subject),
                'preheader' => ShownText::of($content->preheader),
                'html_bytes' => strlen($content->html),
                'text' => $content->text_generated ? 'generated' : 'written',
            ])
            ->values()
            ->all();

        return [
            ...static::row($revision),
            'versions' => $versions,
            'downloads' => [
                'zip' => Links::download($revision, 'email.zip', $expires),
                'html' => Links::download($revision, 'email.html', $expires),
                'expires_at' => $expires->toIso8601String(),
            ],
        ];
    }
}
