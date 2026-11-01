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
use Mozex\Campaigns\Actions\QueueCampaignChecksAction;
use Mozex\Campaigns\Actions\QueueChecksAction;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Models\Campaign;

#[Name('run_checks')]
#[Title('Check an email before it is sent')]
#[Description('Queues the pre-send checks of a template\'s or a campaign\'s current email and returns at once: every link and image fetched from the server, the size with its open pixel and tracked links, images without alt text or not on https, the unsubscribe and privacy links, a version for every language the campaign\'s recipients read, and a spam score where the app turns it on. They take a minute or two; read them with get_check_results. They report and never block a start. Running them again replaces a run still waiting.')]
#[IsOpenWorld]
#[IsDestructive(false)]
class RunChecksTool extends CampaignsTool
{
    use NamesEmailOwner;

    public function schema(JsonSchema $schema): array
    {
        return static::ownerInput($schema);
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'revision' => $schema->integer()->description('The number of the revision being checked.'),
            'status' => $schema->string()->enum(['queued']),
            'queued_at' => $schema->string(),
        ];
    }

    public function perform(Request $request, QueueChecksAction $queueChecks, QueueCampaignChecksAction $queueCampaignChecks): ResponseFactory
    {
        $input = $request->validate(static::ownerRules());

        $owner = static::owner($input);
        $revision = static::revision($owner);

        // A campaign's checks know its recipients' languages and whether it
        // tracks clicks; a template's know neither, and count tracked links.
        if ($owner instanceof Campaign) {
            $queueCampaignChecks->execute($owner);
        } else {
            $queueChecks->execute($revision);
        }

        return Response::structured([
            'revision' => $revision->number,
            'status' => 'queued',
            'queued_at' => (string) ($revision->refresh()->check_results['queued_at'] ?? now()->toIso8601String()),
        ]);
    }
}
