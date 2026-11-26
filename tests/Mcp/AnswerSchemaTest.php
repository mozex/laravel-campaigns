<?php

declare(strict_types=1);

use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\FreezeReportAction;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\Actions\SendConfirmationAction;
use Mozex\Campaigns\Checks\PreSendChecks;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Jobs\FreezeCampaignJob;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Jobs\RunChecksJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Jobs\SendTestJob;
use Mozex\Campaigns\Mcp\Tools\AddContactsTool;
use Mozex\Campaigns\Mcp\Tools\AddImageTool;
use Mozex\Campaigns\Mcp\Tools\AddRevisionTool;
use Mozex\Campaigns\Mcp\Tools\ArchiveListTool;
use Mozex\Campaigns\Mcp\Tools\ArchiveTemplateTool;
use Mozex\Campaigns\Mcp\Tools\BlockContactTool;
use Mozex\Campaigns\Mcp\Tools\CancelCampaignTool;
use Mozex\Campaigns\Mcp\Tools\CreateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\CreateListTool;
use Mozex\Campaigns\Mcp\Tools\CreateTemplateTool;
use Mozex\Campaigns\Mcp\Tools\CreateUploadLinkTool;
use Mozex\Campaigns\Mcp\Tools\DuplicateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignReportTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignStatusTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetCheckResultsTool;
use Mozex\Campaigns\Mcp\Tools\GetGuideTool;
use Mozex\Campaigns\Mcp\Tools\GetPreviewLinkTool;
use Mozex\Campaigns\Mcp\Tools\GetSubscriberTool;
use Mozex\Campaigns\Mcp\Tools\GetTemplateTool;
use Mozex\Campaigns\Mcp\Tools\GetTestSendsTool;
use Mozex\Campaigns\Mcp\Tools\ListAssetsTool;
use Mozex\Campaigns\Mcp\Tools\ListAudiencesTool;
use Mozex\Campaigns\Mcp\Tools\ListCampaignsTool;
use Mozex\Campaigns\Mcp\Tools\ListListsTool;
use Mozex\Campaigns\Mcp\Tools\ListRevisionsTool;
use Mozex\Campaigns\Mcp\Tools\ListSubscribersTool;
use Mozex\Campaigns\Mcp\Tools\ListTemplatesTool;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Mcp\Tools\PauseCampaignTool;
use Mozex\Campaigns\Mcp\Tools\PreviewAudienceTool;
use Mozex\Campaigns\Mcp\Tools\PreviewContactsImportTool;
use Mozex\Campaigns\Mcp\Tools\RemoveContactsTool;
use Mozex\Campaigns\Mcp\Tools\RestoreRevisionTool;
use Mozex\Campaigns\Mcp\Tools\ResumeCampaignTool;
use Mozex\Campaigns\Mcp\Tools\RunChecksTool;
use Mozex\Campaigns\Mcp\Tools\RunContactsImportTool;
use Mozex\Campaigns\Mcp\Tools\SaveAudienceTool;
use Mozex\Campaigns\Mcp\Tools\SendTestTool;
use Mozex\Campaigns\Mcp\Tools\SetContactFieldsTool;
use Mozex\Campaigns\Mcp\Tools\SlowDownCampaignTool;
use Mozex\Campaigns\Mcp\Tools\SpeedUpCampaignTool;
use Mozex\Campaigns\Mcp\Tools\StartCampaignTool;
use Mozex\Campaigns\Mcp\Tools\UnblockContactTool;
use Mozex\Campaigns\Mcp\Tools\UnsubscribeContactTool;
use Mozex\Campaigns\Mcp\Tools\UpdateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\UpdateListTool;
use Mozex\Campaigns\Mcp\Tools\UpdateTemplateTool;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\Suppression;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Mcp\SchemaCheckedServer;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MemberDirectory;
use Mozex\Campaigns\Tests\Fixtures\MembersInCountry;

