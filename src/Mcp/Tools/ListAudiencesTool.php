<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
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
use Mozex\Campaigns\Contracts\Audience;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Support\ShownText;
use Throwable;

#[Name('list_audiences')]
#[Title('List audiences')]
#[Description('The audiences a campaign can narrow its lists to: the app\'s named audiences (people the app defines in code, such as its customers), each with what it holds and the JSON Schema of the parameters it takes, and the saved audiences (named sets of the built-in filters), '.ListAudiencesTool::PER_PAGE.' a page. A campaign names one in its audience setting: {"audience": {"name": ..., "parameters": {...}}} or {"saved_audience": key}.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListAudiencesTool extends CampaignsTool
{
    /**
     * A page of saved audiences at their longest measures about 15,000
     * characters, leaving the rest of the answer's limit to the named
     * audiences, whose parameters are the app's own code and aren't cut.
     */
    public const PER_PAGE = 5;

    /** The most named audiences shown, and how much of each one's description. */
    public const NAMED_LIMIT = 25;

    public const DESCRIPTION_LENGTH = 300;

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('The page of saved audiences, from 1 (the default).'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'named' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'parameters' => $schema->object()->required()->description('A JSON Schema of the parameters it takes.'),
            ]))->description('At most '.static::NAMED_LIMIT.', in the order the app lists them.'),
            'named_total' => $schema->integer(),
            'saved' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'description' => $schema->string()->nullable()->required(),
                'filters' => $schema->string()->required()->description('As JSON, cut to '.CampaignSummary::AUDIENCE_TEXT_LENGTH.' characters.'),
                'updated_at' => $schema->string()->nullable()->required(),
            ])),
            'page' => $schema->integer(),
            'pages' => $schema->integer(),
            'total' => $schema->integer()->description('How many saved audiences there are.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'page' => static::PAGE_RULES,
        ]);

        $page = (int) ($input['page'] ?? 1);
        $named = Campaigns::audiences();
        // PHP keeps a name like "7" as an integer key, which a slice would
        // renumber unless told to keep the keys.
        $shown = array_slice($named, 0, static::NAMED_LIMIT, true);
        $total = SavedAudience::query()->count();

        $saved = SavedAudience::query()
            ->orderBy('key')
            ->forPage($page, static::PER_PAGE)
            ->get()
            ->map(fn (SavedAudience $audience): array => [
                'key' => $audience->key,
                'name' => ShownText::of($audience->name, CampaignSummary::NAME_LENGTH),
                'description' => ShownText::of($audience->description, static::DESCRIPTION_LENGTH),
                'filters' => CampaignSummary::jsonText($audience->filters) ?? '{}',
                'updated_at' => $audience->updated_at?->toIso8601String(),
            ]);

        $described = [];

        foreach ($shown as $name => $audience) {
            // One audience whose description or parameters fail is reported
            // and left out, as a broken resource is: the rest still answer.
            try {
                $described[] = [
                    'name' => (string) $name,
                    'description' => (string) ShownText::of($audience->description(), static::DESCRIPTION_LENGTH),
                    'parameters' => static::parameters($audience),
                ];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return Response::structured([
            'named' => $described,
            'named_total' => count($named),
            'saved' => $saved->values()->all(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / static::PER_PAGE)),
            'total' => $total,
        ]);
    }

    /**
     * The audience's parameters as a JSON Schema, built as laravel/mcp
     * builds a tool's input schema, so an agent reads both the same way.
     *
     * @return array<string, mixed>
     */
    protected static function parameters(Audience $audience): array
    {
        $schema = JsonSchemaFactory::object($audience->parameters(...))->toArray();
        $schema['properties'] ??= (object) [];

        return $schema;
    }
}
