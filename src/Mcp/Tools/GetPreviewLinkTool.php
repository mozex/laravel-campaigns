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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Actions\CreatePreviewLinkAction;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Support\Links;

/**
 * A read tool, though it may write: the test email rows a revision's
 * preview links open are made the first time, once per language, and
 * change nothing anyone sees, while whoever may read an email should be
 * able to look at it.
 */
#[Name('get_preview_link')]
#[Title('Get a preview link')]
#[Description('A link, valid '.Links::PREVIEW_DAYS.' days, to a page on the app showing a template\'s or a campaign\'s email as it will look: From, subject and preheader, the email at 600 and 375 px, a tab per language, the plain text and the pre-send checks\' results, filled in with made-up details. Give it to the person deciding to look at in a browser. It shows that revision for good: after a new revision, ask for a new link. Clicking the email\'s links there changes nothing.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetPreviewLinkTool extends CampaignsTool
{
    use NamesEmailOwner;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...static::ownerInput($schema),
            'revision' => $schema->integer()->min(1)->description('A revision\'s number; the current one by default.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string(),
            'expires_at' => $schema->string(),
            'revision' => $schema->integer()->description('The revision it shows.'),
            'locales' => $schema->array()->items($schema->string()),
        ];
    }

    public function perform(Request $request, CreatePreviewLinkAction $createLink): ResponseFactory
    {
        $input = $request->validate([
            ...static::ownerRules(),
            'revision' => static::revisionRules(),
        ]);

        $revision = static::revision(static::owner($input), isset($input['revision']) ? (int) $input['revision'] : null);
        $revision->load('contents:id,revision_id,locale');
        $expires = Links::previewExpiresAt();

        return Response::structured([
            'url' => $createLink->execute($revision, $expires),
            'expires_at' => $expires->toIso8601String(),
            'revision' => $revision->number,
            'locales' => $revision->locales(),
        ]);
    }
}
