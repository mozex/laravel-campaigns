<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

readonly class SendReceipt
{
    public function __construct(
        public string $providerMessageId,
    ) {}
}
