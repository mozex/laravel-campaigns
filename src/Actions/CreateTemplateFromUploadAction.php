<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Assets\AssetLibrary;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\CreatedTemplate;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\TextValue;

/**
 * A new template from an uploaded email (one HTML file, or a zip with its
 * translations and images). The key, name, description, note and author are
 * checked before the upload is read, since reading uses it up; then its
 * images go into the asset library and the template is made in one
 * transaction.
 */
class CreateTemplateFromUploadAction
{
    public function __construct(
        protected ReadEmailUploadAction $read,
        protected AssetLibrary $library,
        protected CreateTemplateAction $createTemplate,
    ) {}

    /**
     * @param  array<string, string>  $subjects  by locale; a version without one takes its HTML's <title>
     * @param  array<string, string>  $preheaders  by locale
     *
     * @throws CampaignsException
     */
    public function execute(
        string $key,
        string $name,
        string $uploadId,
        array $subjects = [],
        array $preheaders = [],
        ?string $description = null,
        bool $isDefault = false,
        ?string $note = null,
        ?string $author = null,
    ): CreatedTemplate {
        $this->createTemplate->check($key, $name, $description);
        TextValue::optional('note', $note, 255);
        TextValue::optional('author', $author, 255);

        $email = $this->read->execute($uploadId, $subjects, $preheaders);

        return (new Template)->getConnection()->transaction(function () use ($key, $name, $description, $isDefault, $note, $author, $email): CreatedTemplate {
            $assets = $this->library->store($email->images, $author);
            $template = $this->createTemplate->execute($key, $name, $email->contents, $description, $isDefault, $note, $author);

            return new CreatedTemplate(
                template: $template,
                revision: $template->currentRevision()->firstOrFail(),
                assets: $assets,
                unusedImages: $email->unusedImages,
            );
        });
    }
}
