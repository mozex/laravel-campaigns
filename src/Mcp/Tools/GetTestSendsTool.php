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
use Mozex\Campaigns\Actions\SendTestAction;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\ShownText;

#[Name('get_test_sends')]
#[Title('Get test emails')]
#[Description('Whether the test emails send_test queued went out: sent_at once the provider took one, failed_at and failure when it couldn\'t be sent. A test still waiting has neither; the queue sends it within moments.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetTestSendsTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tests' => $schema->array()->items($schema->string())->required()->description('The uuids send_test answered, at most '.SendTestAction::MAX.'.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'tests' => $schema->array()->items($schema->object([
                'uuid' => $schema->string()->required(),
                'email' => $schema->string()->required(),
                'locale' => $schema->string()->nullable()->required(),
                'campaign' => $schema->string()->nullable()->required()->description('The campaign\'s key; null for a template\'s test.'),
                'sent_at' => $schema->string()->nullable()->required(),
                'failed_at' => $schema->string()->nullable()->required(),
                'failure' => $schema->string()->nullable()->required(),
                'unsubscribed_at' => $schema->string()->nullable()->required()->description('When its unsubscribe link was used, which changed nothing else.'),
            ]))->description('The tests found, in the order asked; an unknown uuid is left out.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'tests' => ['required', 'array', 'list', 'max:'.SendTestAction::MAX],
            // Checked before the query: PostgreSQL refuses a malformed uuid.
            'tests.*' => ['string', 'uuid'],
        ]);

        /** @var list<string> $uuids */
        $uuids = $input['tests'];

        $tests = TestSend::query()->with('campaign:id,key')->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        return Response::structured([
            'tests' => collect($uuids)
                ->unique()
                ->map(fn (string $uuid): ?TestSend => $tests->get($uuid))
                ->filter()
                ->map(fn (TestSend $test): array => [
                    'uuid' => $test->uuid,
                    'email' => $test->email,
                    'locale' => $test->locale,
                    'campaign' => $test->campaign?->key,
                    'sent_at' => $test->sent_at?->toIso8601String(),
                    'failed_at' => $test->failed_at?->toIso8601String(),
                    'failure' => ShownText::of($test->failure),
                    'unsubscribed_at' => $test->unsubscribed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
