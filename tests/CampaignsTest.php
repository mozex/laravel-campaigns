<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Member;

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

    $results = [
        Campaigns::recordActivity($member, $later),
        Campaigns::recordActivity($member, $earlier),
        Campaigns::recordActivity(Subscriber::query()->sole(), $earlier),
        Campaigns::recordActivity(new Member(['id' => 999])),
    ];

    expect($results)->toBe([true, false, false, false])
        ->and(Subscriber::query()->sole()->last_active_at?->equalTo($later))->toBeTrue();
});

it('refuses a list that has no source, or a source of the wrong kind', function (): void {
    config()->set('campaigns.list_sources', ['broken' => Member::class]);

    expect(fn () => Campaigns::listSource('missing'))->toThrow(CampaignsException::class, 'No list source is configured for the list "missing"')
        ->and(fn () => Campaigns::listSource('broken'))->toThrow(CampaignsException::class, 'must implement');
});