/*
 * Every tool's answer, in the states that fill its fields, against the
 * tool's own output schema: a client such as Claude Code refuses an answer
 * that doesn't match. The states come from the package's own writers (the
 * checks job, a campaign's start, freeze, sends, opens, clicks and the
 * provider's feedback), never rows written by hand, which hold whatever the
 * test assumes. SchemaCheckedServer checks each answer; this test makes
 * sure every tool gave one.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
    Queue::fake([RunChecksJob::class, FreezeCampaignJob::class, SendMessageJob::class, SendTestJob::class, ProcessFeedbackJob::class]);
    resolveHosts(['example.com' => '93.184.216.34', 'cdn.example.com' => '93.184.216.35', 'app.example.com' => '93.184.216.36']);

    config([
        'app.url' => 'https://app.example.com',
        'campaigns.url' => 'https://app.example.com',
        'campaigns.privacy_url' => 'https://app.example.com/privacy',
        'campaigns.postal_address' => '1 Main Street, Springfield',
        'campaigns.mail.reply_to' => 'help@example.com',
        'campaigns.test_addresses' => ['me@example.com'],
        'campaigns.checks.spam_score' => true,
        'campaigns.postmaster.credentials' => googleCredentials(),
        'campaigns.resources' => ['members' => MemberDirectory::class],
        'campaigns.audiences' => ['in-country' => MembersInCountry::class],
    ]);
    sendFromExample();
});

/**
 * The queued jobs a worker has taken, each once: run, or died.
 *
 * @return WeakMap<object, true>
 */
function workedJobs(): WeakMap
{
    /** @var WeakMap<object, true> $taken */
    static $taken = new WeakMap;

    return $taken;
}

/**
 * Runs the jobs of a class the test queued and no worker took yet, as a
 * worker would.
 *
 * @param  class-string  $class
 */
function workQueued(string $class): void
{
    foreach (Queue::pushed($class) as $job) {
        if (! isset(workedJobs()[$job])) {
            workedJobs()[$job] = true;
            app()->call([$job, 'handle']);
        }
    }
}

/**
 * The last queued job of a class dies on its worker, which calls its
 * failed() and never runs it.
 *
 * @param  class-string  $class
 */
function dieQueued(string $class): void
{
    $job = Queue::pushed($class)->last();
    workedJobs()[$job] = true;
    $job->failed(new RuntimeException('The worker died.'));
}

/**
 * The structured answer of a tool called with full rights, checked against
 * its schema on the way.
 *
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function answered(string $tool, array $arguments = []): array
{
    return structuredContent(agent()->tool($tool, $arguments)->assertOk()->assertHasNoErrors());
}

/**
 * Opens an email and clicks its first tracked link, as a person does:
 * clicking every link at once is what a link scanner does.
 */
function readInBrowser(OutgoingMessage $email): void
{
    $browser = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36'];

    preg_match('~https://app\.example\.com/campaigns/o/[A-Za-z0-9]+~', $email->html, $pixel);
    test()->get($pixel[0], $browser)->assertOk();

    if (preg_match('~https://app\.example\.com/campaigns/l/[A-Za-z0-9]+/[A-Za-z0-9]+~', $email->html, $link) === 1) {
        test()->get($link[0], $browser)->assertRedirect();
    }
}

/**
 * What an answer may leave without a value (a field that may be null, a
 * list's items) that no tool's answer in this test filled, so its schema
 * was never checked against a value. A field several tools share (a
 * campaign's, a revision's, Gmail's reading) counts once, filled when any of
 * them filled it.
 *
 * @return list<string>
 */
function neverFilled(): array
{
    $shared = fn (string $path): string => (string) preg_replace(
        ['/^\.campaigns\[\]\./', '/^\.revisions\[\]\./', '/^\.campaign\.revision\./', '/^\.(readings|domain)\.gmail\./', '/^\.readings\.usage\./', '/^\.subscribers\[\]\./'],
        ['.campaign.', '.revision.', '.revision.', '.gmail.', '.usage.', '.subscriber.'],
        $path,
    );

    $optional = [];
    $filled = [];

    foreach (toolClasses() as $class) {
        foreach (SchemaCheckedServer::optionalPaths($class) as $path) {
            $optional[$shared($path)] = true;
        }

        foreach (array_keys(SchemaCheckedServer::$filled[$class] ?? []) as $path) {
            $filled[$shared($path)] = true;
        }
    }

    $never = array_keys(array_diff_key($optional, $filled));
    sort($never);

    return $never;
}

