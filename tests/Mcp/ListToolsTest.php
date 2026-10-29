<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mcp\Tools\ArchiveListTool;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Mcp\Tools\CreateListTool;
use Mozex\Campaigns\Mcp\Tools\ListListsTool;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Mcp\Tools\UpdateListTool;
use Mozex\Campaigns\Models\CampaignList;

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

it('answers a list the tools can\'t change as a refusal', function (): void {
    CampaignList::factory()->dynamic()->create(['key' => 'members']);
    CampaignList::factory()->create(['key' => 'readers']);

    agent()->tool(CreateListTool::class, ['key' => 'readers', 'name' => 'Again'])->assertHasErrors(['A list with the key "readers" already exists.']);
    agent()->tool(UpdateListTool::class, ['key' => 'members', 'name' => 'Accounts'])->assertHasErrors(['can\'t be renamed or archived']);
    agent()->tool(ArchiveListTool::class, ['key' => 'nope'])->assertHasErrors(['No list has the key "nope".']);
    agent()->tool(CreateListTool::class, ['key' => 'x', 'name' => 'X', 'basis' => 'legacy'])->assertHasErrors(['basis']);
});

it('pages the lists so the longest page stays under the limit', function (): void {
    CampaignList::factory()->count(30)->sequence(fn (Sequence $sequence): array => [
        'key' => str_pad((string) $sequence->index, 125, 'k', STR_PAD_LEFT),
        'name' => str_repeat('n', 255),
        'description' => str_repeat('d', 5000),
    ])->create();

    $first = agent(Rights::Read)->tool(ListListsTool::class)->assertOk();
    $second = structuredContent(agent(Rights::Read)->tool(ListListsTool::class, ['page' => 2])->assertOk());

    expect(mb_strlen((string) json_encode(structuredContent($first))))->toBeLessThan(CampaignsTool::RESULT_LIMIT)
        ->and(structuredContent($first))->pages->toBe(2)->total->toBe(30)
        ->and(structuredContent($first)['lists'])->toHaveCount(25)
        ->and(structuredContent($first)['lists'][0]['description'])->toBe(str_repeat('d', 300).'...')
        ->and($second['lists'])->toHaveCount(5);
});
