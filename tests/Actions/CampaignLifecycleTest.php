<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\Actions\AddRevisionAction;
use Mozex\Campaigns\Actions\CancelCampaignAction;
use Mozex\Campaigns\Actions\ChangePaceAction;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Actions\FreezeCampaignAction;
use Mozex\Campaigns\Actions\PauseCampaignAction;
use Mozex\Campaigns\Actions\ResumeCampaignAction;
use Mozex\Campaigns\Actions\StartCampaignAction;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Contracts\Audience;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Events\CampaignPausedEvent;
use Mozex\Campaigns\Events\CampaignStartedEvent;
use Mozex\Campaigns\Jobs\FreezeCampaignJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Tests\Fixtures\MembersInCountry;

beforeEach(function (): void {
    sendFromExample();
    config()->set('app.url', 'https://www.example.com');
});

/**
 * A draft campaign to the "readers" list, with the given email.
 *
 * @param  list<EmailContent>  $contents
 * @param  array<string, mixed>  $settings
 */
function readersCampaign(array $contents = [], array $settings = []): Campaign
{
    CampaignList::query()->where('key', 'readers')->first() ?? CampaignList::factory()->create(['key' => 'readers']);

    return app(CreateCampaignAction::class)->execute('news', 'News', EmailSource::contents($contents ?: [campaignEmail()]), ['lists' => ['readers'], ...$settings])->campaign;
}

function readers(): CampaignList
{
    return CampaignList::query()->where('key', 'readers')->sole();
}

