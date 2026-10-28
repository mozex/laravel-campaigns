<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\AgentResource;
use Mozex\Campaigns\Contracts\Audience;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MemberDirectory;
use Mozex\Campaigns\Tests\Fixtures\MemberList;
use Mozex\Campaigns\Tests\Fixtures\MembersInCountry;
use Mozex\Campaigns\Tests\Fixtures\RecentContacts;

it('applies a consent context only to its own model, and only inside the callback', function (): void {
    $context = new ConsentContext('registration', accepted: true);
    $seen = [];

    Member::withCampaignConsent($context, function () use (&$seen): void {
        $seen[] = Campaigns::consentFor(new Member);
        $seen[] = Campaigns::consentFor(new Subscriber);
    });
    $seen[] = Campaigns::consentFor(new Member);

    try {
        Member::withCampaignConsent($context, fn () => throw new RuntimeException('Sign-up failed'));
    } catch (RuntimeException) {
        $seen[] = Campaigns::consentFor(new Member);
    }

    expect($seen)->toBe([$context, null, null, null]);
});

it('moves activity only forward, and only for a record with a subscriber', function (): void {
    $member = Member::query()->create(['email' => 'jane@example.com']);
    $later = now()->subDay()->startOfSecond();
    $earlier = now()->subWeek()->startOfSecond();
    $latest = now()->subHour()->startOfSecond();

    $results = [
        Campaigns::recordActivity($member, $later),
        Campaigns::recordActivity($member, $earlier),
        Campaigns::recordActivity(Subscriber::query()->sole(), $earlier),
        Campaigns::recordActivity(new Member(['id' => 999])),
        Campaigns::recordActivity(Subscriber::query()->sole(), $latest),
    ];

    expect($results)->toBe([true, false, false, false, true])
        ->and(Subscriber::query()->sole()->last_active_at?->equalTo($latest))->toBeTrue();
});

it('refuses a list that has no source, a source of the wrong kind, or a key a static list holds', function (): void {
    config()->set('campaigns.list_sources', ['broken' => Member::class, 'members' => MemberList::class]);
    CampaignList::factory()->create(['key' => 'members']);

    expect(fn () => Campaigns::listSource('missing'))->toThrow(CampaignsException::class, 'No list source is configured for the list "missing"')
        ->and(fn () => Campaigns::listSource('broken'))->toThrow(CampaignsException::class, 'must implement')
        ->and(fn () => Campaigns::list('members'))->toThrow(CampaignsException::class, 'A static list already uses the key "members"');
});

it('turns a named audience into its subscribers, from the app\'s records or from subscribers', function (string $driver): void {
    useDatabase($driver);
    config()->set('campaigns.audiences', ['in-country' => MembersInCountry::class, 'recent-contacts' => RecentContacts::class]);

    Member::query()->create(['email' => 'ana@example.com', 'country' => 'ES']);
    Member::query()->create(['email' => 'bob@example.com', 'country' => 'US']);
    Member::query()->create(['email' => 'eva@example.com', 'country' => 'ES']);
    Member::query()->create(['email' => 'no-address', 'country' => 'ES']);
    $contact = Subscriber::factory()->create(['email' => 'eva.contact@example.com']);
    Subscriber::factory()->create(['email' => 'old.contact@example.com', 'created_at' => now()->subDays(60)]);

    $spain = Campaigns::audienceSubscribers('in-country', ['country' => 'es', 'ignored' => true]);

    expect($spain->orderBy('email')->pluck('email')->all())->toBe(['ana@example.com', 'eva@example.com'])
        ->and(Campaigns::audienceSubscribers('recent-contacts', ['days' => 7])->pluck('id')->all())->toBe([$contact->id])
        ->and(fn () => Campaigns::audienceSubscribers('in-country', ['country' => 'Spain']))->toThrow(ValidationException::class)
        ->and(fn () => Campaigns::audienceSubscribers('in-country'))->toThrow(ValidationException::class)
        ->and(array_keys(Campaigns::audiences()))->toBe(['in-country', 'recent-contacts'])
        ->and(array_keys(Campaigns::audience('in-country')->parameters(new JsonSchemaTypeFactory)))->toBe(['country']);
})->with('databases');

it('reads an app\'s resource a page at a time', function (): void {
    config()->set('campaigns.resources', ['members' => MemberDirectory::class]);

    foreach (['Ana', 'Bob', 'Eva'] as $name) {
        Member::query()->create(['email' => strtolower($name).'@example.com', 'name' => $name]);
    }

    $first = Campaigns::resource('members')->read(1);
    $second = Campaigns::resource('members')->read(2);

    expect($first->items())->toBe([['name' => 'Ana'], ['name' => 'Bob']])
        ->and($first->hasMorePages())->toBeTrue()
        ->and($second->items())->toBe([['name' => 'Eva']])
        ->and($second->hasMorePages())->toBeFalse()
        ->and(array_keys(Campaigns::resources()))->toBe(['members']);
});

it('refuses an audience or resource that isn\'t configured or has the wrong class, and leaves a broken one out of the lists', function (): void {
    Exceptions::fake();
    config()->set('campaigns.audiences', ['broken' => MemberDirectory::class, 'in-country' => MembersInCountry::class]);
    config()->set('campaigns.resources', ['broken' => MembersInCountry::class, 'members' => MemberDirectory::class]);

    expect(fn () => Campaigns::audience('missing'))->toThrow(CampaignsException::class, 'Nothing is configured as "missing" in campaigns.audiences.')
        ->and(fn () => Campaigns::audience('broken'))->toThrow(CampaignsException::class, 'must implement '.Audience::class)
        ->and(fn () => Campaigns::resource('missing'))->toThrow(CampaignsException::class, 'Nothing is configured as "missing" in campaigns.resources.')
        ->and(fn () => Campaigns::resource('broken'))->toThrow(CampaignsException::class, 'must implement '.AgentResource::class)
        ->and(array_keys(Campaigns::audiences()))->toBe(['in-country'])
        ->and(array_keys(Campaigns::resources()))->toBe(['members']);

    Exceptions::assertReported(CampaignsException::class);
});
