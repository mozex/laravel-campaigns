<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Imports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Throwable;

/**
 * One person brought in from another system: their address and details,
 * when they last read the other system's email or used the app, and whether
 * that system had them opted out or blocked.
 */
readonly class ImportedContact
{
    /**
     * The keys fromArray() reads.
     */
    public const FIELDS = [
        'email', 'name', 'first_name', 'locale', 'timezone', 'country', 'custom_fields',
        'last_opened_at', 'last_clicked_at', 'last_active_at', 'opted_out', 'blocked', 'meta',
    ];

    /**
     * @param  array<string, mixed>  $customFields  Free-form data, added to what the subscriber lacks.
     * @param  ?CarbonInterface  $lastOpenedAt  The last open a person made, never a mail app's automatic one.
     * @param  bool  $optOut  The other system had the person opted out of marketing.
     * @param  ?BlockReason  $block  The other system couldn't or mustn't mail the address, and why.
     * @param  array<string, mixed>  $meta  Kept in this contact's consent records, such as its original lists and the date it was added there.
     */
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?string $firstName = null,
        public ?string $locale = null,
        public ?string $timezone = null,
        public ?string $country = null,
        public array $customFields = [],
        public ?CarbonInterface $lastOpenedAt = null,
        public ?CarbonInterface $lastClickedAt = null,
        public ?CarbonInterface $lastActiveAt = null,
        public bool $optOut = false,
        public ?BlockReason $block = null,
        public array $meta = [],
    ) {}

    /**
     * A contact from a row such as a CSV line or a tool's input, keyed by
     * FIELDS. Empty strings count as missing; dates take any format Carbon
     * reads; opted_out takes true, false, 1, 0, yes or no; blocked takes a
     * BlockReason value.
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws CampaignsException for an unknown key or a value that can't be read
     */
    public static function fromArray(array $row): self
    {
        foreach (array_keys($row) as $key) {
            if (! in_array($key, static::FIELDS, true)) {
                throw CampaignsException::unknownImportField((string) $key);
            }
        }

        $email = static::text($row, 'email');

        if ($email === null) {
            throw CampaignsException::invalidImportValue('email', 'it is missing');
        }

        return new self(
            email: $email,
            name: static::text($row, 'name'),
            firstName: static::text($row, 'first_name'),
            locale: static::text($row, 'locale'),
            timezone: static::text($row, 'timezone'),
            country: static::text($row, 'country'),
            customFields: static::map($row, 'custom_fields'),
            lastOpenedAt: static::date($row, 'last_opened_at'),
            lastClickedAt: static::date($row, 'last_clicked_at'),
            lastActiveAt: static::date($row, 'last_active_at'),
            optOut: static::flag($row, 'opted_out'),
            block: static::reason($row, 'blocked'),
            meta: static::map($row, 'meta'),
        );
    }

    /**
     * Only the opt-out and block a row carries, for a row whose other values
     * can't be read or stored: a bad date or name must never cost the
     * address its suppression. Null when it carries neither, or when they
     * can't be read either.
     *
     * @param  self|array<array-key, mixed>  $row
     */
    public static function suppressionOf(self|array $row): ?self
    {
        try {
            $email = $row instanceof self ? $row->email : static::text($row, 'email');
            $suppression = $email === null ? null : new self(
                $email,
                optOut: $row instanceof self ? $row->optOut : static::flag($row, 'opted_out'),
                block: $row instanceof self ? $row->block : static::reason($row, 'blocked'),
            );
        } catch (CampaignsException) {
            return null;
        }

        return $suppression?->suppresses() === true ? $suppression : null;
    }

    /**
     * Whether the contact carries an opt-out or a block.
     */
    public function suppresses(): bool
    {
        return $this->optOut || $this->block !== null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    protected static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && ! is_scalar($value)) {
            throw CampaignsException::invalidImportValue($key, 'it is not text');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function map(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw CampaignsException::invalidImportValue($key, 'it is not a map of names to values');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    protected static function date(array $row, string $key): ?CarbonInterface
    {
        $value = $row[$key] ?? null;

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $text = static::text($row, $key);

        if ($text === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text);
        } catch (Throwable) {
            throw CampaignsException::invalidImportValue($key, 'it is not a date');
        }
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    protected static function flag(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        $text = static::text($row, $key);

        if ($text === null) {
            return false;
        }

        return filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw CampaignsException::invalidImportValue($key, 'it is not true or false');
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    protected static function reason(array $row, string $key): ?BlockReason
    {
        $value = $row[$key] ?? null;

        if ($value instanceof BlockReason) {
            return $value;
        }

        $text = static::text($row, $key);

        if ($text === null) {
            return null;
        }

        return BlockReason::tryFrom($text)
            ?? throw CampaignsException::invalidImportValue($key, 'it is not one of '.implode(', ', array_column(BlockReason::cases(), 'value')));
    }
}