describe('starting a campaign', function (): void {
    it('freezes the engaged first, then takes mailbox providers in turn', function (string $driver): void {
        useDatabase($driver);
        $this->freezeTime();
        $campaign = readersCampaign();

        // Made out of the send order, so only the positions can order them.
        foreach ([
            ['silent-other@example.org', null],
            ['active-gmail@gmail.com', 200],
            ['engaged-other@example.org', 10],
            ['engaged-gmail-1@gmail.com', 10],
            ['silent-gmail@gmail.com', null],
            ['engaged-gmail-2@googlemail.com', 10],
            ['engaged-microsoft@hotmail.co.uk', 10],
            ['engaged-gmail-3@gmail.com', 10],
        ] as [$email, $daysAgo]) {
            subscriberOn([readers()], ['email' => $email, 'last_clicked_at' => $daysAgo === null ? null : now()->subDays($daysAgo)]);
        }

        app(StartCampaignAction::class)->execute($campaign, 8);

        expect(CampaignMessage::query()->orderBy('position')->pluck('email')->all())->toBe([
            'engaged-gmail-1@gmail.com',
            'engaged-microsoft@hotmail.co.uk',
            'engaged-other@example.org',
            'engaged-gmail-2@googlemail.com',
            'engaged-gmail-3@gmail.com',
            'active-gmail@gmail.com',
            'silent-gmail@gmail.com',
            'silent-other@example.org',
        ])
            ->and(CampaignMessage::query()->max('position'))->toBeGreaterThan(2 * FreezeCampaignAction::TIER_STEP);
    })->with('databases');

    it('sends the email with UTM tags on links to the app\'s own sites', function (string $driver): void {
        useDatabase($driver);
        $campaign = readersCampaign([
            campaignEmail(html: '<a href="https://www.example.com/sale?a=1&amp;b=2#top">Sale</a> <a href="https://shop.example.com/">Shop</a> '
                .'<a href="https://other.org/">Other</a> <a href="https://example.com/?utm_source=mine">Mine</a> <a href="{{web_version_url}}">View</a>{{footer}}'),
            campaignEmail('fr', '<a href="https://example.com/fr">Promo</a>{{footer}}', "Promo: https://example.com/fr.\n{{unsubscribe_url}}"),
        ], ['utm' => ['content' => 'hero']]);
        subscriberOn([readers()]);
        $authored = $campaign->currentRevision()->sole();
        $authored->forceFill(['check_results' => ['status' => 'done', 'passed' => true]])->save();

        app(StartCampaignAction::class)->execute($campaign, 1);
        $frozen = $campaign->fresh()->frozenRevision;
        $tags = 'utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=news&amp;utm_content=hero';

        expect($frozen)
            ->number->toBe(2)
            ->frozen_from_id->toBe($authored->id)
            ->check_results->toEqual(['status' => 'done', 'passed' => true])
            ->and($frozen->authored()->id)->toBe($authored->id)
            ->and($frozen->contentFor('en')->html)->toBe(
                "<a href=\"https://www.example.com/sale?a=1&amp;b=2&amp;{$tags}#top\">Sale</a> <a href=\"https://shop.example.com/?{$tags}\">Shop</a> "
                .'<a href="https://other.org/">Other</a> <a href="https://example.com/?utm_source=mine">Mine</a> <a href="{{web_version_url}}">View</a>{{footer}}'
            )
            ->and($frozen->contentFor('en')->text)->toContain('https://www.example.com/sale?a=1&b=2&'.str_replace('&amp;', '&', $tags).'#top')
            ->and($frozen->contentFor('en')->text_generated)->toBeTrue()
            ->and($frozen->contentFor('fr')->text)->toBe('Promo: https://example.com/fr?'.str_replace('&amp;', '&', $tags).".\n{{unsubscribe_url}}")
            // A row per address its tracked links lead to, tags included, in
            // every language; the placeholder's link is never wrapped.
            ->and(CampaignLink::query()->where('revision_id', $frozen->id)->pluck('url')->sort()->values()->all())->toBe([
                'https://example.com/?utm_source=mine',
                'https://example.com/fr?'.str_replace('&amp;', '&', $tags),
                'https://other.org/',
                'https://shop.example.com/?'.str_replace('&amp;', '&', $tags),
                'https://www.example.com/sale?a=1&b=2&'.str_replace('&amp;', '&', $tags).'#top',
            ])
            ->and(CampaignLink::query()->where('revision_id', '!=', $frozen->id)->count())->toBe(0);
    })->with('databases');

    it('sends the current revision itself when there is nothing to tag, keeping the link rows a test email made of it', function (string $driver): void {
        useDatabase($driver);
        $campaign = readersCampaign([campaignEmail(html: '<a href="https://example.com/a">A</a><a href="https://example.com/b">B</a>{{footer}}')], ['utm' => false]);
        subscriberOn([readers()]);
        $current = $campaign->currentRevision()->sole();
        // A test email composed first made the row for one address.
        CampaignLink::factory()->create(['revision_id' => $current->id, 'url' => 'https://example.com/a', 'url_hash' => CampaignLink::hash('https://example.com/a'), 'key' => str_repeat('a', 22)]);

        app(StartCampaignAction::class)->execute($campaign, 1);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Sending)
            ->frozen_revision_id->toBe($current->id)
            ->and(CampaignLink::query()->where('revision_id', $current->id)->orderBy('url')->pluck('key', 'url')->all())
            ->toHaveKeys(['https://example.com/a', 'https://example.com/b'])
            ->toHaveCount(2)
            ->and(CampaignLink::query()->where('url', 'https://example.com/a')->value('key'))->toBe(str_repeat('a', 22));
    })->with('databases');

    it('goes back to a draft, leaving nothing, when the audience changed', function (string $driver): void {
        useDatabase($driver);
        Event::fake([CampaignStartedEvent::class]);
        $campaign = readersCampaign([campaignEmail(html: '<a href="https://example.com/">Hi</a>{{footer}}')]);
        subscriberOn([readers()]);
        subscriberOn([readers()]);

        app(StartCampaignAction::class)->execute($campaign, 1);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Draft)
            ->start_error->toBe('The audience now holds 2 recipients, not the 1 confirmed for the start. Preview it again, and start with the new number if it is still right.')
            ->frozen_revision_id->toBeNull()
            ->recipient_count->toBeNull()
            ->and(CampaignMessage::query()->count())->toBe(0)
            ->and(Revision::query()->where('revisable_id', $campaign->id)->count())->toBe(1)
            ->and(CampaignLink::query()->count())->toBe(0);

        Event::assertNotDispatched(CampaignStartedEvent::class);

        app(StartCampaignAction::class)->execute($campaign->fresh(), 2);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Sending)
            ->start_error->toBeNull()
            ->recipient_count->toBe(2)
            ->started_at->not->toBeNull()
            ->pace_started_at->not->toBeNull();

        Event::assertDispatched(CampaignStartedEvent::class, fn (CampaignStartedEvent $event): bool => $event->campaign->is($campaign));
    })->with('databases');

    it('refuses a start that can\'t work, in the call', function (Closure $arrange, int $count, string $message): void {
        Queue::fake();
        $campaign = readersCampaign();
        $arrange($campaign);

        expect(fn () => app(StartCampaignAction::class)->execute($campaign->fresh(), $count, $count === 3 ? now()->addYear()->addDay() : null))
            ->toThrow(CampaignsException::class, $message)
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft);

        Queue::assertNothingPushed();
    })->with([
        'sending switched off' => [fn () => config()->set('campaigns.enabled', false), 1, 'Sending is switched off'],
        'no recipient count' => [fn () => null, 0, 'at least 1'],
        'a year and more ahead' => [fn () => null, 3, 'at most a year ahead'],
        'no target lists' => [fn (Campaign $campaign) => $campaign->forceFill(['target_lists' => null])->save(), 1, 'no target lists'],
        'a From off the domain' => [fn (Campaign $campaign) => $campaign->forceFill(['from_email' => 'news@example.com'])->save(), 1, 'must be on the sending domain'],
        // Links kept out of click tracking, so the tags lengthen the email.
        'tags that push it past 90 KB' => [fn (Campaign $campaign) => app(AddRevisionAction::class)->execute($campaign, [
            campaignEmail(html: str_repeat('<a href="https://example.com/" data-campaigns-no-track>x</a>', 2400).'{{footer}}'),
        ]), 1, 'over the 90 KB limit'],
    ]);

    it('refuses a named audience whose parameters no longer pass', function (): void {
        config()->set('campaigns.audiences', ['in-country' => MembersInCountry::class]);
        $campaign = readersCampaign();
        $campaign->forceFill(['audience' => ['audience' => ['name' => 'in-country', 'parameters' => ['country' => 'France']]]])->save();

        expect(fn () => app(StartCampaignAction::class)->execute($campaign, 1))->toThrow(ValidationException::class);
    });

    it('claims a draft once, so a second start is refused', function (): void {
        Queue::fake();
        $campaign = readersCampaign();

        app(StartCampaignAction::class)->execute($campaign, 1);

        expect(fn () => app(StartCampaignAction::class)->execute($campaign, 1))
            ->toThrow(CampaignsException::class, 'A starting campaign can\'t be started.');

        Queue::assertPushed(FreezeCampaignJob::class, 1);
    });

    it('makes a start wait for an edit holding the campaign\'s row', function (string $driver): void {
        useDatabase($driver);
        Queue::fake();
        $campaign = readersCampaign();

        config()->set('database.connections.second', config("database.connections.{$driver}"));
        $second = DB::connection('second');
        $second->beginTransaction();
        $second->table('campaigns')->where('id', $campaign->id)->lockForUpdate()->first();

        DB::statement($driver === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");

        try {
            expect(fn () => app(StartCampaignAction::class)->execute($campaign, 1))
                ->toThrow(function (QueryException $exception): void {
                    expect(strtolower($exception->getSql()))->toContain('for update');
                });
        } finally {
            $second->rollBack();
            DB::purge('second');
        }

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
        Queue::assertNothingPushed();
    })->with(['mysql', 'pgsql']);

    it('hands the campaign back when its freeze job fails, and only for its own start', function (): void {
        Queue::fake();
        $campaign = readersCampaign();
        subscriberOn([readers()]);
        app(StartCampaignAction::class)->execute($campaign, 1);
        $stale = new FreezeCampaignJob($campaign->id, 1, (string) Str::uuid());

        $stale->handle(app(FreezeCampaignAction::class));
        $stale->failed(new RuntimeException('Timed out'));

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Starting)
            ->and(CampaignMessage::query()->count())->toBe(0);

        (new FreezeCampaignJob($campaign->id, 1, $campaign->fresh()->start_run))->failed(new RuntimeException('Timed out'));

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Draft)
            ->start_error->toContain('Its start stopped before the audience was frozen');
    });

    // Not on SQLite, which reads an unknown quoted column as a string.
    it('keeps the values of a failed query out of every message', function (string $driver): void {
        useDatabase($driver);
        $audience = new class implements Audience
        {
            public function description(): string
            {
                return 'Broken.';
            }

            public function parameters(JsonSchema $schema): array
            {
                return [];
            }

            public function rules(): array
            {
                return [];
            }

            /** @return Builder<Subscriber> */
            public function query(array $parameters): Builder
            {
                return Subscriber::query()->where('no_such_column', 'secret@example.com');
            }
        };
        app()->instance($audience::class, $audience);
        config()->set('campaigns.audiences', ['broken' => $audience::class]);
        $campaign = readersCampaign(settings: ['audience' => ['audience' => ['name' => 'broken', 'parameters' => []]]]);

        expect(fn () => app(StartCampaignAction::class)->execute($campaign, 1))
            ->toThrow(function (CampaignsException $exception): void {
                expect($exception->getMessage())->toContain('SQLSTATE')
                    ->not->toContain('secret@example.com')
                    ->and($exception->getPrevious())->toBeNull();
            })
            ->and($campaign->fresh())
            ->status->toBe(CampaignStatus::Draft)
            ->start_error->toStartWith('The database refused part of the campaign\'s freeze (SQLSTATE');
    })->with(['mysql', 'pgsql']);
});

