<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mcp\Tools\ArchiveListTool;
use Mozex\Campaigns\Mcp\Tools\CreateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\CreateListTool;
use Mozex\Campaigns\Mcp\Tools\ListListsTool;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Mcp\Tools\UpdateListTool;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Reports\ListSummary;

it('creates, renames, archives and restores a list, which the lists and the overview follow', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();
    CampaignList::factory()->dynamic()->create(['key' => 'members', 'name' => 'Members']);

    $created = structuredContent(agent()->tool(CreateListTool::class, [
        'key' => 'events',
        'name' => 'Event contacts',
        'description' => 'People met at events.',
        'double_opt_in' => true,
    ])->assertOk());

    subscriberOn([CampaignList::forKey('events')], status: SubscriptionStatus::Pending);

    agent(person: true)->tool(UpdateListTool::class, ['key' => 'events', 'name' => 'Expo contacts'])->assertOk();
    $archived = structuredContent(agent()->tool(ArchiveListTool::class, ['key' => 'events'])->assertOk());

    $listed = fn (array $arguments = []): array => array_column(structuredContent(agent(Rights::Read)->tool(ListListsTool::class, $arguments)->assertOk())['lists'], 'archived_at', 'key');
    $overview = array_column(structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['lists'], 'key');
    $whileArchived = [$listed(), $listed(['include_archived' => true]), $overview];

    $restored = structuredContent(agent()->tool(ArchiveListTool::class, ['key' => 'events', 'archived' => false])->assertOk());

    expect($created['list'])->toBe([
        'key' => 'events',
        'name' => 'Event contacts',
        'description' => 'People met at events.',
        'kind' => 'static',
        'is_default' => false,
        'double_opt_in' => true,
        'default_basis' => 'consent',
        'pending' => 0,
        'subscribed' => 0,
        'unsubscribed' => 0,
        'mailable' => 0,
        'tiers' => ['engaged' => 0, 'active' => 0, 'silent' => 0],
        'created_at' => now()->toIso8601String(),
        'archived_at' => null,
    ])
        ->and($archived['changed'])->toBeTrue()
        ->and($archived['list'])->name->toBe('Expo contacts')->pending->toBe(1)->archived_at->toBe(now()->toIso8601String())
        ->and($whileArchived)->toBe([
            ['members' => null],
            ['events' => now()->toIso8601String(), 'members' => null],
            ['members'],
        ])
        ->and($restored)->changed->toBeTrue()
        ->and($restored['list']['archived_at'])->toBeNull()
        ->and($listed())->toBe(['events' => null, 'members' => null]);
})->with('databases');

it('marks lists default, a dynamic one included, which the lists show first and a campaign made without lists goes to', function (string $driver): void {
    useDatabase($driver);
    sendFromExample();
    CampaignList::factory()->dynamic()->create(['key' => 'members', 'name' => 'Members']);

    $created = structuredContent(agent()->tool(CreateListTool::class, ['key' => 'news', 'name' => 'News', 'is_default' => true])->assertOk());
    agent()->tool(CreateListTool::class, ['key' => 'alpha', 'name' => 'Alpha'])->assertOk();
    $marked = structuredContent(agent(person: true)->tool(UpdateListTool::class, ['key' => 'members', 'is_default' => true])->assertOk());

    $listed = fn (): array => array_column(structuredContent(agent(Rights::Read)->tool(ListListsTool::class)->assertOk())['lists'], 'is_default', 'key');
    $overview = array_column(structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['lists'], 'is_default', 'key');
    $campaign = structuredContent(agent()->tool(CreateCampaignTool::class, ['key' => 'march', 'name' => 'March', 'template' => 'starter'])->assertOk())['campaign'];
    $whileMarked = $listed();

    // False is a value, not a blank the server drops.
    $unmarked = structuredContent(agent()->tool(UpdateListTool::class, ['key' => 'news', 'is_default' => false])->assertOk());
    agent()->tool(ArchiveListTool::class, ['key' => 'alpha'])->assertOk();

    expect($created['list']['is_default'])->toBeTrue()
        ->and($marked['list'])->kind->toBe('dynamic')->is_default->toBeTrue()
        ->and(array_keys($whileMarked))->toBe(['members', 'news', 'alpha'])
        ->and($whileMarked)->toBe(['members' => true, 'news' => true, 'alpha' => false])
        ->and($overview)->toBe(['members' => true, 'news' => true, 'alpha' => false])
        ->and($campaign)->lists->toBe(['members', 'news'])->lists_total->toBe(2)
        ->and($unmarked['list']['is_default'])->toBeFalse()
        ->and($listed())->toBe(['members' => true, 'news' => false])
        ->and(Campaign::forKey('march')->target_lists)->toHaveCount(2);

    agent()->tool(UpdateListTool::class, ['key' => 'members', 'name' => 'Accounts', 'is_default' => false])->assertHasErrors(['can\'t be renamed, described or archived']);
    agent()->tool(UpdateListTool::class, ['key' => 'alpha', 'is_default' => true])->assertHasErrors(['The list "alpha" is archived, so it can\'t be a default list: restore it first.']);
    agent(Rights::Read)->tool(UpdateListTool::class, ['key' => 'news', 'is_default' => true])->assertNotRegistered();

    expect(CampaignList::defaultKeys())->toBe(['members']);
})->with('databases');

it('answers a list the tools can\'t change as a refusal', function (): void {
    CampaignList::factory()->dynamic()->create(['key' => 'members']);
    CampaignList::factory()->create(['key' => 'readers']);

    agent()->tool(CreateListTool::class, ['key' => 'readers', 'name' => 'Again'])->assertHasErrors(['A list with the key "readers" already exists.']);
    agent()->tool(UpdateListTool::class, ['key' => 'members', 'name' => 'Accounts'])->assertHasErrors(['can\'t be renamed, described or archived']);
    agent()->tool(ArchiveListTool::class, ['key' => 'nope'])->assertHasErrors(['No list has the key "nope".']);
    agent()->tool(CreateListTool::class, ['key' => 'x', 'name' => 'X', 'basis' => 'legacy'])->assertHasErrors(['basis']);
});

it('pages the lists so the longest page stays under the limit', function (string $character): void {
    // Measured as JSON: a quote takes two characters, a control character six.
    CampaignList::factory()->count(30)->sequence(fn (Sequence $sequence): array => [
        'key' => str_pad((string) $sequence->index, 125, 'k', STR_PAD_LEFT),
        'name' => mb_substr(str_repeat($character, 255), 0, 255),
        'description' => str_repeat($character, 5000),
    ])->create();

    $first = structuredContent(agent(Rights::Read)->tool(ListListsTool::class)->assertOk());
    $second = structuredContent(agent(Rights::Read)->tool(ListListsTool::class, ['page' => 2])->assertOk());

    expect($first)->pages->toBe(2)->total->toBe(30)
        ->and($first['lists'])->toHaveCount(ListListsTool::PER_PAGE)
        ->and(mb_strlen($first['lists'][0]['description']))->toBe(ListSummary::DESCRIPTION_LENGTH + 3)
        ->and(json_encode($first))->not->toContain('\u0001')
        ->and($second['lists'])->toHaveCount(5);

    // Bounded, so a page whose offset would overflow is refused, not a crash.
    agent(Rights::Read)->tool(ListListsTool::class, ['page' => PHP_INT_MAX])->assertHasErrors(['page']);
})->with('costly characters');
