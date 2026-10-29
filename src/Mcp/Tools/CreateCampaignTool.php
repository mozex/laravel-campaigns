<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\AnswersUploadedEmails;
use Mozex\Campaigns\Mcp\Tools\Concerns\DescribesCampaignSettings;
use Mozex\Campaigns\Mcp\Tools\Concerns\TakesEmailContent;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Support\Keys;

#[Name('create_campaign')]
#[Title('Create a campaign')]
#[Description('A new draft campaign with its first email, from one of: template (a template\'s key or a starter\'s, whose current email it copies), upload_id (an email sent through an email upload link, with subjects and preheaders by language) or contents (the email inline). Settings (its lists, audience, sender, tracking, UTM tags, pace and limits) can come now or later through update_campaign. It comes from the app\'s sender unless from_email names another address on the sending domain. A refused upload is used up: send it again through a new link. Nothing is sent: test it with send_test and look at it with get_preview_link.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class CreateCampaignTool extends CampaignsTool
{
    use AnswersUploadedEmails;
    use DescribesCampaignSettings;
    use TakesEmailContent;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('Lower-case letters, digits, dots, dashes and underscores, at most '.Keys::MAX_LENGTH.' characters. It never changes, and it is the campaign\'s tag at the provider and its utm_campaign.'),
            'name' => $schema->string()->required(),
            'template' => $schema->string()->description('Copy a template\'s current email, or a starter template\'s.'),
            ...static::contentInput($schema),
            'settings' => static::settingsInput($schema),
            'note' => $schema->string()->description('What this first version of its email is, kept in its history.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::campaign($schema),
            ...static::uploadOutput($schema),
        ];
    }

    public function perform(Request $request, CreateCampaignAction $create): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
            'template' => ['nullable', 'string', 'required_without_all:upload_id,contents', 'prohibits:upload_id,contents'],
            ...static::contentRules(),
            'settings' => ['nullable', 'array'],
            'note' => ['nullable', 'string'],
        ]);

        $source = match (true) {
            isset($input['template']) => EmailSource::template($input['template']),
            isset($input['upload_id']) => EmailSource::upload($input['upload_id'], static::byLocale($input, 'subjects'), static::byLocale($input, 'preheaders')),
            default => EmailSource::contents(static::inlineContents($input)),
        };

        $created = $create->execute(
            key: $input['key'],
            name: $input['name'],
            email: $source,
            settings: static::settings($input['settings'] ?? []),
            note: $input['note'] ?? null,
            author: static::author(),
        );

        return Response::structured([
            'campaign' => CampaignSummary::detail($created->campaign),
            // Only an upload stores images, so only an upload answers them.
            ...static::uploadAnswer(isset($input['upload_id']) ? $created : null),
        ]);
    }
}
