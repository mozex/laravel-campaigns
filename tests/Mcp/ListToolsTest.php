<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mcp\Tools\ArchiveListTool;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Mcp\Tools\CreateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\CreateListTool;
use Mozex\Campaigns\Mcp\Tools\ListListsTool;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Mcp\Tools\UpdateListTool;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Reports\ListActivity;
use Mozex\Campaigns\Reports\ListSummary;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MemberList;

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

it('shows how each list fills and the campaign that last used it', function (string $driver): void {
    useDatabase($driver);
    $this->travelTo('2026-11-23 12:00:00Z');

    // The dynamic list fills from its records, each joining once: one
    // leaving and coming back writes no second join.
    Member::query()->create(['email' => 'jane@example.com']);
    $leaver = Member::query()->create(['email' => 'joe@example.com']);
    $this->travel(1)->hours();
    $leaver->update(['banned_at' => now()]);
    $leaver->update(['banned_at' => null]);
    $members = CampaignList::forKey('members');

    $news = CampaignList::factory()->create(['key' => 'news']);
    $events = CampaignList::factory()->create(['key' => 'events']);
    $quiet = CampaignList::factory()->create(['key' => 'quiet']);
    $subscriber = Subscriber::factory()->create();

    $record = fn (CampaignList $list, ?string $source, Carbon $at, ConsentAction $action = ConsentAction::Subscribe): ConsentRecord => ConsentRecord::factory()->create([
        'subscriber_id' => $subscriber->id,
        'list_id' => $list->id,
        'action' => $action,
        'source' => $source,
        'occurred_at' => $at,
    ]);

    // Thirteen sources: the ten largest show, ties by name with no source
    // last, and the last three count as other.
    foreach (['registration' => 4, 'checkout' => 4, 'agent' => 3, 'staff' => 3, '' => 3] as $source => $count) {
        foreach (range(1, $count) as $ignored) {
            $record($news, $source === '' ? null : $source, now()->subDays(2));
        }
    }

    foreach (['form-a', 'form-b', 'form-c', 'form-d', 'form-e', 'form-f', 'form-g', 'form-h'] as $source) {
        $record($news, $source, now()->subDays(3));
    }

    // Thirty days back is in; a second further isn't.
    $record($news, 'registration', now()->subDays(30));
    $record($news, 'registration', now()->subDays(30)->subSecond());
    $record($news, 'checkout', now()->subMinutes(5));

    foreach ([ConsentAction::Confirm, ConsentAction::Decline, ConsentAction::Unsubscribe, ConsentAction::OptOut] as $action) {
        $record($news, 'registration', now(), $action);
    }

    $record($events, 'registration', now()->subDays(1));
    $record($quiet, 'import', now()->subDays(40));

    Campaign::factory()->create(['key' => 'spring', 'status' => CampaignStatus::Completed, 'target_lists' => [$members->id, $news->id], 'started_at' => now()->subDays(5)]);
    Campaign::factory()->create(['key' => 'summer', 'status' => CampaignStatus::Completed, 'target_lists' => [$members->id], 'started_at' => now()->subDay()]);
    // Neither a campaign that hasn't started nor one whose list ids merely
    // contain the list's id in their digits used it.
    Campaign::factory()->create(['key' => 'draft', 'target_lists' => [$news->id]]);
    Campaign::factory()->create(['key' => 'decoy', 'status' => CampaignStatus::Completed, 'target_lists' => [$news->id * 10, $news->id * 10 + 1, $news->id + 100], 'started_at' => now()]);

    $lists = collect(structuredContent(agent(Rights::Read)->tool(ListListsTool::class)->assertOk())['lists'])
        ->mapWithKeys(fn (array $list): array => [$list['key'] => Arr::only($list, ['records', 'recent_joins', 'last_joined_at', 'last_campaign'])])
        ->all();

    expect(array_keys($lists))->toBe(['events', 'members', 'news', 'quiet'])
        ->and($lists['members'])->toBe([
            'records' => Member::class,
            'recent_joins' => ['total' => 2, 'by_source' => [['source' => 'staff', 'count' => 2]], 'other' => 0],
            'last_joined_at' => '2026-11-23T12:00:00+00:00',
            'last_campaign' => ['key' => 'summer', 'started_at' => '2026-11-22T13:00:00+00:00'],
        ])
        ->and($lists['news'])->toBe([
            'records' => null,
            'recent_joins' => [
                'total' => 27,
                'by_source' => [
                    ['source' => 'checkout', 'count' => 5],
                    ['source' => 'registration', 'count' => 5],
                    ['source' => 'agent', 'count' => 3],
                    ['source' => 'staff', 'count' => 3],
                    ['source' => null, 'count' => 3],
                    ['source' => 'form-a', 'count' => 1],
                    ['source' => 'form-b', 'count' => 1],
                    ['source' => 'form-c', 'count' => 1],
                    ['source' => 'form-d', 'count' => 1],
                    ['source' => 'form-e', 'count' => 1],
                ],
                'other' => 3,
            ],
            'last_joined_at' => '2026-11-23T12:55:00+00:00',
            'last_campaign' => ['key' => 'spring', 'started_at' => '2026-11-18T13:00:00+00:00'],
        ])
        ->and($lists['events'])->toBe([
            'records' => null,
            'recent_joins' => ['total' => 1, 'by_source' => [['source' => 'registration', 'count' => 1]], 'other' => 0],
            'last_joined_at' => '2026-11-22T13:00:00+00:00',
            'last_campaign' => null,
        ])
        ->and($lists['quiet'])->toBe([
            'records' => null,
            'recent_joins' => ['total' => 0, 'by_source' => [], 'other' => 0],
            'last_joined_at' => '2026-10-14T13:00:00+00:00',
            'last_campaign' => null,
        ]);
})->with('databases');

