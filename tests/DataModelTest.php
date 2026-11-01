<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Models\Upload;

it('stores the longest address and user agent the columns allow', function (string $driver): void {
    useDatabase($driver);

    $email = str_repeat('a', 64).'@'.str_repeat('b', 185).'.com';
    $subscriber = Subscriber::factory()->create(['email' => $email]);
    ConsentRecord::factory()->for($subscriber)->create(['user_agent' => str_repeat('x', 400)]);

    expect(strlen($email))->toBe(254)
        ->and(Subscriber::query()->sole()->email)->toBe($email)
        ->and(ConsentRecord::query()->sole()->user_agent)->toHaveLength(400);
})->with('databases');

it('keeps addresses that differ only by an accent apart', function (string $driver): void {
    useDatabase($driver);

    Subscriber::factory()->create(['email' => 'jose@example.com']);
    Subscriber::factory()->create(['email' => 'josé@example.com']);

    expect(Subscriber::query()->where('email', 'josé@example.com')->sole()->email)->toBe('josé@example.com');
})->with('databases');

it('builds the whole model graph and resolves every relation', function (string $driver): void {
    useDatabase($driver);

    $list = CampaignList::factory()->create();
    $subscriber = Subscriber::factory()->blocked(BlockReason::Complaint)->create(['custom_fields' => ['plan' => 'pro']]);
    $subscription = Subscription::factory()->for($list, 'list')->for($subscriber)->create();
    $event = ProviderEvent::factory()->create(['message_id' => CampaignMessage::factory()->for($subscriber)]);
    $testSend = TestSend::factory()->for($event->message->campaign)->create();
    ProviderEvent::factory()->create(['message_id' => null, 'test_send_id' => $testSend->id]);
    ApiToken::factory()->create();

    $subscriber->subscribable()->associate($list)->save();

    $message = CampaignMessage::query()->sole();
    $fresh = Subscriber::query()->sole();

    expect($list->subscribers()->sole()->pivot->status)->toBe(SubscriptionStatus::Subscribed->value)
        ->and($subscription->list->is($list))->toBeTrue()
        ->and($fresh->lists()->sole()->is($list))->toBeTrue()
        ->and($fresh->blocked_reason)->toBe(BlockReason::Complaint)
        ->and($fresh->custom_fields)->toBe(['plan' => 'pro'])
        ->and($fresh->subscribable_type)->toBe('campaigns.list')
        ->and($fresh->subscribable->is($list))->toBeTrue()
        ->and($message->status)->toBe(MessageStatus::Pending)
        ->and($message->email)->toBe($subscriber->email)
        ->and($message->uuid)->toBeString()->toHaveLength(36)
        ->and($message->events()->sole()->is($event))->toBeTrue()
        ->and($event->fresh()->type)->toBe(FeedbackType::Delivered)
        ->and($message->campaign->testSends()->sole()->events()->sole()->testSend->is($testSend))->toBeTrue()
        ->and($message->campaign->uuid)->toBeString()->toHaveLength(36);
})->with('databases');

it('stores a template\'s tests with no campaign, and tests waiting for their token', function (string $driver): void {
    useDatabase($driver);

    $template = Template::factory()->create();
    $revision = Revision::factory()->of($template)->withContent('en')->create();

    TestSend::factory()->count(2)->create(['campaign_id' => null, 'revision_id' => $revision->id, 'token_hash' => null]);
    $failed = TestSend::factory()->create(['revision_id' => $revision->id, 'failed_at' => now(), 'failure' => str_repeat('x', 255)]);

    $tests = TestSend::query()->with(['revision', 'campaign'])->orderBy('id')->get();

    expect($tests->pluck('token_hash')->filter()->count())->toBe(1)
        ->and($tests->first()->campaign)->toBeNull()
        ->and($tests->first()->revision->is($revision))->toBeTrue()
        ->and($failed->fresh()->failure)->toHaveLength(255);
})->with('databases');

it('stores templates, revisions, the asset library, uploads and saved audiences, and links a campaign to them', function (string $driver): void {
    useDatabase($driver);

    // A whole email's worth of HTML: MySQL's text column would refuse it.
    $html = '<p>'.str_repeat('x', 89 * 1024).'</p>';

    $template = Template::factory()->create();
    $first = Revision::factory()->of($template)->withContent('en', ['html' => $html])->create();
    $second = Revision::factory()->of($template)->withContent('en')->withContent('fa')->create();
    $source = Campaign::factory()->create();
    $campaign = Campaign::factory()->create([
        'status' => CampaignStatus::Scheduled,
        'template_id' => $template->id,
        'frozen_revision_id' => $second->id,
        'duplicated_from_id' => $source->id,
        'scheduled_at' => now()->addDay(),
    ]);
    Asset::factory()->create(['url' => 'https://cdn.example.com/'.str_repeat('a', 2000)]);
    Upload::factory()->create();
    SavedAudience::factory()->create(['filters' => ['tiers' => ['engaged'], 'locales' => ['en']]]);

    $campaign = $campaign->fresh();

    expect($first->contents()->sole()->html)->toBe($html)
        ->and([$first->number, $second->number])->toBe([1, 2])
        ->and($template->currentRevision()->sole()->is($second))->toBeTrue()
        ->and($second->fresh()->revisable->is($template))->toBeTrue()
        ->and($second->revisable_type)->toBe('campaigns.template')
        ->and($second->fresh()->locales())->toBe(['en', 'fa'])
        ->and($campaign->status)->toBe(CampaignStatus::Scheduled)
        ->and($campaign->template->is($template))->toBeTrue()
        ->and($campaign->frozenRevision->is($second))->toBeTrue()
        ->and($campaign->duplicatedFrom->is($source))->toBeTrue()
        ->and(Asset::query()->sole()->url)->toHaveLength(2024)
        ->and(Upload::query()->sole()->expires_at)->not->toBeNull()
        ->and(SavedAudience::query()->sole()->filters)->toEqual(['tiers' => ['engaged'], 'locales' => ['en']])
        ->and(fn () => Revision::query()->insert([
            'uuid' => (string) Str::uuid(),
            'revisable_type' => 'campaigns.template',
            'revisable_id' => $template->id,
            'number' => 2,
        ]))->toThrow(UniqueConstraintViolationException::class);
})->with('databases');

