<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * One recipient's email with every placeholder filled in.
 */
readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public ?string $preheader,
        public string $html,
        public string $text,
    ) {}
}