it('shows no records for a dynamic list whose source is gone, and reports one that breaks', function (): void {
    CampaignList::factory()->dynamic()->create(['key' => 'members']);
    CampaignList::factory()->dynamic()->create(['key' => 'retired']);
    config()->set('campaigns.list_sources', ['members' => 'not-a-class']);
    Exceptions::fake();

    $lists = array_column(structuredContent(agent(Rights::Read)->tool(ListListsTool::class)->assertOk())['lists'], 'records', 'key');

    expect($lists)->toBe(['members' => null, 'retired' => null]);
    Exceptions::assertReportedCount(1);
});

it('answers a list the tools can\'t change as a refusal', function (): void {
    CampaignList::factory()->dynamic()->create(['key' => 'members']);
    CampaignList::factory()->create(['key' => 'readers']);

    agent()->tool(CreateListTool::class, ['key' => 'readers', 'name' => 'Again'])->assertHasErrors(['A list with the key "readers" already exists.']);
    agent()->tool(UpdateListTool::class, ['key' => 'members', 'name' => 'Accounts'])->assertHasErrors(['can\'t be renamed, described or archived']);
    agent()->tool(ArchiveListTool::class, ['key' => 'nope'])->assertHasErrors(['No list has the key "nope".']);
    agent()->tool(CreateListTool::class, ['key' => 'x', 'name' => 'X', 'basis' => 'legacy'])->assertHasErrors(['basis']);
});

it('pages the lists so the longest page stays under the limit', function (string $character): void {
    // Measured as JSON: a quote takes two characters, a control character
    // six. Every list is dynamic, its records' type, its sources and its
    // last campaign's key as long as they come.
    $count = ListListsTool::PER_PAGE + 5;

    CampaignList::factory()->dynamic()->count($count)->sequence(fn (Sequence $sequence): array => [
        'key' => str_pad((string) $sequence->index, 125, 'k', STR_PAD_LEFT),
        'name' => mb_substr(str_repeat($character, 255), 0, 255),
        'description' => str_repeat($character, 5000),
    ])->create();

    $lists = CampaignList::query()->orderBy('key')->get();
    $model = new class extends Member
    {
        public string $type = '';

        public function getMorphClass(): string
        {
            return $this->type;
        }
    };
    $model->type = str_repeat($character, 200);

    app()->instance('costly-list-source', new class($model) extends MemberList
    {
        public function __construct(private readonly Member $model) {}

        public function query(): Builder
        {
            return $this->model->newQuery();
        }
    });
    config()->set('campaigns.list_sources', $lists->mapWithKeys(fn (CampaignList $list): array => [$list->key => 'costly-list-source'])->all());

    $subscriber = Subscriber::factory()->create();

    ConsentRecord::query()->insert($lists->flatMap(fn (CampaignList $list): array => array_map(fn (int $source): array => [
        'subscriber_id' => $subscriber->id,
        'list_id' => $list->id,
        'action' => ConsentAction::Subscribe->value,
        'source' => mb_substr(str_repeat($character, 124), 0, 124).chr(97 + $source),
        'occurred_at' => now(),
    ], range(0, ListActivity::SOURCES)))->all());

    Campaign::factory()->count($count)->sequence(fn (Sequence $sequence): array => [
        'key' => str_pad((string) $sequence->index, 125, 'c', STR_PAD_LEFT),
        'status' => CampaignStatus::Completed,
        'target_lists' => [$lists[$sequence->index]->id],
        'started_at' => now(),
    ])->create();

    $first = structuredContent(agent(Rights::Read)->tool(ListListsTool::class)->assertOk());
    $second = structuredContent(agent(Rights::Read)->tool(ListListsTool::class, ['page' => 2])->assertOk());
    $length = mb_strlen((string) json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    expect($first)->pages->toBe(2)->total->toBe($count)
        ->and($first['lists'])->toHaveCount(ListListsTool::PER_PAGE)
        ->and(mb_strlen($first['lists'][0]['description']))->toBe(ListSummary::DESCRIPTION_LENGTH + 3)
        ->and($first['lists'][0]['recent_joins'])->total->toBe(ListActivity::SOURCES + 1)->other->toBe(1)
        ->and($first['lists'][0]['recent_joins']['by_source'])->toHaveCount(ListActivity::SOURCES)
        ->and(mb_strlen($first['lists'][0]['recent_joins']['by_source'][0]['source']))->toBe(ListActivity::SOURCE_LENGTH + 3)
        ->and(mb_strlen($first['lists'][0]['records']))->toBe(ListActivity::RECORDS_LENGTH + 3)
        ->and($first['lists'][0]['last_campaign']['key'])->toHaveLength(125)
        ->and(json_encode($first))->not->toContain('\u0001')
        ->and($length)->toBeLessThan(CampaignsTool::RESULT_LIMIT)
        ->and($second['lists'])->toHaveCount(5);

    // Bounded, so a page whose offset would overflow is refused, not a crash.
    agent(Rights::Read)->tool(ListListsTool::class, ['page' => PHP_INT_MAX])->assertHasErrors(['page']);
})->with('costly characters');
