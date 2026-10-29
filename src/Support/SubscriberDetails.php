<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

/**
 * The one place a subscriber's details are cleaned before they are stored,
 * whether they come from a list source or an import.
 */
class SubscriberDetails
{
    /**
     * The details a subscriber keeps, with their column lengths. The country
     * is a two-letter code.
     */
    public const LENGTHS = ['name' => 255, 'first_name' => 255, 'locale' => 35, 'timezone' => 64];

    /**
     * The given details, trimmed, cut to their columns and empty ones made
     * null; a country that isn't a two-letter code becomes null. Only the
     * keys given come back, and anything that isn't a detail is dropped.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, ?string>
     */
    public static function clean(array $fields): array
    {
        $details = [];

        foreach (static::LENGTHS as $field => $length) {
            if (array_key_exists($field, $fields)) {
                $details[$field] = static::cut($fields[$field], $length);
            }
        }

        if (array_key_exists('country', $fields)) {
            $country = strtoupper(trim((string) $fields['country']));
            $details['country'] = preg_match('/^[A-Z]{2}\z/', $country) === 1 ? $country : null;
        }

        return $details;
    }

    protected static function cut(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
