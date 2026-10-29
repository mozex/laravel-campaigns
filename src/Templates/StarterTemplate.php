<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Templates;

use Mozex\Campaigns\Content\EmailContent;

readonly class StarterTemplate
{
    /**
     * @param  list<EmailContent>  $contents
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public array $contents,
    ) {}
}
