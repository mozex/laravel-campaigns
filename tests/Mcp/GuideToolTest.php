<?php

declare(strict_types=1);

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\Paginator as SimplePaginator;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\AgentResource;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Resources\GuideResource;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Mcp\Tools\GetGuideTool;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MemberDirectory;

/**
 * An app's resource whose every page is longer than an answer may be.
 */
class OversizedDirectory implements AgentResource
{
    public function description(): string
    {
        return 'Far too much at once.';
    }

    public function read(int $page): Paginator
    {
        return new SimplePaginator(array_fill(0, 60, str_repeat('x', 1000)), 60, $page);
    }
}

/**
 * An app's resource whose description throws (a setting or translation it
 * reads is missing).
 */
class BrokenDirectory implements AgentResource
{
    public function description(): string
    {
        throw new RuntimeException('The description failed.');
    }

    public function read(int $page): Paginator
    {
        return new SimplePaginator([], 10, $page);
    }
}

/**
 * An app's resource with a description of whatever length a test sets.
 */
class WordyDirectory implements AgentResource
{
    public static string $description = '';

    public function description(): string
    {
        return static::$description;
    }

    public function read(int $page): Paginator
    {
        return new SimplePaginator([], 10, $page);
    }
}

beforeEach(function (): void {
    config(['campaigns.resources' => ['members' => MemberDirectory::class, 'oversized' => OversizedDirectory::class]]);
});

/**
 * What a file of the package's guidance names in backticks the way a tool
 * is named (a verb, then words), leaving out the answer fields named so.
 *
 * @return list<string>
 */
function namedTools(string $file): array
{
    preg_match_all('/`((?:get|list|create|update|archive|add|remove|set|unsubscribe|block|unblock|preview|run|restore|send|start|pause|resume|slow|speed|cancel|duplicate|save)(?:_[a-z]+)+)`/', (string) file_get_contents($file), $matches);

    return array_values(array_diff(array_unique($matches[1]), ['start_error']));
}

/**
 * The names of the tools the server has.
 *
 * @return list<string>
 */
function serverTools(): array
{
    return array_map(fn (string $class): string => app($class)->name(), (fn (): array => $this->tools)->call(app(CampaignsServer::class, ['transport' => new FakeTransporter])));
}

it('gives the guide and the app\'s resources to a reader, and a resource a page at a time', function (): void {
    foreach (range(1, 5) as $n) {
        Member::query()->create(['email' => "member{$n}@example.org", 'name' => "Member {$n}"]);
    }

    $guide = structuredContent(agent(Rights::Read)->tool(GetGuideTool::class)->assertOk());
    $page = structuredContent(agent(Rights::Read, person: true)->tool(GetGuideTool::class, ['resource' => 'members', 'page' => 2])->assertOk());
    $last = structuredContent(agent(Rights::Read)->tool(GetGuideTool::class, ['resource' => 'members', 'page' => 3])->assertOk());

    expect($guide['guide'])->toBe(GuideResource::text())
        ->and($guide['resources'])->toBe([
            ['name' => 'members', 'description' => 'The members, by name.'],
            ['name' => 'oversized', 'description' => 'Far too much at once.'],
        ])
        ->and($guide['resources_total'])->toBe(2)
        ->and($guide['page'])->toBeNull()
        // A page comes alone, since the app sets its size.
        ->and($page['guide'])->toBeNull()
        ->and($page['resources'])->toBeNull()
        ->and($page['page'])->toBe([
            'resource' => 'members',
            'description' => 'The members, by name.',
            'page' => 2,
            'items' => [['name' => 'Member 3'], ['name' => 'Member 4']],
            'next_page' => 3,
        ])
        ->and($last['page']['items'])->toBe([['name' => 'Member 5']])
        ->and($last['page']['next_page'])->toBeNull();

    agent(Rights::Read)->tool(GetGuideTool::class, ['resource' => 'nope'])->assertHasErrors(['nope']);
    agent(Rights::Read)->tool(GetGuideTool::class, ['resource' => 'members', 'page' => 0])->assertHasErrors(['page']);
    // A page so large its offset would overflow is refused, not a crash.
    agent(Rights::Read)->tool(GetGuideTool::class, ['resource' => 'members', 'page' => PHP_INT_MAX])->assertHasErrors(['page']);

    // A client that sends "" for an argument it leaves out gets the guide.
    expect(structuredContent(agent(Rights::Read)->tool(GetGuideTool::class, ['resource' => '', 'page' => ''])->assertOk())['guide'])
        ->toBe(GuideResource::text());
});

it('leaves out and reports a resource whose description fails, the guide still answering', function (): void {
    Exceptions::fake();
    config(['campaigns.resources' => ['members' => MemberDirectory::class, 'broken' => BrokenDirectory::class]]);

    $guide = structuredContent(agent(Rights::Read)->tool(GetGuideTool::class)->assertOk());

    expect(array_column($guide['resources'], 'name'))->toBe(['members']);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The description failed.');
});

it('refuses and reports a resource page longer than an answer may be', function (): void {
    Exceptions::fake();

    agent(Rights::Read)->tool(GetGuideTool::class, ['resource' => 'oversized'])
        ->assertHasErrors(['A page of the oversized resource is too long to return']);

    Exceptions::assertReported(fn (CampaignsException $exception): bool => str_contains($exception->getMessage(), 'over the limit of '.CampaignsTool::RESULT_LIMIT));
});

it('keeps the guide beside the most resources it names under the answer limit', function (string $costly): void {
    // Resource names are the app's own config keys, so they stay plain; a
    // description is the app's text, shown cut, so it is filled with what
    // costs most as JSON.
    WordyDirectory::$description = str_repeat($costly, 1000);
    $resources = [];

    foreach (range(1, GetGuideTool::RESOURCES + 5) as $n) {
        $resources[str_pad((string) $n, 100, 'r')] = WordyDirectory::class;
    }

    config(['campaigns.resources' => $resources]);

    $response = agent(Rights::Read)->tool(GetGuideTool::class)->assertOk();
    $guide = structuredContent($response);

    expect($guide['resources'])->toHaveCount(GetGuideTool::RESOURCES)
        ->and($guide['resources_total'])->toBe(GetGuideTool::RESOURCES + 5)
        ->and(mb_strlen((string) json_encode($guide, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))->toBeLessThan(CampaignsTool::RESULT_LIMIT);
})->with('costly characters');

it('names in its guidance only tools the server has, and every tool in its guide', function (): void {
    $root = dirname(__DIR__, 2).'/resources/mcp/';
    $tools = serverTools();

    foreach (['instructions.md', 'guide.md'] as $file) {
        expect(array_values(array_diff(namedTools($root.$file), $tools)))->toBe([], "{$file} names a tool the server doesn't have.");
    }

    expect(array_values(array_diff($tools, namedTools($root.'guide.md'))))->toBe([], 'The guide never names these tools.');
});

it('leaves an app room for its own instructions', function (): void {
    config(['app.name' => str_repeat('A', 60), 'campaigns.mcp.instructions' => str_repeat('Only start campaigns from the office. ', 60)]);

    expect(mb_strlen(CampaignsServer::instructions()))->toBeLessThanOrEqual(CampaignsServer::INSTRUCTIONS_LIMIT)
        ->and(mb_strlen((string) config('campaigns.mcp.instructions')))->toBeGreaterThan(2000);
});
