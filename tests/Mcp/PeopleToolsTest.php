<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Mozex\Campaigns\Actions\SetCustomFieldsAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Mcp\Tools\AddContactsTool;
use Mozex\Campaigns\Mcp\Tools\BlockContactTool;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Mcp\Tools\GetSubscriberTool;
use Mozex\Campaigns\Mcp\Tools\ListSubscribersTool;
use Mozex\Campaigns\Mcp\Tools\RemoveContactsTool;
use Mozex\Campaigns\Mcp\Tools\SetContactFieldsTool;
use Mozex\Campaigns\Mcp\Tools\UnblockContactTool;
use Mozex\Campaigns\Mcp\Tools\UnsubscribeContactTool;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Reports\SubscriberSummary;
use Mozex\Campaigns\Sending\Suppression;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\Keys;

it('adds contacts to a list and takes them off again, recording who did it', function (): void {
    $list = CampaignList::factory()->doubleOptIn()->create(['key' => 'events']);
    $contacts = [['email' => 'ann@example.com', 'name' => 'Ann'], ['email' => 'bob@example.com'], ...array_fill(0, 60, ['email' => 'not an address'])];

    $added = structuredContent(agent()->tool(AddContactsTool::class, ['list' => 'events', 'basis' => 'consent', 'contacts' => $contacts])->assertOk());
    $removed = structuredContent(agent(person: true)->tool(RemoveContactsTool::class, ['list' => 'events', 'emails' => ['bob@example.com', 'carol@example.com']])->assertOk());

    expect($added)
        ->dry_run->toBeFalse()
        ->rows->toBe(62)
        ->waiting_to_confirm->toBe(2)
        ->problems_total->toBe(60)
        ->and($added['counts'])->created->toBe(2)->joined->toBe(2)
        ->and($added['problems'])->toHaveCount(50)
        ->and($added['problems'][0])->toBe('Row 3: it has no email address that can be mailed.')
        ->and($removed)->toBe(['removed' => 1, 'not_on_list' => 0, 'not_found' => 1, 'invalid' => 0])
        ->and($list->subscribers()->pluck('email')->all())->toBe(['ann@example.com'])
        ->and(ConsentRecord::query()->orderBy('id')->get()->map(fn (ConsentRecord $record): array => [$record->action, $record->source, $record->meta, $record->ip_address, $record->user_agent])->all())->toBe([
            [ConsentAction::Subscribe, 'agent', ['by' => 'laptop'], null, null],
            [ConsentAction::Subscribe, 'agent', ['by' => 'laptop'], null, null],
            [ConsentAction::Unsubscribe, 'agent', ['by' => 'Ann Agent'], null, null],
        ]);

    agent()->tool(AddContactsTool::class, ['list' => 'events', 'basis' => 'consent', 'contacts' => [['email' => 'x@example.com', 'blocked' => 'manual']]])
        ->assertHasErrors(['can only carry email, name, first_name, locale, timezone, country, custom_fields, not blocked']);
    agent()->tool(AddContactsTool::class, ['list' => 'events', 'basis' => 'legacy', 'contacts' => [['email' => 'x@example.com']]])->assertHasErrors(['basis']);
});

it('sets and removes a contact\'s custom fields', function (): void {
    Subscriber::factory()->create(['email' => 'ann@example.com', 'custom_fields' => ['plan' => 'pro', 'old' => 1]]);

    $answer = structuredContent(agent()->tool(SetContactFieldsTool::class, ['email' => 'Ann@example.com', 'fields' => ['old' => null, 'seats' => 5, 'notes' => str_repeat('n', 1000)]])->assertOk());

    expect($answer['custom_fields'])->toBe(['plan' => 'pro', 'seats' => 5, 'notes' => str_repeat('n', SubscriberSummary::FIELD_LENGTH).'...'])
        ->and(Subscriber::findByEmail('ann@example.com')->custom_fields)->toBe(['plan' => 'pro', 'seats' => 5, 'notes' => str_repeat('n', 1000)]);

    agent()->tool(SetContactFieldsTool::class, ['email' => 'ann@example.com', 'fields' => ['bad name' => 1]])->assertHasErrors(['custom field names']);
    agent()->tool(SetContactFieldsTool::class, ['email' => 'nobody@example.com', 'fields' => ['a' => 1]])->assertHasErrors(['No subscriber has that address.']);
});

