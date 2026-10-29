<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\ShownText;
use Mozex\Campaigns\Templates\StarterTemplate;
use Mozex\Campaigns\Templates\StarterTemplates;

/**
 * Templates as the tools show them: the package's starters, and the app's
 * own, a row in a list or the whole of one.
 */
class TemplateSummary
{
    /** How much of a description a row shows. */
    public const DESCRIPTION_LENGTH = 200;

    /** How much of a description one template's own answer shows. */
    public const DETAIL_DESCRIPTION_LENGTH = 1000;

    /**
     * The package's starter templates, read-only, to duplicate from.
     *
     * @return list<array<string, mixed>>
     */
    public static function starters(): array
    {
        return array_map(fn (StarterTemplate $starter): array => [
            'key' => $starter->key,
            'name' => $starter->name,
            'description' => $starter->description,
            'locales' => array_map(fn (EmailContent $content): string => $content->locale, $starter->contents),
        ], StarterTemplates::all());
    }

    /**
     * One of the app's templates. Its current revision's locales are read
     * from the loaded currentRevision.contents (load only id, revision_id
     * and locale for a page of them).
     *
     * @return array<string, mixed>
     */
    public static function row(Template $template, int $descriptionLength = self::DESCRIPTION_LENGTH): array
    {
        $current = $template->currentRevision;

        return [
            'key' => $template->key,
            'name' => ShownText::of($template->name),
            'description' => ShownText::of($template->description, $descriptionLength),
            'is_default' => $template->is_default,
            'current_revision' => $current?->number,
            'locales' => $current?->locales() ?? [],
            'archived_at' => $template->archived_at?->toIso8601String(),
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
