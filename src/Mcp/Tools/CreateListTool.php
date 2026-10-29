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
use Mozex\Campaigns\Actions\CreateListAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Reports\ListSummary;

#[Name('create_list')]
#[Title('Create a list')]
#[Description('Creates a static list for contacts added by hand or imported. Its key never changes. On a double opt-in list, someone added waits to confirm by email before they are mailed.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class CreateListTool extends CampaignsTool
{
    public const BASES = [ConsentBasis::Consent, ConsentBasis::SoftOptIn, ConsentBasis::Imported];

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('Lower-case letters, digits, dots, dashes and underscores; at most 125 characters.'),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->description('What the list is for and who is on it.'),
            'double_opt_in' => $schema->boolean()->description('Default false.'),
            'basis' => $schema->string()->enum(array_column(static::BASES, 'value'))->description('Why the app may email people on it, when whoever adds someone names no basis: consent (they asked; the default), soft_opt_in (customers offered a way out), imported (from another system).'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return ['list' => Schemas::list($schema)];
    }

    public function perform(Request $request, CreateListAction $create): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'double_opt_in' => ['nullable', 'boolean'],
            'basis' => ['nullable', 'string', 'in:'.implode(',', array_column(static::BASES, 'value'))],
        ]);

        $list = $create->execute(
            key: $input['key'],
            name: $input['name'],
            description: $input['description'] ?? null,
            doubleOptIn: (bool) ($input['double_opt_in'] ?? false),
            basis: ConsentBasis::from($input['basis'] ?? ConsentBasis::Consent->value),
        );

        return Response::structured(['list' => ListSummary::rows([$list], details: true)[0]]);
    }
}