describe('scheduling a campaign', function (): void {
    it('freezes it now and waits for its time, pausable and resumable before it', function (string $driver): void {
        useDatabase($driver);
        Event::fake([CampaignStartedEvent::class, CampaignPausedEvent::class]);
        $campaign = readersCampaign();
        subscriberOn([readers()]);
        // In another timezone than the app's, which must not move the instant.
        $at = now('America/New_York')->addDay()->startOfSecond();

        app(StartCampaignAction::class)->execute($campaign, 1, $at, ['per_minute' => 5]);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Scheduled)
            ->scheduled_at->toEqual($at)
            ->started_at->toBeNull()
            ->pace_started_at->toBeNull()
            ->pace->toBe(['per_minute' => 5])
            ->and(CampaignMessage::query()->count())->toBe(1);

        app(PauseCampaignAction::class)->execute($campaign);
        app(ResumeCampaignAction::class)->execute($campaign);

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);
        Event::assertNotDispatched(CampaignStartedEvent::class);

        app(PauseCampaignAction::class)->execute($campaign);
        // In UTC: some Carbon versions read a mocked now in another timezone
        // by its wall time, hours off.
        $this->travelTo($at->copy()->utc()->addMinute());
        app(ResumeCampaignAction::class)->execute($campaign);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Sending)
            ->started_at->toEqual(now()->startOfSecond())
            ->pace_started_at->toEqual(now()->startOfSecond());

        Event::assertDispatchedTimes(CampaignStartedEvent::class, 1);
        Event::assertDispatchedTimes(CampaignPausedEvent::class, 2);
    })->with('databases');
});

