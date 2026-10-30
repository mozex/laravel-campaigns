<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * One place an email's HTML names an image to load: where its text is
 * written in the HTML, and what it says once decoded as a mail app reads
 * it. A srcset holds several addresses with their descriptors; anything
 * else holds one.
 */
readonly class ImageSource
{
    public function __construct(
        public int $offset,
        public int $length,
        public string $value,
        public bool $srcset = false,
        public bool $inAttribute = true,
    ) {}

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        if (! $this->srcset) {
            return [trim($this->value)];
        }

        return array_column(Html::srcset($this->value), 'url');
    }
}
