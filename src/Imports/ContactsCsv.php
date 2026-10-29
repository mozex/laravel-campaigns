<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Imports;

use Mozex\Campaigns\Actions\SetCustomFieldsAction;
use Mozex\Campaigns\Audiences\SubscriberFilters;
use Mozex\Campaigns\CampaignsException;
use SplFileObject;
use SplTempFileObject;

/**
 * A CSV of contacts read into rows ImportContactsAction takes: a header
 * naming each column by ImportedContact's fields, a custom field as
 * custom_fields.<name> and a value to keep in the consent records as
 * meta.<name>. Commas, semicolons or tabs separate the values (whichever the
 * header uses most), a value holding one is quoted, and the file is UTF-8,
 * with or without a byte order mark. Blank lines are skipped.
 *
 * A file whose header or shape is wrong is refused whole, naming rows by
 * their number (the first after the header is 1, as the import counts
 * them), never by an address; a row whose values are wrong is the import's
 * problem, reported with the rest.
 */
class ContactsCsv
{
    /**
     * The most contacts one file may hold. A larger list is imported by a
     * command on the server, which reads its source directly.
     */
    public const MAX_ROWS = 2000;

    /**
     * How many problems a refusal names.
     */
    protected const MAX_PROBLEMS = 20;

    protected const CUSTOM_FIELDS = 'custom_fields.';

    protected const META = 'meta.';

    protected const DELIMITER_NAMES = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab'];

    /**
     * @return list<array<string, mixed>>
     *
     * @throws CampaignsException naming what is wrong with the file
     */
    public static function read(string $contents): array
    {
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw CampaignsException::invalidCsv(['It isn\'t UTF-8 text. Save it as "CSV UTF-8".']);
        }

        $file = new SplTempFileObject;
        $file->fwrite($contents);
        $file->rewind();

        return static::rows($file, static::delimiter($contents));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function rows(SplFileObject $file, string $delimiter): array
    {
        $header = static::next($file, $delimiter);

        if ($header === null) {
            throw CampaignsException::invalidCsv(['It is empty: the first line names the columns, and every line after it is a contact.']);
        }

        $columns = static::columns($header);
        $rows = $problems = [];
        $number = 0;

        while (($values = static::next($file, $delimiter)) !== null) {
            $number++;

            if ($number > static::MAX_ROWS) {
                throw CampaignsException::invalidCsv(['It holds more than '.number_format(static::MAX_ROWS).' contacts. Split it, or import a list this large with a command on the server.']);
            }

            $extra = array_slice($values, count($columns));

            if (array_filter($extra, fn (string $value): bool => trim($value) !== '') !== []) {
                $problems[] = "Row {$number} has more values than the header has columns. Quote a value that holds a ".static::DELIMITER_NAMES[$delimiter].'.';

                continue;
            }

            $rows[] = static::row($columns, $values);
        }

        if ($problems !== []) {
            throw CampaignsException::invalidCsv(static::listed($problems));
        }

        return $rows;
    }

    /**
     * Each column's place in a row: a field, or a custom field or meta value
     * by its name.
     *
     * @param  list<string>  $header
     * @return list<array{string, ?string}>
     */
    protected static function columns(array $header): array
    {
        $fields = array_values(array_diff(ImportedContact::FIELDS, ['custom_fields', 'meta']));

        // A file without its header line starts with a contact, whose
        // address must never be quoted back as a column's name.
        if (array_filter($header, fn (string $cell): bool => str_contains($cell, '@')) !== []) {
            throw CampaignsException::invalidCsv(['The first line looks like a contact. It must name the columns: '.implode(', ', $fields).', custom_fields.<name> or meta.<name>.']);
        }

        $columns = $problems = $seen = [];
        $customFields = 0;

        foreach ($header as $place => $cell) {
            $name = trim($cell);
            $lower = strtolower($name);

            [$field, $key] = match (true) {
                in_array($lower, $fields, true) => [$lower, null],
                str_starts_with($lower, static::CUSTOM_FIELDS) => ['custom_fields', substr($name, strlen(static::CUSTOM_FIELDS))],
                str_starts_with($lower, static::META) => ['meta', substr($name, strlen(static::META))],
                default => [null, null],
            };

            if ($field === null) {
                $problems[] = 'Column '.($place + 1).' ("'.mb_strimwidth($name, 0, 40, '...').'") isn\'t a contact field. Use '.implode(', ', $fields).', custom_fields.<name> or meta.<name>.';

                continue;
            }

            if ($key !== null && preg_match(SubscriberFilters::CUSTOM_FIELD_NAME, $key) !== 1) {
                $problems[] = 'Column '.($place + 1).' needs a name after "'.$field.'.": letters, digits and underscores, starting with a letter or an underscore.';

                continue;
            }

            $id = $field.'.'.$key;

            if (isset($seen[$id])) {
                $problems[] = 'Column '.($place + 1).' repeats column '.$seen[$id].'.';

                continue;
            }

            $seen[$id] = $place + 1;
            $columns[] = [$field, $key];
            $customFields += $field === 'custom_fields' ? 1 : 0;
        }

        if ($problems === [] && ! isset($seen['email.'])) {
            $problems[] = 'No column is named email.';
        }

        if ($customFields > SetCustomFieldsAction::MAX_FIELDS) {
            $problems[] = 'It has '.$customFields.' custom field columns; a contact holds at most '.SetCustomFieldsAction::MAX_FIELDS.'.';
        }

        if ($problems !== []) {
            throw CampaignsException::invalidCsv(static::listed($problems));
        }

        return $columns;
    }

    /**
     * @param  list<array{string, ?string}>  $columns
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    protected static function row(array $columns, array $values): array
    {
        $row = [];

        foreach ($columns as $place => [$field, $key]) {
            $value = $values[$place] ?? '';

            if ($key === null) {
                $row[$field] = $value;
            } elseif (trim($value) !== '') {
                $row[$field][$key] = $value;
            }
        }

        return $row;
    }

    /**
     * The next record's values, skipping blank lines; null at the end.
     *
     * @return ?list<string>
     */
    protected static function next(SplFileObject $file, string $delimiter): ?array
    {
        while (! $file->eof()) {
            $values = $file->fgetcsv($delimiter, '"', '');

            if ($values === false) {
                break;
            }

            if ($values !== [null]) {
                return array_map(fn (?string $value): string => (string) $value, $values);
            }
        }

        return null;
    }

    /**
     * The separator the header line uses most: a comma, a semicolon (what
     * spreadsheets write where a comma is the decimal mark) or a tab.
     */
    protected static function delimiter(string $contents): string
    {
        $header = strtok($contents, "\r\n") ?: '';
        $best = ',';

        foreach ([';', "\t"] as $delimiter) {
            if (substr_count($header, $delimiter) > substr_count($header, $best)) {
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $problems
     * @return list<string>
     */
    protected static function listed(array $problems): array
    {
        $more = count($problems) - static::MAX_PROBLEMS;

        return $more > 0
            ? [...array_slice($problems, 0, static::MAX_PROBLEMS), "And {$more} more like these."]
            : $problems;
    }
}
