<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Support\ShownText;

/**
 * A campaign's report (CampaignReport::for()) as an agent's answer shows
 * it: the parts that grow with the campaign are cut, each with its total,
 * so the answer stays under CampaignsTool::RESULT_LIMIT: the most clicked
 * links, the largest lists and languages, and the latest days. The report
 * kept on the campaign stays whole.
 */
class ReportSummary
{
    /** How many links, lists, languages and days, and how much of a link. */
    public const LINKS = 20;

    public const URL_LENGTH = 200;

    public const BREAKDOWN = 20;

    public const DAYS = 31;

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function of(array $report): array
    {
        $links = is_array($report['links'] ?? null) ? $report['links'] : [];
        $timeline = is_array($report['timeline'] ?? null) ? $report['timeline'] : [];
        $campaign = is_array($report['campaign'] ?? null) ? $report['campaign'] : [];

        return [
            ...$report,
            'campaign' => [...$campaign, 'name' => ShownText::of(is_string($campaign['name'] ?? null) ? $campaign['name'] : null)],
            'failures' => (object) ($report['failures'] ?? []),
            'links' => array_map(fn (mixed $link): array => [
                ...(array) $link,
                'url' => (string) ShownText::of((string) (((array) $link)['url'] ?? ''), static::URL_LENGTH),
            ], array_slice($links, 0, static::LINKS)),
            // The report keeps its most clicked links and their total; one
            // kept before it counted them has only its list.
            'links_total' => is_int($report['links_total'] ?? null) ? $report['links_total'] : count($links),
            ...static::largest($report, 'by_list', 'lists_total'),
            ...static::largest($report, 'by_locale', 'locales_total'),
            'timeline' => (object) array_slice($timeline, -static::DAYS, preserve_keys: true),
            'days_total' => count($timeline),
        ];
    }

    /**
     * A breakdown's largest rows by messages (then by name), with how many
     * there are.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    protected static function largest(array $report, string $part, string $total): array
    {
        $rows = is_array($report[$part] ?? null) ? $report[$part] : [];
        $entries = [];

        // Sorted as pairs, since PHP turns a key like "2024" into a number
        // that a sort of the array itself would renumber.
        foreach ($rows as $name => $row) {
            $entries[] = [(string) $name, $row, (int) (((array) $row)['messages'] ?? 0)];
        }

        usort($entries, fn (array $a, array $b): int => [$b[2], $a[0]] <=> [$a[2], $b[0]]);

        $shown = [];

        foreach (array_slice($entries, 0, static::BREAKDOWN) as [$name, $row]) {
            $shown[(string) ShownText::of($name)] = $row;
        }

        return [$part => (object) $shown, $total => count($rows)];
    }
}
