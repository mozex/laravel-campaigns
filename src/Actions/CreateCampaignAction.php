<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\Assets\AssetLibrary;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\CreatedCampaign;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\CampaignSettings;
use Mozex\Campaigns\Support\Keys;
use Mozex\Campaigns\Support\Sender;
use Mozex\Campaigns\Support\TextValue;
use Mozex\Campaigns\Templates\StarterTemplates;

/**
 * A new draft campaign with its first email, from a template, an upload or
 * content given inline. It comes from the configured sender unless its
 * settings name another address on the sending domain, and its one tag is
 * its key. Everything else is checked before an upload is read, since
 * reading uses the upload up.
 */
class CreateCampaignAction
{
    public function __construct(
        protected AddRevisionAction $addRevision,
        protected ReadEmailUploadAction $readUpload,
        protected AssetLibrary $library,
    ) {}

    /**
     * @param  array<string, mixed>  $settings  as UpdateCampaignAction takes them
     *
     * @throws CampaignsException|ValidationException
     */
    public function execute(
        string $key,
        string $name,
        EmailSource $email,
        array $settings = [],
        ?string $note = null,
        ?string $author = null,
    ): CreatedCampaign {
        if (! Keys::isValid($key)) {
            throw CampaignsException::invalidKey($key);
        }

        if (Campaign::query()->where('key', $key)->exists()) {
            throw CampaignsException::keyTaken('campaign', $key);
        }

        $columns = [
            'from_name' => Sender::name(),
            'reply_to' => Sender::replyTo(),
            ...CampaignSettings::columns($settings),
            'key' => $key,
            'name' => TextValue::required('name', $name, 255),
            'status' => CampaignStatus::Draft,
            'tag' => $key,
        ];

        if (! array_key_exists('from_email', $columns)) {
            $columns['from_email'] = CampaignSettings::from(Sender::address()
                ?? throw CampaignsException::invalidSetting('from_email', 'no sender is configured (campaigns.mail.from), so name one'));
        }

        [$template, $contents] = $this->source($email);
        $upload = $email->uploadId === null ? null : $this->readUpload->execute($email->uploadId, $email->subjects, $email->preheaders);

        return (new Campaign)->getConnection()->transaction(function () use ($columns, $template, $contents, $upload, $note, $author, $key): CreatedCampaign {
            try {
                $campaign = Campaign::create([...$columns, 'template_id' => $template?->id]);
            } catch (UniqueConstraintViolationException) {
                throw CampaignsException::keyTaken('campaign', $key);
            }

            $assets = $upload === null ? [] : $this->library->store($upload->images, $author);
            $revision = $this->addRevision->execute($campaign, $upload->contents ?? $contents, $note, $author);

            return new CreatedCampaign(
                campaign: $campaign->refresh(),
                revision: $revision,
                assets: $assets,
                unusedImages: $upload->unusedImages ?? [],
            );
        });
    }

    /**
     * The template a campaign comes from (none for a starter or an upload)
     * and the content to copy.
     *
     * @return array{0: ?Template, 1: list<EmailContent>}
     */
    protected function source(EmailSource $email): array
    {
        if ($email->template === null) {
            if ($email->uploadId === null && $email->contents === []) {
                throw CampaignsException::noRevision();
            }

            return [null, $email->contents];
        }

        $starter = is_string($email->template) ? StarterTemplates::find($email->template) : null;

        if ($starter !== null) {
            return [null, $starter->contents];
        }

        $template = is_string($email->template) ? Template::findByKey($email->template) : $email->template;

        if ($template === null) {
            throw CampaignsException::unknownTemplate((string) $email->template);
        }

        $revision = $template->currentRevision()->first() ?? throw CampaignsException::noRevision();

        return [$template, $revision->contentsToCopy()];
    }
}
