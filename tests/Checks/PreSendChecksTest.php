<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\Actions\AddRevisionAction;
use Mozex\Campaigns\Actions\QueueChecksAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Checks\PreSendChecks;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Jobs\RunChecksJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Sending\OutgoingMessage;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    resolveHosts(['example.com' => '93.184.216.34', 'cdn.example.com' => '93.184.216.35', 'app.example.com' => '93.184.216.36']);

    config()->set('app.url', 'https://app.example.com');
    config()->set('campaigns.privacy_url', 'https://app.example.com/privacy');
    config()->set('campaigns.mail.from', ['address' => 'news@news.example.com', 'name' => 'Example News']);
    config()->set('campaigns.checks.spam_score', true);
});

/**
 * A template's revision holding the given HTML in English.
 */
function revisionWith(string $html): Revision
{
    return app(AddRevisionAction::class)->execute(Template::factory()->create(), [
        new EmailContent(locale: 'en', subject: 'Hello {{first_name}}', html: $html),
    ]);
}

/**
 * Queues the checks and runs the queued job, as a worker would.
 *
 * @param  ?list<string>  $locales
 * @return array<string, mixed> the stored results
 */
function runChecks(Revision $revision, ?array $locales = null, bool $clickTracking = true): array
{
    app(QueueChecksAction::class)->execute($revision, $locales, $clickTracking);

    $job = Queue::pushedJobs()[RunChecksJob::class][0]['job'];
    app()->call([$job, 'handle']);
    Queue::fake();

    return (array) $revision->fresh()->check_results;
}

/**
 * One check's result.
 *
 * @param  array<string, mixed>  $results
 * @return array<string, mixed>
 */
function check(array $results, string $check, ?string $locale = null): array
{
    return collect($results['checks'])->first(fn (array $result): bool => $result['check'] === $check
        && ($locale === null || $result['locale'] === $locale)) ?? [];
}

it('scores a campaign\'s email from the campaign\'s sender, with the headers a send carries', function (): void {
    Http::fake([
        PreSendChecks::SPAM_CHECKER => Http::response(['success' => true, 'score' => '0.1', 'rules' => []]),
        'https://app.example.com/privacy' => Http::response(),
    ]);

    $campaign = Campaign::factory()->create(['from_name' => 'Spring Team', 'from_email' => 'spring@news.example.com', 'reply_to' => 'help@example.com']);
    $revision = app(AddRevisionAction::class)->execute($campaign, [new EmailContent(locale: 'en', subject: 'Hello', html: '<p>Hi</p>{{footer}}')]);

    runChecks($revision);

    $sent = '';
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->url() !== PreSendChecks::SPAM_CHECKER) {
            return false;
        }

        $sent = (string) $request['email'];

        return true;
    });

    $headers = (new OutgoingMessage(
        uuid: 'x', to: 'someone@example.com', fromEmail: 'spring@news.example.com', fromName: 'Spring Team', replyTo: 'help@example.com',
        subject: 'Hello', html: '', text: '', unsubscribeUrl: Personalization::sample('en')->unsubscribeUrl, tag: 'x', trackOpens: true, trackClicks: true,
    ))->headers();

    expect($sent)->toContain('From: Spring Team <spring@news.example.com>')
        ->and($sent)->toContain('Reply-To: help@example.com')
        ->and($sent)->toContain('List-Unsubscribe: '.$headers['List-Unsubscribe'])
        ->and($sent)->toContain('List-Unsubscribe-Post: '.$headers['List-Unsubscribe-Post'])
        ->and($sent)->not->toContain('news@news.example.com');
});

it('queues the checks and returns at once', function (): void {
    $revision = revisionWith('<p>Hi</p>{{footer}}');

    $run = app(QueueChecksAction::class)->execute($revision, ['en', 'fr']);

    expect($revision->fresh()->check_results)
        ->status->toBe('queued')
        ->run->toBe($run)
        ->and($revision->fresh()->checked_at)->toBeNull()
        ->and(fn () => app(QueueChecksAction::class)->execute($revision, ['not a locale']))
        ->toThrow(CampaignsException::class, 'is not a locale');

    Queue::assertPushed(RunChecksJob::class, fn (RunChecksJob $job): bool => $job->revisionId === $revision->id
        && $job->run === $run
        && $job->locales === ['en', 'fr']
        && $job->queue === 'campaigns');
});

