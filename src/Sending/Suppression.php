<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Mozex\Campaigns\Enums\SuppressionType;

readonly class Suppression
{
    public function __construct(
        public string $email,
        public SuppressionType $type,
        public ?CarbonInterface $since = null,
        public ?string $reason = null,
    ) {}
}
