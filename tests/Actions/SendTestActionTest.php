<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\Actions\SendTestAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Jobs\SendTestJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Support\Token;

beforeEach(function (): void {
    sendFromExample();
    config()->set('campaigns.driver', 'fake');
    config()->set('campaigns.test_addresses', ['owner@example.com', 'Editor@Example.com']);
});

it('sends a draft\'s current email to the test addresses only, each test once', function (): void {
    $campaign = Campaign::factory()->create();
    Revision::factory()->of($campaign)->withContent('en', ['subject' => 'First'])->create();
    $current = Revision::factory()->of($campaign)->withContent('en', ['subject' => 'Second'])->withContent('fr', ['subject' => 'Deuxième'])->create();

    $tests = app(SendTestAction::class)->execute($campaign, locales: ['en', 'FR']);

    // A duplicate job for a test already sent sends nothing.
    app()->call([new SendTestJob($tests[0]->id), 'handle']);

    $sent = fakeDriver()->sent();
    $stored = TestSend::query()->orderBy('id')->get();

    expect($tests)->toHaveCount(4)
        ->and($stored->pluck('email')->all())->toBe(['owner@example.com', 'owner@example.com', 'editor@example.com', 'editor@example.com'])
        ->and($stored->pluck('locale')->all())->toBe(['en', 'fr', 'en', 'fr'])
        ->and($stored->pluck('revision_id')->unique()->all())->toBe([$current->id])
        ->and($stored->pluck('campaign_id')->unique()->all())->toBe([$campaign->id])
        ->and($stored->every(fn (TestSend $test): bool => $test->sent_at !== null && $test->token_hash !== null && $test->failed_at === null))->toBeTrue()
        ->and($stored->first()->provider_message_id)->toBe('fake.'.$stored->first()->uuid.'@campaigns.test')
        ->and($stored->pluck('driver')->unique()->all())->toBe(['fake'])
        ->and($sent)->toHaveCount(4)
        ->and(array_map(fn (OutgoingMessage $message): string => $message->subject, $sent))->toBe(['Second', 'Deuxième', 'Second', 'Deuxième'])
        ->and($sent[0]->isTest)->toBeTrue()
        ->and($sent[0]->tag)->toBe(TestSend::TAG)
        ->and($campaign->messages()->count())->toBe(0);
});

it('sends a started campaign\'s frozen email, and a template\'s current one, to the addresses picked', function (): void {
    $template = Template::factory()->create();
    Revision::factory()->of($template)->withContent('en', ['subject' => 'Template'])->create();

    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
    $frozen = Revision::factory()->of($campaign)->withContent('en', ['subject' => 'Frozen'])->create();
    Revision::factory()->of($campaign)->withContent('en', ['subject' => 'Later'])->create();
    $campaign->update(['frozen_revision_id' => $frozen->id]);

    app(SendTestAction::class)->execute($campaign, [' OWNER@example.com ']);
    app(SendTestAction::class)->execute($template, ['owner@example.com', 'owner@example.com']);

    expect(array_map(fn (OutgoingMessage $message): string => $message->subject, fakeDriver()->sent()))->toBe(['Frozen', 'Template'])
        ->and(TestSend::query()->where('campaign_id', null)->count())->toBe(1);
});

