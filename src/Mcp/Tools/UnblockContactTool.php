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
use Mozex\Campaigns\Actions\CheckProviderSuppressionsAction;
use Mozex\Campaigns\Actions\UnblockAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Reports\SubscriberSummary;
use Mozex\Campaigns\Sending\Suppression;

#[Name('unblock_contact')]
#[Title('Unblock an address')]
#[Description('Lets a blocked address get email again (a bounce that was the receiver\'s fault, a block made by mistake); an opt-out, the person\'s own choice, stays. Then asks the provider whether it still holds the address on the sending domain\'s bounce, complaint or unsubscribe list: while it does, the provider drops mail to it, and taking it off there is a change at the provider the owner approves.')]
#[IsIdempotent]
#[IsOpenWorld]
#[IsDestructive]
class UnblockContactTool extends CampaignsTool
{
    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return ['email' => $schema->string()->required()];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->description('False when the address wasn\'t blocked.'),
            'subscriber' => Schemas::subscriberState($schema),
            'provider' => $schema->object([
                'checked' => $schema->boolean()->required()->description('False when the provider couldn\'t be asked.'),
                'lists' => $schema->array()->items($schema->object([
                    'list' => $schema->string()->enum(array_column(SuppressionType::cases(), 'value'))->required(),
                    'since' => $schema->string()->nullable()->required(),
                    'reason' => $schema->string()->nullable()->required(),
                ]))->required()->description('The provider\'s lists holding the address on the sending domain.'),
                'error' => $schema->string()->nullable()->required()->description('Why it couldn\'t be asked.'),
            ]),
            'notes' => $schema->array()->items($schema->string()),
        ];
    }

    public function perform(Request $request, UnblockAction $unblock, CheckProviderSuppressionsAction $check): ResponseFactory
    {
        $input = $request->validate(['email' => ['required', 'string']]);
        $subscriber = static::subscriber($input['email']);
        $changed = $unblock->execute($subscriber, static::agentContext());
        $notes = $changed ? ['Its count of temporary failures starts over at 0.'] : [];

        // Asked after the unblock has committed: a provider that can't be
        // read never undoes it, nor makes it look as if it failed.
        try {
            $lists = $check->execute($subscriber->email);
            $provider = [
                'checked' => true,
                'lists' => array_map(fn (Suppression $suppression): array => [
                    'list' => $suppression->type->value,
                    'since' => $suppression->since?->toIso8601String(),
                    'reason' => $suppression->reason,
                ], $lists),
                'error' => null,
            ];

            if ($lists !== []) {
                $notes[] = 'The provider still holds the address, so mail to it is dropped there until it is taken off the provider\'s list: a change at the provider that the owner approves.';
            }
        } catch (CampaignsException $exception) {
            $provider = ['checked' => false, 'lists' => [], 'error' => $exception->getMessage()];
            $notes[] = 'Couldn\'t check whether the provider still holds the address; the unblock stands.';
        }

        return Response::structured([
            'changed' => $changed,
            'subscriber' => SubscriberSummary::state($subscriber),
            'provider' => $provider,
            'notes' => $notes,
        ]);
    }
}