describe('pausing, resuming and cancelling', function (): void {
    it('pauses with a reason, and says whether the package did it', function (): void {
        Event::fake([CampaignPausedEvent::class]);
        $campaign = Campaign::factory()->sending()->create();

        app(PauseCampaignAction::class)->execute($campaign, 'Too many bounces', automatic: true);
        app(PauseCampaignAction::class)->execute($campaign);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Paused)
            ->pause_reason->toBe('Too many bounces')
            ->and(fn () => app(PauseCampaignAction::class)->execute(Campaign::factory()->create()))
            ->toThrow(CampaignsException::class, 'A draft campaign can\'t be paused.');

        Event::assertDispatchedTimes(CampaignPausedEvent::class, 1);
        Event::assertDispatched(CampaignPausedEvent::class, fn (CampaignPausedEvent $event): bool => $event->automatic && $event->reason === 'Too many bounces');
    });

    it('pauses and resumes whatever an app\'s listener on their events throws, and reports it', function (): void {
        Exceptions::fake();
        Event::listen(CampaignPausedEvent::class, fn (): never => throw new LogicException('The pause listener broke.'));
        Event::listen(CampaignStartedEvent::class, fn (): never => throw new LogicException('The start listener broke.'));
        $campaign = Campaign::factory()->sending()->create(['started_at' => null]);

        // Thrown, the change would stand while the caller heard it failed,
        // and an agent retrying a resume would be refused.
        $paused = app(PauseCampaignAction::class)->execute($campaign);
        $resumed = app(ResumeCampaignAction::class)->execute($campaign->fresh());

        expect($paused->status)->toBe(CampaignStatus::Paused)
            ->and($resumed->fresh()?->status)->toBe(CampaignStatus::Sending);

        Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'The pause listener broke.');
        Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'The start listener broke.');
    });

    it('resumes where it stopped, restarting the pace after more than a day', function (): void {
        $this->freezeTime();
        $started = now()->subDays(3)->startOfSecond();
        $campaign = Campaign::factory()->sending()->create(['started_at' => $started, 'pace_started_at' => $started]);

        app(PauseCampaignAction::class)->execute($campaign, 'A look');
        $this->travel(2)->hours();
        app(ResumeCampaignAction::class)->execute($campaign);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Sending)
            ->pause_reason->toBeNull()
            ->pace_started_at->toEqual($started);

        app(PauseCampaignAction::class)->execute($campaign);
        $this->travel(25)->hours();
        config()->set('campaigns.enabled', false);

        expect(fn () => app(ResumeCampaignAction::class)->execute($campaign))->toThrow(CampaignsException::class, 'Sending is switched off');

        config()->set('campaigns.enabled', true);
        app(ResumeCampaignAction::class)->execute($campaign);

        expect($campaign->fresh()->pace_started_at)->toEqual(now()->startOfSecond())
            ->and($campaign->fresh()->started_at)->toEqual($started->startOfSecond());
    });

    it('cancels a campaign in any status but completed', function (CampaignStatus $status): void {
        config()->set('campaigns.enabled', false);
        $campaign = Campaign::factory()->create(['status' => $status]);

        app(CancelCampaignAction::class)->execute($campaign);

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Canceled);
    })->with([CampaignStatus::Draft, CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused]);

    it('cancels a campaign being started, whose freeze then leaves nothing', function (): void {
        Queue::fake();
        $campaign = readersCampaign();
        subscriberOn([readers()]);
        app(StartCampaignAction::class)->execute($campaign, 1);
        config()->set('campaigns.enabled', false);

        app(CancelCampaignAction::class)->execute($campaign);
        app(CancelCampaignAction::class)->execute($campaign);
        app(FreezeCampaignAction::class)->execute($campaign, 1, $campaign->fresh()->start_run);

        expect($campaign->fresh())
            ->status->toBe(CampaignStatus::Canceled)
            ->canceled_at->not->toBeNull()
            ->and(CampaignMessage::query()->count())->toBe(0)
            ->and(fn () => app(CancelCampaignAction::class)->execute(Campaign::factory()->create(['status' => CampaignStatus::Completed])))
            ->toThrow(CampaignsException::class, 'A completed campaign can\'t be cancelled.');
    });
});

