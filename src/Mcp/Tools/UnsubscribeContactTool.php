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
use Mozex\Campaigns\Actions\OptOutAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Reports\SubscriberSummary;

#[Name('unsubscribe_contact')]
#[Title('Unsubscribe a contact')]
#[Description('Stops all marketing email to one address, whatever lists it is on, as the person\'s own unsubscribe does: for someone who asked by reply or by mail. Their lists stay, for history. Only the person can undo it, from an email\'s unsubscribe page or a sign-up.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class UnsubscribeContactTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['email' => $schema->string()->required()];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->description('False when the person had already opted out.'),
            'subscriber' => Schemas::subscriberState($schema),
        ];
    }

    public function perform(Request $request, OptOutAction $optOut): ResponseFactory
    {
        $input = $request->validate(['email' => ['required', 'string']]);
        $subscriber = static::subscriber($input['email']);

        return Response::structured([
            'changed' => $optOut->execute($subscriber, static::agentContext()),
            'subscriber' => SubscriberSummary::state($subscriber),
        ]);
    }
}
