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
use Mozex\Campaigns\Audiences\AudienceDefinition;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\MailboxProvider;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Support\CampaignSettings;
use Mozex\Campaigns\Support\ShownText;

#[Name('preview_audience')]
#[Title('Preview an audience')]
#[Description('Who a campaign would reach, in counts only, never addresses: a campaign\'s own lists and audience, or lists and an audience not saved anywhere yet. recipients is the number a start freezes and asks to be confirmed; the dropped (blocked, then opted out, then sunset, never overlapping) and the recipients add up to the targeted. Broken down by list, language, engagement tier and mailbox provider.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class PreviewAudienceTool extends CampaignsTool
{
    /** The most lists and languages a breakdown names, the largest first. */
    public const BREAKDOWN_LIMIT = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign' => $schema->string()->description('A campaign\'s key: its own lists and audience. Give this, or lists.'),
            'lists' => $schema->array()->items($schema->string())->description('Target lists\' keys, for an audience not saved on a campaign.'),
            'audience' => $schema->object()->description('With lists. '.Schemas::AUDIENCE),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        $counts = function (array $keys) use ($schema): array {
            $fields = [];

            foreach ($keys as $key) {
                $fields[(string) $key] = $schema->integer()->required();
            }

            return $fields;
        };

        return [
            'recipients' => $schema->integer()->description('Who would be mailed.'),
            'targeted' => $schema->integer()->description('Everyone subscribed on the lists and in the audience.'),
            'dropped' => $schema->object([
                'blocked' => $schema->integer()->required(),
                'opted_out' => $schema->integer()->required(),
                'sunset' => $schema->integer()->required(),
            ]),
            'by_list' => $schema->object()->description('Recipients by list key (one on two lists counts in each), the '.static::BREAKDOWN_LIMIT.' largest.'),
            'by_list_total' => $schema->integer()->description('How many lists the breakdown has in all.'),
            'by_locale' => $schema->object()->description('Recipients by language ("none" for none set), the '.static::BREAKDOWN_LIMIT.' largest.'),
            'by_locale_total' => $schema->integer(),
            'by_tier' => $schema->object($counts(array_column(EngagementTier::cases(), 'value'))),
            'by_provider' => $schema->object($counts(array_column(MailboxProvider::cases(), 'value'))),
            'unmatched_emails' => $schema->integer()->description('Addresses the audience names that matched no subscriber.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'campaign' => ['nullable', 'string', 'required_without:lists', 'prohibits:lists,audience'],
            'lists' => ['nullable', 'array'],
            'audience' => ['nullable', 'array'],
        ]);

        $preview = static::audienceOf($input)->preview()->toArray();

        return Response::structured([
            ...$preview,
            'by_list' => static::largest($preview['by_list']),
            'by_list_total' => count($preview['by_list']),
            'by_locale' => static::largest($preview['by_locale']),
            'by_locale_total' => count($preview['by_locale']),
        ]);
    }

    /**
     * A campaign's audience, or one given here, checked as update_campaign
     * checks it.
     *
     * @param  array<string, mixed>  $input
     */
    protected static function audienceOf(array $input): CampaignAudience
    {
        if (isset($input['campaign'])) {
            return CampaignAudience::for(Campaign::forKey((string) $input['campaign']));
        }

        $columns = CampaignSettings::columns(['lists' => $input['lists'], 'audience' => $input['audience'] ?? null]);

        /** @var list<int> $lists */
        $lists = $columns['target_lists'];

        /** @var ?array<string, mixed> $audience */
        $audience = $columns['audience'];

        return new CampaignAudience($lists, AudienceDefinition::fromStored($audience));
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected static function largest(array $counts): object
    {
        arsort($counts);
        $shown = [];

        foreach (array_slice($counts, 0, static::BREAKDOWN_LIMIT, true) as $key => $count) {
            $shown[(string) ShownText::of((string) $key)] = $count;
        }

        return (object) $shown;
    }
}
