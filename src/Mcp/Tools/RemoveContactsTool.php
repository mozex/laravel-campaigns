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
use Mozex\Campaigns\Actions\RemoveContactsAction;
use Mozex\Campaigns\Models\CampaignList;

#[Name('remove_contacts')]
#[Title('Remove contacts from a list')]
#[Description('Takes up to 100 addresses off a static list. Nobody is opted out: they stay on their other lists. To stop all email to someone, unsubscribe them instead.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class RemoveContactsTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->required()->description('The static list\'s key.'),
            'emails' => $schema->array()->items($schema->string())->min(1)->max(RemoveContactsAction::MAX_CONTACTS)->required(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'removed' => $schema->integer(),
            'not_on_list' => $schema->integer(),
            'not_found' => $schema->integer()->description('No subscriber has the address.'),
            'invalid' => $schema->integer()->description('Not an email address.'),
        ];
    }

    public function perform(Request $request, RemoveContactsAction $remove): ResponseFactory
    {
        $input = $request->validate([
            'list' => ['required', 'string'],
            'emails' => ['required', 'array', 'list'],
            'emails.*' => ['string'],
        ]);

        return Response::structured($remove->execute(CampaignList::forKey($input['list']), $input['emails'], static::agentContext()));
    }
}