it('lists subscribers a page at a time, narrowed by the filters', function (string $driver): void {
    useDatabase($driver);
    $readers = CampaignList::factory()->create(['key' => 'readers']);
    $other = CampaignList::factory()->create(['key' => 'other']);

    foreach (['a', 'b', 'c', 'd'] as $name) {
        subscriberOn([$readers, $other], ['email' => "{$name}@example.com"]);
    }

    subscriberOn([$readers], ['email' => 'out@example.com', 'opted_out_at' => now()]);
    subscriberOn([$readers], ['email' => 'pending@example.com'], SubscriptionStatus::Pending);

    $filters = ['lists' => ['readers'], 'opted_out' => false];
    $first = structuredContent(agent(Rights::Read)->tool(ListSubscribersTool::class, ['filters' => $filters, 'per_page' => 3])->assertOk());
    $second = structuredContent(agent(Rights::Read, person: true)->tool(ListSubscribersTool::class, ['filters' => $filters, 'per_page' => 3, 'cursor' => $first['next_cursor']])->assertOk());

    expect(array_column($first['subscribers'], 'email'))->toBe(['a@example.com', 'b@example.com', 'c@example.com'])
        ->and($first['total'])->toBe(4)
        ->and($first['subscribers'][0])
        ->mailable->toBeTrue()
        ->lists->toBe([['key' => 'readers', 'status' => 'subscribed'], ['key' => 'other', 'status' => 'subscribed']])
        ->lists_total->toBe(2)
        ->and(array_column($second['subscribers'], 'email'))->toBe(['d@example.com'])
        ->and($second['next_cursor'])->toBeNull();

    agent(Rights::Read)->tool(ListSubscribersTool::class, ['filters' => ['segment' => 'vip']])->assertHasErrors(['there is no such filter']);
    agent(Rights::Read)->tool(ListSubscribersTool::class, ['filters' => ['lists' => ['nope']]])->assertHasErrors(['No list has the key "nope".']);
})->with('databases');

it('never echoes an agent\'s input past a short refusal', function (): void {
    $long = str_repeat('x', 60000);
    $unknownFilter = structuredContent(agent(Rights::Read)->tool(ListSubscribersTool::class, ['filters' => [$long => 1]]));

    expect(mb_strlen($unknownFilter['error']))->toBeLessThan(500)
        ->and(mb_strlen(CampaignsTool::refused($long)->getStructuredContent()['error'] ?? ''))->toBe(CampaignsTool::REFUSAL_LENGTH);
});

/**
 * Lists with the longest keys a list may have.
 *
 * @return Collection<int, CampaignList>
 */
function longestLists(int $count): Collection
{
    return CampaignList::factory()->count($count)->sequence(fn (Sequence $sequence): array => [
        'key' => str_pad((string) $sequence->index, Keys::MAX_LENGTH, 'k', STR_PAD_LEFT),
    ])->create();
}

/**
 * A subscriber with every column an answer shows at its longest, on every
 * list given, free text made of quotes, which JSON writes as two
 * characters each.
 *
 * @param  Collection<int, CampaignList>  $lists
 */
function longestSubscriber(int $number, Collection $lists): Subscriber
{
    $subscriber = Subscriber::factory()->create([
        'email' => str_pad((string) $number, 254 - strlen('@example.com'), 'a', STR_PAD_LEFT).'@example.com',
        'name' => str_repeat('"', 255),
        'first_name' => str_repeat('"', 255),
        'locale' => str_repeat('l', 35),
        'timezone' => str_repeat('"', 64),
        'country' => 'TR',
        'last_opened_at' => now(),
        'last_clicked_at' => now(),
        'last_active_at' => now(),
        'first_mailed_at' => now(),
        'opted_out_at' => now(),
        'blocked_at' => now(),
        'blocked_reason' => BlockReason::SoftBounces,
        'confirmation_sent_at' => now(),
    ]);

    Subscription::query()->insert($lists->map(fn (CampaignList $list): array => [
        'list_id' => $list->id,
        'subscriber_id' => $subscriber->id,
        'status' => SubscriptionStatus::Subscribed->value,
        'basis' => ConsentBasis::SoftOptIn->value,
        'source' => str_repeat('"', 125),
        'subscribed_at' => now(),
        'confirmed_at' => now(),
    ])->all());

    return $subscriber;
}