it('numbers revisions with integers when the app keys its own morphs by UUID', function (): void {
    Builder::morphUsingUuids();

    try {
        useDatabase('pgsql');
    } finally {
        Builder::defaultMorphKeyType('int');
    }

    $template = Template::factory()->create();
    $revision = Revision::factory()->of($template)->withContent()->create();

    expect($revision->fresh()->revisable->is($template))->toBeTrue();
});

it('keeps the consent records and messages of a deleted subscriber', function (string $driver): void {
    useDatabase($driver);

    $subscriber = Subscriber::factory()->create();
    Subscription::factory()->for($subscriber)->create();
    ConsentRecord::factory()->for($subscriber)->create(['action' => ConsentAction::OptOut]);
    CampaignMessage::factory()->for($subscriber)->create();

    $subscriber->delete();

    expect(Subscription::query()->count())->toBe(0)
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::OptOut)
        ->subscriber_id->toBeNull()
        ->and(CampaignMessage::query()->sole()->subscriber_id)->toBeNull();
})->with('databases');

it('gives every model a prefixed morph alias', function (): void {
    // Every class in src/Models, so a model added later can't be missed.
    $models = array_map(
        fn (string $path): Model => new ('Mozex\\Campaigns\\Models\\'.basename($path, '.php')),
        glob(__DIR__.'/../src/Models/*.php') ?: [],
    );

    expect($models)->not->toBeEmpty();

    Relation::requireMorphMap();

    try {
        foreach ($models as $model) {
            expect($model->getMorphClass())->toStartWith('campaigns.')
                ->and(Relation::getMorphedModel($model->getMorphClass()))->toBe($model::class);
        }
    } finally {
        Relation::requireMorphMap(false);
    }
});

it('keeps one link row per address of a revision, keys case-sensitive, and makes them race-safe', function (string $driver): void {
    useDatabase($driver);
    $revision = Revision::factory()->withContent('en', ['html' => '<a href="https://shop.test/a">A</a><a href="https://shop.test/a">Again</a><a href="https://shop.test/b">B</a>{{footer}}'])->create();
    $other = Revision::factory()->withContent('en', ['html' => '<a href="https://shop.test/a">A</a>{{footer}}'])->create();
    $long = 'https://shop.test/?q='.str_repeat('x', 8000);

    $keys = CampaignLink::writeFor($revision);
    $again = CampaignLink::writeFor($revision);
    CampaignLink::writeFor($other);
    // Two keys that differ only in case are two rows.
    CampaignLink::factory()->create(['key' => str_repeat('a', 22)]);
    CampaignLink::factory()->create(['key' => str_repeat('A', 22)]);
    CampaignLink::factory()->create(['url' => $long, 'url_hash' => CampaignLink::hash($long)]);

    expect($keys)->toHaveKeys(['https://shop.test/a', 'https://shop.test/b'])->toHaveCount(2)
        ->and($again)->toBe($keys)
        ->and(CampaignLink::query()->where('revision_id', $other->id)->sole()->key)->not->toBe($keys['https://shop.test/a'])
        ->and(CampaignLink::query()->where('key', str_repeat('A', 22))->count())->toBe(1)
        ->and(CampaignLink::query()->where('url_hash', CampaignLink::hash($long))->sole()->url)->toBe($long)
        ->and($revision->links()->count())->toBe(2)
        ->and(fn () => CampaignLink::factory()->create(['revision_id' => $revision->id, 'url' => 'https://shop.test/a', 'url_hash' => CampaignLink::hash('https://shop.test/a')]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with('databases');

it('writes a revision\'s link rows in chunks, hundreds of addresses across its languages', function (string $driver): void {
    useDatabase($driver);
    $links = fn (string $locale): string => implode('', array_map(fn (int $n): string => "<a href=\"https://shop.test/{$locale}/{$n}\">{$n}</a>", range(1, 130)));
    $revision = Revision::factory()
        ->withContent('en', ['html' => $links('en').'<a href="https://shop.test/shared">S</a>{{footer}}'])
        ->withContent('fr', ['html' => $links('fr').'<a href="https://shop.test/shared">S</a>{{footer}}'])
        ->create();

    $keys = CampaignLink::writeFor($revision);

    expect($keys)->toHaveCount(261)
        ->and(CampaignLink::query()->where('revision_id', $revision->id)->count())->toBe(261)
        ->and(array_unique(array_values($keys)))->toHaveCount(261)
        ->and(CampaignLink::writeFor($revision))->toEqual($keys);
})->with('databases');
