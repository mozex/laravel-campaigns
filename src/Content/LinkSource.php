<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * One link in an email's HTML: where its href's value is written, and the
 * address it holds once decoded as a mail app reads it, trimmed, tabs and
 * line breaks dropped. text is a link's visible text, for an a element (null
 * for area and VML); noTrack is where its data-campaigns-no-track attribute
 * is written, start and end, when it carries one.
 */
readonly class LinkSource
{
    /**
     * @param  ?array{0: int, 1: int}  $noTrack
     */
    public function __construct(
        public int $offset,
        public int $length,
        public string $url,
        public string $element,
        public ?string $text = null,
        public ?array $noTrack = null,
    ) {}

    /**
     * Whether its visible text is itself an address: a person reads where
     * it leads, so it must lead there, and a mail app warns when a link's
     * text names one address and its href another.
     */
    public function showsAddress(): bool
    {
        return $this->text !== null && preg_match('#^(?:https?://|www\.)\S+$#i', $this->text) === 1;
    }
}