it('answers the default page of subscribers at its longest, and refuses a longer page rather than cutting it', function (): void {
    Exceptions::fake();
    $lists = longestLists(8);

    foreach (range(1, 50) as $number) {
        longestSubscriber($number, $lists);
    }

    agent(Rights::Read)->tool(ListSubscribersTool::class, ['per_page' => 50])->assertHasErrors(['Ask for less at a time']);
    $default = structuredContent(agent(Rights::Read)->tool(ListSubscribersTool::class)->assertOk());

    expect($default['subscribers'])->toHaveCount(ListSubscribersTool::PER_PAGE)
        ->and($default['subscribers'][0]['lists'])->toHaveCount(SubscriberSummary::ROW_LISTS)
        ->and($default['subscribers'][0]['name'])->toBe(str_repeat('"', SubscriberSummary::NAME_LENGTH).'...');
    Exceptions::assertReported(CampaignsException::class);
});

it('looks one address up whole, with its consent history but never its IP address', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();
    $list = CampaignList::factory()->create(['key' => 'readers']);
    $subscriber = subscriberOn([$list], ['email' => 'ann@example.com', 'name' => 'Ann', 'custom_fields' => ['plan' => 'pro']]);
    ConsentRecord::record($subscriber, ConsentAction::Subscribe, new ConsentContext('signup', ipAddress: '203.0.113.9', userAgent: 'Firefox', meta: ['form' => 'footer']), $list);

    $answer = structuredContent(agent(Rights::Read)->tool(GetSubscriberTool::class, ['email' => ' ANN@example.com '])->assertOk());

    expect($answer)
        ->email->toBe('ann@example.com')
        ->name->toBe('Ann')
        ->custom_fields->toBe(['plan' => 'pro'])
        ->lists_total->toBe(1)
        ->ledger_total->toBe(1)
        ->and($answer['lists'][0])->key->toBe('readers')->status->toBe('subscribed')
        ->and($answer['ledger'])->toBe([[
            'action' => 'subscribe',
            'list' => 'readers',
            'basis' => null,
            'source' => 'signup',
            'notice_version' => null,
            'occurred_at' => now()->toIso8601String(),
            'meta' => '{"form":"footer"}',
        ]])
        ->and(json_encode($answer))->not->toContain('203.0.113.9')->not->toContain('Firefox');

    agent(Rights::Read)->tool(GetSubscriberTool::class, ['email' => 'nobody@example.com'])->assertHasErrors(['No subscriber has that address.']);
})->with('databases');

it('answers the fullest lookup at its longest', function (): void {
    $lists = longestLists(30);
    $subscriber = longestSubscriber(1, $lists);
    $fields = [];

    // Control characters too, which JSON writes as six characters each.
    foreach (range(1, SetCustomFieldsAction::MAX_FIELDS) as $number) {
        $fields[str_pad("f{$number}", 64, '_')] = str_repeat($number % 2 === 0 ? '"' : "\x01\x02", SetCustomFieldsAction::MAX_TEXT / 2);
    }

    $subscriber->forceFill(['custom_fields' => $fields])->save();

    foreach (range(1, 30) as $number) {
        ConsentRecord::record($subscriber, ConsentAction::Subscribe, new ConsentContext(str_repeat('"', 125), noticeVersion: str_repeat('"', 64), meta: ['note' => str_repeat('"', 5000)]), $lists->get($number - 1));
    }

    $answer = structuredContent(agent(Rights::Read)->tool(GetSubscriberTool::class, ['email' => $subscriber->email])->assertOk());

    expect($answer)
        ->lists->toHaveCount(SubscriberSummary::DETAIL_LISTS)
        ->ledger->toHaveCount(SubscriberSummary::LEDGER_ENTRIES)
        ->and($answer['custom_fields'][str_pad('f2', 64, '_')])->toBe(str_repeat('"', SubscriberSummary::FIELD_LENGTH).'...')
        ->and(json_encode($answer))->not->toContain('\u0001');
});