it('checks the links, images, size, unsubscribe and privacy links, languages and spam score', function (): void {
    Http::fake(function (Request $request) {
        return match ($request->url()) {
            'https://example.com/ok', 'http://example.com/plain', 'https://cdn.example.com/hero.png', 'https://app.example.com/privacy' => Http::response(),
            'https://example.com/no-head' => Http::response(status: $request->method() === 'HEAD' ? 405 : 200),
            'https://example.com/missing' => Http::response(status: 404),
            'https://example.com/inside' => Http::response(status: 302, headers: ['Location' => 'https://169.254.169.254/']),
            PreSendChecks::SPAM_CHECKER => Http::response([
                'success' => true,
                'score' => '2.3',
                'rules' => [
                    ['score' => '1.4', 'description' => 'Missing Date: header'],
                    ['score' => '0.0', 'description' => 'BODY: HTML included in message'],
                ],
            ]),
        };
    });

    $revision = revisionWith(<<<'HTML'
        <p><a href="https://example.com/ok">Read</a> <a href="https://example.com/ok">Again</a>
        <a href="https://example.com/no-head">Old server</a> <a href="https://example.com/missing">Gone</a>
        <a href="https://example.com/inside">Sneaky</a> <a href="http://example.com/plain">Plain</a>
        <a href="mailto:hi@example.com">Mail</a> <a href="{{web_version_url}}">View</a></p>
        <img src="https://cdn.example.com/hero.png"><img src="https://cdn.example.com/hero.png" alt="">
        {{footer}}
        HTML);

    $results = runChecks($revision, ['en', 'tr']);

    expect($results)
        ->status->toBe('done')
        ->passed->toBeFalse()
        ->failed->toBe(3)
        ->and($revision->fresh()->checked_at)->not->toBeNull()
        ->and(check($results, 'locales'))
        ->status->toBe('failed')
        ->missing->toBe(['tr'])
        ->and(check($results, 'unsubscribe', 'en')['status'])->toBe('passed')
        ->and(check($results, 'privacy_link', 'en')['status'])->toBe('passed')
        ->and(check($results, 'size', 'en')['status'])->toBe('passed')
        ->and(check($results, 'images', 'en'))
        ->status->toBe('failed')
        ->items->toBe([['url' => 'https://cdn.example.com/hero.png', 'problem' => 'has no alt text']])
        ->and(check($results, 'links'))
        ->status->toBe('failed')
        ->checked->toBe(7)
        ->and(collect(check($results, 'links')['items'])->mapWithKeys(fn (array $item): array => [$item['url'] => $item['problem']])->all())
        ->toBe([
            'https://example.com/missing' => 'answered 404',
            'https://example.com/inside' => '"https://169.254.169.254/" leads into a private or reserved network, which the server never fetches from.',
            'http://example.com/plain' => 'uses http:, not https:',
        ])
        ->and(check($results, 'spam_score', 'en'))
        ->status->toBe('passed')
        ->score->toBe(2.3)
        ->rules->toBe([['score' => 1.4, 'description' => 'Missing Date: header']])
        ->and(array_values(array_unique(array_column($results['checks'], 'check'))))->toEqualCanonicalizing(PreSendChecks::CHECKS);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === PreSendChecks::SPAM_CHECKER
            && $request['options'] === 'long'
            && str_contains($request['email'], 'Subject: Hello Alexandra')
            && str_contains($request['email'], 'To: someone@example.com')
            && str_contains($request['email'], 'From: Example News <news@news.example.com>')
            && str_contains($request['email'], 'Date: ')
            && str_contains($request['email'], 'Message-ID: ')
            && str_contains($request['email'], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click');
    });

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/campaigns/')
        || str_contains($request->url(), '169.254')
        || $request->method() === 'HEAD');
});

it('skips the spam score when it is off or the checker fails, and names a missing privacy link', function (): void {
    config()->set('campaigns.privacy_url', null);
    Http::fakeSequence(PreSendChecks::SPAM_CHECKER)
        ->push(['success' => false, 'message' => 'Busy'], 500)
        ->pushFailedConnection();

    $revision = revisionWith('<p>No links</p>{{unsubscribe_url}}');
    $failing = runChecks($revision);
    $silent = runChecks($revision);

    config()->set('campaigns.checks.spam_score', false);
    $off = runChecks($revision);

    expect(check($failing, 'spam_score', 'en'))
        ->status->toBe('skipped')
        ->message->toBe('Postmark\'s spam checker gave no score (HTTP 500).')
        ->and(check($silent, 'spam_score', 'en')['message'])->toBe('Postmark\'s spam checker didn\'t answer.')
        ->and(check($failing, 'privacy_link', 'en'))
        ->status->toBe('failed')
        ->message->toContain('campaigns.privacy_url is not set')
        ->and(check($failing, 'links')['status'])->toBe('passed')
        ->and(check($failing, 'locales')['message'])->toBe('It holds en. No audience was given to compare with.')
        ->and(check($off, 'spam_score', 'en')['status'])->toBe('skipped');

    Http::assertSentCount(2);
});

it('counts click tracking\'s longer links in the size', function (): void {
    config()->set('campaigns.checks.spam_score', false);
    Http::fake(['https://example.com/*' => Http::response(), 'https://app.example.com/*' => Http::response()]);

    $revision = revisionWith(str_repeat('<a href="https://example.com/a">Link</a>', 240).'{{footer}}');

    expect(check(runChecks($revision), 'size', 'en'))
        ->status->toBe('failed')
        ->message->toContain('once click tracking lengthens its links, over the 90 KB limit')
        ->and(check(runChecks($revision, clickTracking: false), 'size', 'en')['status'])->toBe('passed');
});

it('keeps only the latest run\'s results, and says when a run failed', function (): void {
    config()->set('campaigns.checks.spam_score', false);
    $revision = revisionWith('<p>Hi</p>{{footer}}');

    app(QueueChecksAction::class)->execute($revision);
    $first = Queue::pushedJobs()[RunChecksJob::class][0]['job'];
    $latest = app(QueueChecksAction::class)->execute($revision);

    app()->call([$first, 'handle']);
    $first->failed();

    expect($revision->fresh()->check_results)
        ->status->toBe('queued')
        ->run->toBe($latest);

    (new RunChecksJob($revision->id, $latest))->failed(new RuntimeException('boom'));

    expect($revision->fresh()->check_results)
        ->status->toBe('failed')
        ->message->toBe('The checks stopped with an error. Queue them again.')
        ->run->toBe($latest);
});
