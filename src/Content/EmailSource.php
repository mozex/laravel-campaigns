<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\Models\Template;

/**
 * Where a new campaign's first email comes from: a template's current
 * revision (or a starter template, by its key), an upload, or content given
 * inline.
 */
readonly class EmailSource
{
    /**
     * @param  list<EmailContent>  $contents
     * @param  array<string, string>  $subjects  an upload's, by locale
     * @param  array<string, string>  $preheaders  an upload's, by locale
     */
    protected function __construct(
        public Template|string|null $template = null,
        public ?string $uploadId = null,
        public array $contents = [],
        public array $subjects = [],
        public array $preheaders = [],
    ) {}

    /**
     * A template, or its key, or a starter template's key.
     */
    public static function template(Template|string $template): self
    {
        return new self(template: $template);
    }

    /**
     * @param  array<string, string>  $subjects  by locale; a version without one takes its HTML's <title>
     * @param  array<string, string>  $preheaders  by locale
     */
    public static function upload(string $uploadId, array $subjects = [], array $preheaders = []): self
    {
        return new self(uploadId: $uploadId, subjects: $subjects, preheaders: $preheaders);
    }

    /**
     * @param  list<EmailContent>  $contents  one per locale
     */
    public static function contents(array $contents): self
    {
        return new self(contents: $contents);
    }
}