it('refuses a test that could reach anyone but a test address, or that it can\'t send', function (Closure $setUp, string $message): void {
    Queue::fake();
    $campaign = Campaign::factory()->create();
    Revision::factory()->of($campaign)->withContent('en')->create();

    $source = $setUp($campaign) ?? $campaign;

    expect(fn () => app(SendTestAction::class)->execute($source))->toThrow(CampaignsException::class, $message)
        ->and(TestSend::query()->count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'sending off' => [fn () => config()->set('campaigns.enabled', false), 'switched off'],
    'no test address' => [fn () => config()->set('campaigns.test_addresses', []), 'No test addresses'],
    'no email yet' => [fn (): Campaign => Campaign::factory()->create(), 'no email'],
]);

it('refuses an address that isn\'t a test address, a language the email lacks, and too many at once', function (): void {
    Queue::fake();
    $campaign = Campaign::factory()->create();
    Revision::factory()->of($campaign)->withContent('en')->create();
    config()->set('campaigns.test_addresses', array_map(fn (int $n): string => "owner{$n}@example.com", range(1, 26)));

    expect(fn () => app(SendTestAction::class)->execute($campaign, ['owner1@example.com', 'reader@example.org']))->toThrow(CampaignsException::class, '1 of the addresses given is not among them')
        ->and(fn () => app(SendTestAction::class)->execute($campaign, ['owner1@example.com'], ['de']))->toThrow(CampaignsException::class, 'no "de" version; it holds en')
        ->and(fn () => app(SendTestAction::class)->execute($campaign))->toThrow(CampaignsException::class, 'At most 25')
        ->and(TestSend::query()->count())->toBe(0);
});

it('records why a test failed, and sends nothing to an address taken off the list or once sending is off', function (): void {
    $campaign = Campaign::factory()->create();
    Revision::factory()->of($campaign)->withContent('en')->create();

    fakeDriver()->failNext(new SendFailed(SendFailure::DomainRefused, 'Mailgun answered 401: Forbidden', 401));
    [$refused] = app(SendTestAction::class)->execute($campaign, ['owner@example.com']);

    Queue::fake();
    [$removed] = app(SendTestAction::class)->execute($campaign, ['editor@example.com']);
    [$switchedOff] = app(SendTestAction::class)->execute($campaign, ['owner@example.com']);

    config()->set('campaigns.test_addresses', ['owner@example.com']);
    app()->call([new SendTestJob($removed->id), 'handle']);

    config()->set('campaigns.enabled', false);
    app()->call([new SendTestJob($switchedOff->id), 'handle']);

    expect($refused->fresh())
        ->failed_at->not->toBeNull()
        ->failure->toBe('Mailgun answered 401: Forbidden')
        ->sent_at->toBeNull()
        ->and($removed->fresh())
        ->failure->toContain('no longer one of the test addresses')
        ->token_hash->toBeNull()
        ->and($switchedOff->fresh()->failure)->toContain('switched off')
        ->and(fakeDriver()->sent())->toBe([]);
});

it('answers for a test whose job died after claiming it, and refuses a template\'s test from off the marketing domain', function (): void {
    Queue::fake();
    $campaign = Campaign::factory()->create();
    Revision::factory()->of($campaign)->withContent('en')->create();
    [$died, $sent] = app(SendTestAction::class)->execute($campaign);

    TestSend::query()->whereKey($died->id)->update(['token_hash' => Token::hash('claimed')]);
    TestSend::query()->whereKey($sent->id)->update(['token_hash' => Token::hash('sent'), 'sent_at' => now()]);
    (new SendTestJob($died->id))->failed(new RuntimeException('Timed out.'));
    (new SendTestJob($sent->id))->failed(new RuntimeException('Timed out.'));

    config()->set('campaigns.mail.domain', 'other.example.com');
    $template = Template::factory()->create();
    Revision::factory()->of($template)->withContent('en')->create();
    [$offDomain] = app(SendTestAction::class)->execute($template, ['owner@example.com']);
    app()->call([new SendTestJob($offDomain->id), 'handle']);

    expect($died->fresh())
        ->failed_at->not->toBeNull()
        ->failure->toContain('may still arrive')
        ->and($sent->fresh()->failed_at)->toBeNull()
        ->and($offDomain->fresh()->failure)->toContain('must be on the sending domain, other.example.com')
        ->and(fakeDriver()->sent())->toBe([]);
});

it('opens the real unsubscribe page from a test\'s link and changes only the test', function (): void {
    $campaign = Campaign::factory()->create();
    Revision::factory()->of($campaign)->withContent('en')->create();

    [$test] = app(SendTestAction::class)->execute($campaign, ['owner@example.com']);
    $link = fakeDriver()->sent()[0]->unsubscribeUrl;
    $token = basename($link);

    $this->post($link)->assertOk();

    expect(Token::hash($token))->toBe($test->fresh()->token_hash)
        ->and($test->fresh()->unsubscribed_at)->not->toBeNull();
});