describe('changing the pace', function (): void {
    it('slows down while sending is off, and refuses anything faster', function (array $from, array $to, bool $faster): void {
        config()->set('campaigns.enabled', false);
        $campaign = Campaign::factory()->sending()->create(['pace' => $from]);

        $change = fn () => app(ChangePaceAction::class)->execute($campaign, $to, faster: false);

        $faster
            ? expect($change)->toThrow(CampaignsException::class, 'it is a speed-up, not a slow-down')
            : expect($change()->pace)->toBe([...$from, ...$to]);
    })->with([
        'a lower rate' => [['per_minute' => 10], ['per_minute' => 5], false],
        'a higher rate' => [['per_minute' => 10], ['per_minute' => 11], true],
        'more growth' => [[], ['daily_growth' => 50], true],
        'a cap set' => [[], ['daily_cap' => 500], false],
        'a cap lifted' => [['daily_cap' => 500], ['daily_cap' => null], true],
        'a higher cap' => [['daily_cap' => 500], ['daily_cap' => 501], true],
        'hours where there were none' => [[], ['sending_hours' => ['09:00', '17:00']], false],
        'narrower hours' => [['sending_hours' => ['09:00', '17:00']], ['sending_hours' => ['10:00', '12:00']], false],
        'wider hours' => [['sending_hours' => ['09:00', '17:00']], ['sending_hours' => ['08:00', '12:00']], true],
        'hours inside a window past midnight' => [['sending_hours' => ['22:00', '06:00']], ['sending_hours' => ['23:30', '01:00']], false],
        'hours outside it' => [['sending_hours' => ['22:00', '06:00']], ['sending_hours' => ['05:00', '07:00']], true],
        'hours lifted' => [['sending_hours' => ['09:00', '17:00']], ['sending_hours' => null], true],
        'another timezone' => [['sending_hours' => ['09:00', '17:00']], ['timezone' => 'Asia/Tokyo'], true],
    ]);

    it('speeds up only while sending is on', function (): void {
        $campaign = Campaign::factory()->sending()->create(['pace' => ['per_minute' => 10]]);

        expect(app(ChangePaceAction::class)->execute($campaign, ['per_minute' => 30], faster: true)->pace)->toBe(['per_minute' => 30]);

        config()->set('campaigns.enabled', false);

        expect(fn () => app(ChangePaceAction::class)->execute($campaign, ['per_minute' => 40], faster: true))
            ->toThrow(CampaignsException::class, 'Sending is switched off')
            ->and(fn () => app(ChangePaceAction::class)->execute(Campaign::factory()->create(['status' => CampaignStatus::Completed]), ['per_minute' => 1], faster: false))
            ->toThrow(CampaignsException::class, 'A completed campaign can\'t be given a new pace.');
    });
});

it('lists the recipients\' languages for the pre-send checks', function (): void {
    $campaign = readersCampaign();
    subscriberOn([readers()], ['locale' => 'fr']);
    subscriberOn([readers()], ['locale' => null]);
    subscriberOn([readers()], ['locale' => 'de', 'opted_out_at' => now()]);
    subscriberOn([readers()], ['locale' => 'fr']);

    expect(CampaignAudience::for($campaign)->locales())->toBe(['fr']);
});
