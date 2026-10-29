<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Support\ShownText;

/**
 * A revision's pre-send check results (its check_results, whose shape
 * Checks\PreSendChecks and Jobs\RunChecksJob write) as the tools show them:
 * where the run stands, one row per check, and one check's problems a page
 * at a time. A check's list (broken links, images, spam rules, missing
 * languages) has no length limit, so rows count it and a page shows it,
 * each address and text cut.
 */
class CheckSummary
{
    public const MESSAGE_LENGTH = 300;

    /** How much of a run's own failure a status shows, which every revision's row carries. */
    public const STATUS_MESSAGE_LENGTH = 150;

    public const URL_LENGTH = 300;

    public const TEXT_LENGTH = 200;

    /** The languages an item names, at most. */
    public const ITEM_LOCALES = 10;

    /**
     * Where the revision's latest run stands: none, queued, done or failed.
     *
     * @return array<string, mixed>
     */
    public static function status(Revision $revision): array
    {
        $results = $revision->check_results ?? [];

        return [
            'status' => is_string($results['status'] ?? null) ? $results['status'] : 'none',
            'queued_at' => $results['queued_at'] ?? null,
            'finished_at' => $results['finished_at'] ?? null,
            'passed' => $results['passed'] ?? null,
            'failed' => $results['failed'] ?? null,
            'message' => ShownText::of(is_string($results['message'] ?? null) ? $results['message'] : null, static::STATUS_MESSAGE_LENGTH),
        ];
    }

    /**
     * The run's checks, each with how many items it lists.
     *
     * @return list<array<string, mixed>>
     */
    public static function checks(Revision $revision): array
    {
        return array_map(fn (array $check): array => [
            'check' => (string) ($check['check'] ?? ''),
            'locale' => ShownText::of(static::text($check['locale'] ?? null)),
            'status' => (string) ($check['status'] ?? ''),
            'message' => ShownText::of((string) ($check['message'] ?? ''), static::MESSAGE_LENGTH),
            'items' => count(static::listed($check)),
            'score' => $check['score'] ?? null,
            'bytes' => $check['bytes'] ?? null,
        ], static::all($revision));
    }

    /**
     * What one check lists (for a check run per language, that language's),
     * each address and text cut.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(Revision $revision, string $check, ?string $locale): array
    {
        $items = [];

        foreach (static::all($revision) as $result) {
            if (($result['check'] ?? null) !== $check || ($locale !== null && ($result['locale'] ?? null) !== $locale)) {
                continue;
            }

            foreach (static::listed($result) as $item) {
                $items[] = array_filter([
                    'locale' => ShownText::of(static::text($result['locale'] ?? $item['locale'] ?? null)),
                    'url' => ShownText::of(static::text($item['url'] ?? null), static::URL_LENGTH),
                    'problem' => ShownText::of(static::text($item['problem'] ?? null), static::TEXT_LENGTH),
                    'locales' => isset($item['locales']) && is_array($item['locales'])
                        ? array_map(fn (mixed $locale): string => (string) ShownText::of(static::text($locale)), array_slice(array_values($item['locales']), 0, static::ITEM_LOCALES))
                        : null,
                    'score' => $item['score'] ?? null,
                    'description' => ShownText::of(static::text($item['description'] ?? null), static::TEXT_LENGTH),
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function all(Revision $revision): array
    {
        $checks = $revision->check_results['checks'] ?? [];

        return is_array($checks) ? array_values(array_filter($checks, 'is_array')) : [];
    }

    /**
     * A check's list: its items (images, links), its spam rules, or the
     * languages it found missing.
     *
     * @param  array<string, mixed>  $check
     * @return list<array<string, mixed>>
     */
    protected static function listed(array $check): array
    {
        $listed = match (true) {
            isset($check['items']) => $check['items'],
            isset($check['rules']) => $check['rules'],
            isset($check['missing']) => array_map(fn (mixed $locale): array => ['locale' => $locale, 'problem' => 'no version in this language'], (array) $check['missing']),
            default => [],
        };

        return is_array($listed) ? array_values(array_filter($listed, 'is_array')) : [];
    }

    protected static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
