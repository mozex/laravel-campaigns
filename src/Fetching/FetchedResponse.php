<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

/**
 * What a fetch ended with, after its redirects: the last address, its
 * status, its content type and, for a GET, its body.
 */
readonly class FetchedResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public string $contentType = '',
        public string $body = '',
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
