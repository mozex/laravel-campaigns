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
use Mozex\Campaigns\Actions\BlockAction;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Reports\SubscriberSummary;

#[Name('block_contact')]
#[Title('Block an address')]
#[Description('Stops all email to one address that mustn\'t get any (a role address, a trap, an address someone reports as wrong), as manual. A block belongs to the address and stays until unblock_contact. A person who only asked to stop: unsubscribe_contact.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class BlockContactTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['email' => $schema->string()->required()];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->description('False when the address was already blocked; its first reason stands.'),
            'subscriber' => Schemas::subscriberState($schema),
        ];
    }

    public function perform(Request $request, BlockAction $block): ResponseFactory
    {
        $input = $request->validate(['email' => ['required', 'string']]);
        $subscriber = static::subscriber($input['email']);

        return Response::structured([
            'changed' => $block->execute($subscriber, BlockReason::Manual, static::agentContext()),
            'subscriber' => SubscriberSummary::state($subscriber),
        ]);
    }
}
