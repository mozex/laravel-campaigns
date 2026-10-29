<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\Keys;
use Mozex\Campaigns\Support\TextValue;
use Mozex\Campaigns\Templates\StarterTemplates;

/**
 * A new template with its first revision. Nothing is saved unless the email
 * meets the rules.
 */
class CreateTemplateAction
{
    public function __construct(protected AddRevisionAction $addRevision) {}

    /**
     * @param  list<EmailContent>  $contents  one per locale
     *
     * @throws CampaignsException
     */
    public function execute(
        string $key,
        string $name,
        array $contents,
        ?string $description = null,
        bool $isDefault = false,
        ?string $note = null,
        ?string $author = null,
    ): Template {
        [$name, $description] = $this->check($key, $name, $description);

        return (new Template)->getConnection()->transaction(function () use ($key, $name, $description, $isDefault, $contents, $note, $author): Template {
            if (Template::query()->where('key', $key)->exists()) {
                throw CampaignsException::keyTaken('template', $key);
            }

            try {
                $template = Template::create([
                    'key' => $key,
                    'name' => $name,
                    'description' => $description,
                    'is_default' => $isDefault,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CampaignsException::keyTaken('template', $key);
            }

            $this->addRevision->execute($template, $contents, $note, $author);

            return $template;
        });
    }

    /**
     * What a new template's key, name and description must meet, checked
     * before anything else, so a caller can ask before it uses up an upload.
     * The key is checked again under the insert.
     *
     * @return array{0: string, 1: ?string} the name and description as stored
     *
     * @throws CampaignsException
     */
    public function check(string $key, string $name, ?string $description = null): array
    {
        if (! Keys::isValid($key)) {
            throw CampaignsException::invalidKey($key);
        }

        if (StarterTemplates::has($key)) {
            throw CampaignsException::reservedKey($key);
        }

        if (Template::query()->where('key', $key)->exists()) {
            throw CampaignsException::keyTaken('template', $key);
        }

        return [TextValue::required('name', $name, 255), TextValue::optional('description', $description, 5000)];
    }
}