it('answers every tool within its output schema, through a template\'s checks and a campaign\'s life', function (string $driver): void {
    useDatabase($driver);
    $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'UTC'));

    // Google's answers first: the catch-all below answers everything else.
    fakeGoogle(postmasterStats([
        '2026-11-22' => ['spam_rate' => 0.0004, 'dkim_success_rate' => 1.0, 'spf_success_rate' => 1.0, 'dmarc_success_rate' => 1.0],
        '2026-11-23' => ['spam_rate' => 0.0012, 'dkim_success_rate' => 1.0, 'spf_success_rate' => 1.0, 'dmarc_success_rate' => 0.98],
    ]));
    Http::fake(fn (ClientRequest $request): PromiseInterface => match (true) {
        $request->url() === PreSendChecks::SPAM_CHECKER => Http::response(['success' => true, 'score' => '1.2', 'rules' => [['score' => '1.2', 'description' => 'Missing Date: header']]]),
        str_starts_with($request->url(), 'https://example.com/gone') => Http::response(status: 404),
        default => Http::response(),
    });
    fakeDriver()
        ->used(1200, 50000, perSecond: 14.0, notes: ['The plan\'s volume is the setting\'s.'])
        ->count('2026-11-23', ['sent' => 40, 'delivered' => 38, 'hard_bounces' => 1, 'complaints' => 0, 'unsubscribes' => 1])
        ->suppress(new Suppression('guest@example.org', SuppressionType::Bounce, now()->subDay(), 'mailbox full'));

    // Lists and people: a dynamic list from the app's records, two static
    // lists, an import.
    CampaignList::factory()->dynamic()->create(['key' => 'members', 'name' => 'Members']);
    Member::withCampaignConsent(new ConsentContext(source: 'sign_up', noticeVersion: '2026-11-01'), function (): void {
        foreach (['member', 'second', 'third'] as $name) {
            Member::query()->create(['email' => "{$name}@example.org", 'name' => ucfirst($name), 'country' => 'TR']);
        }
    });
    Campaigns::recordActivity(Member::query()->where('email', 'member@example.org')->firstOrFail());
    answered(CreateListTool::class, ['key' => 'readers', 'name' => 'Readers', 'description' => 'Everyone who reads', 'is_default' => true]);
    answered(CreateListTool::class, ['key' => 'events', 'name' => 'Events', 'double_opt_in' => true, 'basis' => 'soft_opt_in']);
    answered(CreateListTool::class, ['key' => 'walk-ins', 'name' => 'Walk-ins']);
    answered(AddContactsTool::class, ['list' => 'walk-ins', 'basis' => 'consent', 'contacts' => [['email' => 'early@example.org']]]);
    $people = array_map(fn (int $i): array => [
        'email' => "reader-{$i}@example.org",
        'name' => "Reader Number{$i}",
        'first_name' => "Reader {$i}",
        'locale' => $i === 1 ? 'fr' : 'en',
        'country' => 'TR',
        'timezone' => 'Europe/Istanbul',
        'custom_fields' => ['plan' => 'pro', 'seats' => $i],
    ], range(1, 6));
    answered(AddContactsTool::class, ['list' => 'readers', 'basis' => 'consent', 'contacts' => $people]);
    answered(AddContactsTool::class, ['list' => 'events', 'basis' => 'soft_opt_in', 'contacts' => [['email' => 'guest@example.org'], ['email' => 'reader-2@example.org']]]);
    answered(RemoveContactsTool::class, ['list' => 'events', 'emails' => ['reader-2@example.org']]);
    answered(SetContactFieldsTool::class, ['email' => 'reader-3@example.org', 'fields' => ['plan' => 'team', 'beta' => true]]);
    answered(BlockContactTool::class, ['email' => 'guest@example.org']);
    answered(GetSubscriberTool::class, ['email' => 'guest@example.org']);
    answered(UnblockContactTool::class, ['email' => 'guest@example.org']);
    app(SendConfirmationAction::class)->execute(Subscriber::findByEmail('guest@example.org')->subscriptions()->sole());
    preg_match('~https://app\.example\.com/campaigns/c/[A-Za-z0-9]+~', collect(fakeDriver()->sent())->last()->html, $confirm);
    $this->post($confirm[0])->assertOk();
    answered(GetSubscriberTool::class, ['email' => 'guest@example.org']);
    answered(GetSubscriberTool::class, ['email' => 'member@example.org']);
    answered(UnsubscribeContactTool::class, ['email' => 'reader-6@example.org']);
    answered(UpdateListTool::class, ['key' => 'events', 'name' => 'Events and talks', 'description' => 'Talks we give']);
    answered(SaveAudienceTool::class, ['key' => 'english', 'name' => 'English readers', 'description' => 'Read in English', 'filters' => ['lists' => ['readers'], 'locales' => ['en']]]);

    $contacts = contactsUpload("email,first_name,opted_out\nann@example.com,Ann,\nbob@example.com,Bob,yes\nnot-an-address,Nobody,\n");
    answered(PreviewContactsImportTool::class, ['upload_id' => $contacts->uuid, 'source' => 'expo-2026', 'list' => 'readers', 'basis' => 'soft_opt_in']);
    answered(RunContactsImportTool::class, ['upload_id' => $contacts->uuid, 'source' => 'expo-2026', 'list' => 'readers', 'basis' => 'soft_opt_in']);

    answered(CreateUploadLinkTool::class, ['purpose' => UploadPurpose::Image->value, 'file_name' => 'logo.png']);
    answered(AddImageTool::class, ['data' => base64_encode(testImage()), 'name' => 'Logo']);
    answered(GetGuideTool::class);
    answered(GetGuideTool::class, ['resource' => 'members']);

    // A template, its checks none, queued, done with a failure and a
    // skip, then a run that died.
    $html = '<p>Hello {{first_name}}</p><a href="https://app.example.com/offer">Offer</a> <a href="https://example.com/gone">Old</a><img src="https://cdn.example.com/hero.png" alt="Hero">{{footer}}';
    answered(CreateTemplateTool::class, ['key' => 'welcome', 'name' => 'Welcome', 'description' => 'The welcome', 'note' => 'First', 'contents' => [
        ['locale' => 'en', 'subject' => 'Hello {{first_name}}', 'preheader' => 'News inside', 'html' => $html],
        ['locale' => 'fr', 'subject' => 'Bonjour', 'html' => '<p>Bonjour</p>{{footer}}'],
    ]]);
    answered(CreateTemplateTool::class, ['key' => 'plain', 'name' => 'Plain', 'from' => 'starter']);
    answered(GetTemplateTool::class, ['key' => 'welcome']);
    answered(GetTemplateTool::class, ['key' => 'starter']);

    answered(RunChecksTool::class, ['template' => 'welcome']);
    answered(GetTemplateTool::class, ['key' => 'welcome']);
    answered(GetCheckResultsTool::class, ['template' => 'welcome']);

    workQueued(RunChecksJob::class);
    answered(GetTemplateTool::class, ['key' => 'welcome']);
    answered(GetCheckResultsTool::class, ['template' => 'welcome']);
    answered(GetCheckResultsTool::class, ['template' => 'welcome', 'check' => 'links']);
    answered(GetCheckResultsTool::class, ['template' => 'welcome', 'check' => 'spam_score', 'locale' => 'en']);
    answered(ListRevisionsTool::class, ['template' => 'welcome']);
    $this->get(answered(GetPreviewLinkTool::class, ['template' => 'welcome'])['url'])->assertOk()->assertSee('passed, 1 failed.');

    answered(AddRevisionTool::class, ['template' => 'welcome', 'note' => 'Second', 'contents' => [['locale' => 'en', 'subject' => 'Hello again', 'html' => $html]]]);
    answered(RestoreRevisionTool::class, ['template' => 'welcome', 'revision' => 1]);
    answered(RunChecksTool::class, ['template' => 'welcome']);
    dieQueued(RunChecksJob::class);
    answered(GetTemplateTool::class, ['key' => 'welcome', 'revision' => 3]);
    answered(GetCheckResultsTool::class, ['template' => 'welcome']);
    answered(ListRevisionsTool::class, ['template' => 'welcome']);

    // An uploaded email with an image it never shows.
    answered(AddRevisionTool::class, ['template' => 'plain', 'upload_id' => emailUpload(zipOf([
        'email.html' => '<html><head><title>Plain news</title></head><body><img src="images/logo.png" alt="Logo">{{footer}}</body></html>',
        'images/logo.png' => testImage(),
        'images/spare.gif' => testImage('gif'),
    ]))->uuid]);

    answered(UpdateTemplateTool::class, ['key' => 'welcome', 'name' => 'Welcome email', 'is_default' => true]);
    answered(ArchiveTemplateTool::class, ['key' => 'plain']);
    answered(ListTemplatesTool::class, ['include_archived' => true]);

    // Campaigns drafted: the default list, an app's audience, a saved one,
    // a test email that went and one refused, the checks done.
    answered(CreateCampaignTool::class, ['key' => 'march', 'name' => 'March', 'template' => 'welcome', 'note' => 'From the welcome']);
    answered(UpdateCampaignTool::class, ['key' => 'march', 'settings' => [
        'name' => 'March news',
        'reply_to' => 'help@example.com',
        'audience' => ['filters' => ['locales' => ['en', 'fr']]],
        'pace' => ['per_minute' => 10, 'daily_cap' => 500, 'sending_hours' => ['08:00', '20:00'], 'timezone' => 'UTC'],
        'utm' => ['campaign' => 'march'],
    ]]);
    answered(DuplicateCampaignTool::class, ['from' => 'march', 'key' => 'april', 'name' => 'April']);
    answered(UpdateCampaignTool::class, ['key' => 'april', 'settings' => [
        'audience' => ['saved_audience' => 'english', 'emails' => ['reader-3@example.org', 'reader-4@example.org']],
    ]]);
    answered(CreateCampaignTool::class, ['key' => 'may', 'name' => 'May', 'template' => 'welcome', 'settings' => ['lists' => ['walk-ins']]]);
    answered(RunChecksTool::class, ['campaign' => 'march']);
    workQueued(RunChecksJob::class);
    answered(GetCampaignTool::class, ['key' => 'march']);
    answered(GetCampaignTool::class, ['key' => 'april']);
    answered(GetCampaignStatusTool::class, ['key' => 'march']);
    answered(GetCampaignReportTool::class, ['key' => 'march']);
    answered(PreviewAudienceTool::class, ['campaign' => 'april']);
    answered(PreviewAudienceTool::class, ['lists' => ['readers', 'events'], 'audience' => ['filters' => ['locales' => ['en']]]]);
    answered(ListAudiencesTool::class);
    answered(ListAssetsTool::class);

    $tests = answered(SendTestTool::class, ['campaign' => 'march', 'addresses' => ['me@example.com'], 'locales' => ['en', 'fr']]);
    fakeDriver()->failNext(new SendFailed(SendFailure::Rejected, 'The server refused the address.', 550));
    workQueued(SendTestJob::class);
    $test = collect(fakeDriver()->sent())->firstOrFail(fn (OutgoingMessage $email): bool => $email->isTest);
    $this->post((string) $test->unsubscribeUrl, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    app(RecordFeedbackAction::class)->execute(new Feedback(providerEventId: 'test-delivered', type: FeedbackType::Delivered, occurredAt: now(), testSendUuid: $test->uuid, code: 250), 'fake');
    answered(GetTestSendsTool::class, ['tests' => array_column($tests['tests'], 'uuid')]);

    // One campaign sending: started, frozen, sent, delivered, opened,
    // clicked, bounced and unsubscribed from; another whose recipients
    // changed before its freeze.
    answered(StartCampaignTool::class, ['key' => 'march', 'recipients' => answered(PreviewAudienceTool::class, ['campaign' => 'march'])['recipients'], 'pace' => ['per_minute' => 10]]);
    answered(StartCampaignTool::class, ['key' => 'may', 'recipients' => answered(PreviewAudienceTool::class, ['campaign' => 'may'])['recipients']]);
    answered(AddContactsTool::class, ['list' => 'walk-ins', 'basis' => 'consent', 'contacts' => [['email' => 'late@example.org']]]);
    answered(GetCampaignStatusTool::class, ['key' => 'march']);
    workQueued(FreezeCampaignJob::class);
    answered(GetCampaignTool::class, ['key' => 'may']);
    answered(UpdateCampaignTool::class, ['key' => 'may', 'settings' => [
        'lists' => ['walk-ins', 'members'],
        'audience' => ['audience' => ['name' => 'in-country', 'parameters' => ['country' => 'TR']]],
    ]]);

    // The first send's answer is unclear, which halves the pace.
    app(PumpCampaignsAction::class)->execute();
    answered(GetCampaignStatusTool::class, ['key' => 'march']);
    fakeDriver()->failNext(new SendFailed(SendFailure::Unclear, 'The connection dropped.'));
    workQueued(SendMessageJob::class);
    answered(GetCampaignStatusTool::class, ['key' => 'march']);

    $messages = CampaignMessage::query()->where('campaign_id', Campaign::query()->where('key', 'march')->value('id'))->orderBy('id')->get();
    $feedback = [
        ...$messages->map(fn (CampaignMessage $message): Feedback => new Feedback(providerEventId: "delivered-{$message->id}", type: FeedbackType::Delivered, occurredAt: now(), messageUuid: $message->uuid, code: 250)),
        new Feedback(providerEventId: 'bounce', type: FeedbackType::Failed, occurredAt: now(), messageUuid: $messages[0]->uuid, severity: 'permanent', reason: 'bounce', failure: DeliveryFailure::HardBounce, code: 550),
        new Feedback(providerEventId: 'unsubscribed', type: FeedbackType::Unsubscribed, occurredAt: now(), messageUuid: $messages[1]->uuid),
    ];

    foreach ($feedback as $event) {
        app(RecordFeedbackAction::class)->execute($event, 'fake');
    }

    workQueued(ProcessFeedbackJob::class);
    $this->travel(2)->minutes();

    foreach (fakeDriver()->sent() as $email) {
        if ($email->tag === 'march') {
            readInBrowser($email);
        }
    }

    $this->travel(ProcessFeedbackJob::ENGAGEMENT_DELAY + 60)->seconds();
    workQueued(ProcessFeedbackJob::class);

    answered(GetCampaignTool::class, ['key' => 'march']);
    answered(GetCampaignStatusTool::class, ['key' => 'march']);
    answered(GetCampaignReportTool::class, ['key' => 'march']);
    answered(ListCampaignsTool::class, ['statuses' => ['sending']]);
    answered(OverviewTool::class);
    answered(GetSubscriberTool::class, ['email' => 'reader-2@example.org']);
    answered(GetSubscriberTool::class, ['email' => (string) $messages[0]->email]);
    answered(ListSubscribersTool::class, ['filters' => ['lists' => ['readers']], 'per_page' => 3]);
    answered(ListListsTool::class);

    answered(SlowDownCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 5]]);
    answered(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 20]]);
    answered(PauseCampaignTool::class, ['key' => 'march', 'reason' => 'Checking the numbers']);
    answered(GetCampaignStatusTool::class, ['key' => 'march']);
    answered(OverviewTool::class);
    answered(ResumeCampaignTool::class, ['key' => 'march']);

    // Completed: every email settled, the pump closes it, its report
    // frozen.
    app(PumpCampaignsAction::class)->execute();
    workQueued(SendMessageJob::class);
    app(FreezeReportAction::class)->execute(Campaign::query()->where('key', 'march')->firstOrFail());
    answered(GetCampaignTool::class, ['key' => 'march']);
    answered(GetCampaignStatusTool::class, ['key' => 'march']);
    answered(GetCampaignReportTool::class, ['key' => 'march']);

    // Another scheduled, then canceled; a list archived.
    answered(StartCampaignTool::class, ['key' => 'april', 'recipients' => answered(PreviewAudienceTool::class, ['campaign' => 'april'])['recipients'], 'at' => now()->addDay()->toIso8601String()]);
    workQueued(FreezeCampaignJob::class);
    answered(GetCampaignStatusTool::class, ['key' => 'april']);
    answered(OverviewTool::class);
    answered(CancelCampaignTool::class, ['key' => 'april']);
    answered(GetCampaignTool::class, ['key' => 'april']);
    answered(ListCampaignsTool::class);
    answered(ArchiveListTool::class, ['key' => 'events']);
    answered(ListListsTool::class, ['include_archived' => true]);

    // Sending switched off, which the overview notes.
    config(['campaigns.enabled' => false]);
    answered(OverviewTool::class);

    expect(Campaign::query()->pluck('status', 'key')->map->value->all())
        ->toMatchArray(['march' => 'completed', 'april' => 'canceled', 'may' => 'draft']);

    $missing = array_values(array_diff(toolClasses(), array_keys(SchemaCheckedServer::$answered)));
    expect($missing)->toBe([], 'These tools gave no answer to check: '.implode(', ', $missing));

    // Every other field that may be null, and every list, held a value at
    // least once. These stay null on purpose: verdict reasons Google's
    // compliance answer here doesn't give, the reading's note (Gmail unread
    // or reporting no day) and the unblock check's error (the provider
    // unread).
    expect(neverFilled())->toBe([
        '.gmail.compliance.registrable_domain.honor_unsubscribe.reason',
        '.gmail.compliance.registrable_domain.one_click_unsubscribe.reason',
        '.gmail.compliance.subdomain.one_click_unsubscribe.reason',
        '.gmail.note',
        '.provider.error',
    ]);
})->with('databases');
