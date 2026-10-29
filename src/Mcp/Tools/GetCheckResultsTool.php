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
use Mozex\Campaigns\Checks\PreSendChecks;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Reports\CheckSummary;

#[Name('get_check_results')]
#[Title('Read an email\'s check results')]
#[Description('The pre-send checks run_checks queued on a template\'s or a campaign\'s email (its current revision, or the one given): status queued while they run, then each check\'s result, '.GetCheckResultsTool::PER_PAGE.' a page, with how many problems it lists. Name a check (and a locale, for one run per language) to page through its problems: the broken links, the images, the spam rules or the missing languages.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetCheckResultsTool extends CampaignsTool
{
    use NamesEmailOwner;

    public const PER_PAGE = 25;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...static::ownerInput($schema),
            'revision' => $schema->integer()->min(1)->description('A revision\'s number; the current one by default.'),
            'check' => $schema->string()->enum(PreSendChecks::CHECKS)->description('List this check\'s problems instead of every check.'),
            'locale' => $schema->string()->description('With check: only this language\'s.'),
            'page' => $schema->integer()->min(1)->description('The page, from 1 (the default).'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'revision' => $schema->integer(),
            'run' => Schemas::checkStatus($schema),
            'checks' => $schema->array()->items($schema->object([
                'check' => $schema->string()->required(),
                'locale' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->enum(['passed', 'failed', 'skipped'])->required(),
                'message' => $schema->string()->required(),
                'items' => $schema->integer()->required()->description('How many problems it lists.'),
                'score' => $schema->number()->nullable()->required()->description('The spam score.'),
                'bytes' => $schema->integer()->nullable()->required()->description('The size, with tracked links.'),
            ]))->description('Without check: every check.'),
            'items' => $schema->array()->items($schema->object([
                'locale' => $schema->string(),
                'url' => $schema->string()->description('Cut to '.CheckSummary::URL_LENGTH.' characters.'),
                'problem' => $schema->string(),
                'locales' => $schema->array()->items($schema->string())->description('The languages whose version holds the link.'),
                'score' => $schema->number(),
                'description' => $schema->string(),
            ]))->description('With check: its problems.'),
            'page' => $schema->integer(),
            'pages' => $schema->integer(),
            'total' => $schema->integer(),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            ...static::ownerRules(),
            'revision' => static::revisionRules(),
            'check' => ['nullable', 'string', 'in:'.implode(',', PreSendChecks::CHECKS)],
            'locale' => ['nullable', 'string', 'max:35'],
            'page' => static::PAGE_RULES,
        ]);

        $revision = static::revision(static::owner($input), isset($input['revision']) ? (int) $input['revision'] : null);
        $page = (int) ($input['page'] ?? 1);
        $field = isset($input['check']) ? 'items' : 'checks';

        $listed = isset($input['check'])
            ? CheckSummary::items($revision, $input['check'], $input['locale'] ?? null)
            : CheckSummary::checks($revision);

        return Response::structured([
            'revision' => $revision->number,
            'run' => CheckSummary::status($revision),
            $field => array_slice($listed, ($page - 1) * static::PER_PAGE, static::PER_PAGE),
            'page' => $page,
            'pages' => max(1, (int) ceil(count($listed) / static::PER_PAGE)),
            'total' => count($listed),
        ]);
    }
}
