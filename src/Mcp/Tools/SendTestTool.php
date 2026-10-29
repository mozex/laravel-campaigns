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
use Mozex\Campaigns\Actions\SendTestAction;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Models\TestSend;

#[Name('send_test')]
#[Title('Send a test email')]
#[Description('Queues test emails of a template\'s or a campaign\'s email to the app\'s test addresses (campaigns.test_addresses, which the overview lists) and nowhere else: one per address and language, at most '.SendTestAction::MAX.' a call, in the default language unless locales are given. A draft\'s test is its current email; a started campaign\'s is the frozen one it sends, UTM tags and all. Tests carry their own tag and never count in a campaign\'s numbers, and their links open the real pages while changing nothing. Read whether each went out with get_test_sends. Refused while sending is switched off.')]
#[IsOpenWorld]
#[IsDestructive(false)]
class SendTestTool extends CampaignsTool
{
    use NamesEmailOwner;

    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...static::ownerInput($schema),
            'addresses' => $schema->array()->items($schema->string())->description('Some of the test addresses; all of them by default.'),
            'locales' => $schema->array()->items($schema->string())->description('The languages to send, like ["en", "fr"]; the default language by default.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'tests' => $schema->array()->items($schema->object([
                'uuid' => $schema->string()->required(),
                'email' => $schema->string()->required(),
                'locale' => $schema->string()->nullable()->required(),
            ]))->description('One per email queued; pass their uuids to get_test_sends.'),
        ];
    }

    public function perform(Request $request, SendTestAction $send): ResponseFactory
    {
        $input = $request->validate([
            ...static::ownerRules(),
            'addresses' => ['nullable', 'array', 'list', 'max:'.SendTestAction::MAX],
            'addresses.*' => ['string'],
            'locales' => ['nullable', 'array', 'list', 'max:'.SendTestAction::MAX],
            'locales.*' => ['string'],
        ]);

        $tests = $send->execute(static::owner($input), $input['addresses'] ?? null, $input['locales'] ?? null);

        return Response::structured([
            'tests' => array_map(fn (TestSend $test): array => [
                'uuid' => $test->uuid,
                'email' => $test->email,
                'locale' => $test->locale,
            ], $tests),
        ]);
    }
}