it('unsubscribes and blocks one address, once each', function (): void {
    $subscriber = Subscriber::factory()->create(['email' => 'ann@example.com']);

    $unsubscribed = structuredContent(agent()->tool(UnsubscribeContactTool::class, ['email' => 'ann@example.com'])->assertOk());
    $again = structuredContent(agent()->tool(UnsubscribeContactTool::class, ['email' => 'ann@example.com'])->assertOk());
    $blocked = structuredContent(agent(person: true)->tool(BlockContactTool::class, ['email' => 'ann@example.com'])->assertOk());

    expect($unsubscribed['changed'])->toBeTrue()
        ->and($unsubscribed['subscriber'])->mailable->toBeFalse()->opted_out_at->not->toBeNull()->blocked_at->toBeNull()
        ->and($again['changed'])->toBeFalse()
        ->and($blocked['changed'])->toBeTrue()
        ->and($blocked['subscriber']['blocked_reason'])->toBe('manual')
        ->and($subscriber->fresh()->blocked_reason)->toBe(BlockReason::Manual)
        ->and(ConsentRecord::query()->orderBy('id')->pluck('meta')->all())->toBe([['by' => 'laptop'], ['by' => 'Ann Agent', 'reason' => 'manual']]);
});

describe('unblocking', function (): void {
    beforeEach(function (): void {
        sendFromExample();
        config(['campaigns.provider' => 'fake']);
        Subscriber::factory()->create(['email' => 'ann@example.com', 'blocked_at' => now(), 'blocked_reason' => BlockReason::HardBounce, 'soft_bounces' => 2]);
    });

    it('lets the address be mailed and says which of the provider\'s lists still hold it', function (): void {
        fakeProvider()->suppress(new Suppression('ann@example.com', SuppressionType::Bounce, Carbon::parse('2026-10-01T10:00:00Z'), '550 No such user'));

        $answer = structuredContent(agent()->tool(UnblockContactTool::class, ['email' => 'ann@example.com'])->assertOk());

        expect($answer)
            ->changed->toBeTrue()
            ->provider->toBe(['checked' => true, 'lists' => [['list' => 'bounce', 'since' => '2026-10-01T10:00:00+00:00', 'reason' => '550 No such user']], 'error' => null])
            ->and($answer['subscriber'])->mailable->toBeTrue()->blocked_at->toBeNull()
            ->and($answer['notes'])->toHaveCount(2)
            ->and($answer['notes'][0])->toBe('Its count of temporary failures starts over at 0.')
            ->and($answer['notes'][1])->toContain('The provider still holds the address')
            ->and(Subscriber::findByEmail('ann@example.com')->soft_bounces)->toBe(0);
    });

    it('keeps the unblock when the provider can\'t be asked', function (): void {
        fakeProvider()->failReads(new CampaignsException('The provider couldn\'t be read.'));

        $answer = structuredContent(agent()->tool(UnblockContactTool::class, ['email' => 'ann@example.com'])->assertOk());
        $again = structuredContent(agent()->tool(UnblockContactTool::class, ['email' => 'ann@example.com'])->assertOk());

        expect($answer)
            ->changed->toBeTrue()
            ->provider->toBe(['checked' => false, 'lists' => [], 'error' => 'The provider couldn\'t be read.'])
            ->and($answer['notes'][1])->toBe('Couldn\'t check whether the provider still holds the address; the unblock stands.')
            ->and(Subscriber::findByEmail('ann@example.com')->blocked_at)->toBeNull()
            ->and($again['changed'])->toBeFalse()
            ->and($again['notes'])->toBe(['Couldn\'t check whether the provider still holds the address; the unblock stands.']);
    });
});
