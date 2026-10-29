<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

/**
 * What one request of a fetch learned before its transfer ended: the
 * status and redirect address its headers gave, and whether the fetcher
 * stopped it on purpose (once the headers were in, or over the size limit).
 */
class Hop
{
    public ?int $status = null;

    public string $location = '';

    public bool $tooLarge = false;

    public bool $stoppedAfterHeaders = false;
}
