<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\Models\RevisionContent;

/**
 * An email in one language, as an agent or an upload gives it: a revision
 * holds one per locale. A null text is generated from the HTML.
 */
readonly class EmailContent
{
    public function __construct(
        public string $locale,
        public string $subject,
        public string $html,
        public ?string $text = null,
        public ?string $preheader = null,
        public bool $textGenerated = false,
    ) {}

    public static function fromRevisionContent(RevisionContent $content): self
    {
        return new self(
            locale: $content->locale,
            subject: $content->subject,
            html: $content->html,
            text: $content->text,
            preheader: $content->preheader,
            textGenerated: $content->text_generated,
        );
    }
}
