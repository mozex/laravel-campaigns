<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Templates\StarterTemplates;

/**
 * A new template starting from another one's current email, or from one of
 * the package's starter templates (named by its key).
 */
class DuplicateTemplateAction
{
    public function __construct(protected CreateTemplateAction $createTemplate) {}

    /**
     * @throws CampaignsException
     */
    public function execute(Template|string $source, string $key, string $name, ?string $author = null): Template
    {
        $starter = is_string($source) ? StarterTemplates::find($source) : null;

        if ($starter !== null) {
            return $this->createTemplate->execute(
                key: $key,
                name: $name,
                contents: $starter->contents,
                note: "Duplicated from the starter template \"{$starter->key}\"",
                author: $author,
            );
        }

        $template = is_string($source) ? Template::findByKey($source) : $source;

        if ($template === null) {
            throw CampaignsException::unknownTemplate((string) $source);
        }

        $revision = $template->currentRevision()->first();

        if ($revision === null) {
            throw CampaignsException::noRevision();
        }

        return $this->createTemplate->execute(
            key: $key,
            name: $name,
            contents: $revision->contentsToCopy(),
            description: $template->description,
            note: "Duplicated from template \"{$template->key}\", revision {$revision->number}",
            author: $author,
        );
    }
}
