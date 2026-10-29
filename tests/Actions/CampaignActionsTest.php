<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\Actions\AddRevisionAction;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Actions\CreateTemplateAction;
use Mozex\Campaigns\Actions\CreateUploadAction;
use Mozex\Campaigns\Actions\DuplicateCampaignAction;
use Mozex\Campaigns\Actions\ReceiveUploadAction;
use Mozex\Campaigns\Actions\SaveAudienceAction;
use Mozex\Campaigns\Actions\SetCustomFieldsAction;
use Mozex\Campaigns\Actions\StartCampaignAction;
use Mozex\Campaigns\Actions\UpdateCampaignAction;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Tests\Fixtures\MembersInCountry;

beforeEach(function (): void {
    sendFromExample();
    config()->set('campaigns.mail.reply_to', 'help@example.com');
});

describe('creating a campaign', function (): void {
    it('starts a draft from the sender, with its settings and its email', function (): void {
        $list = CampaignList::factory()->create(['key' => 'readers']);

        $created = app(CreateCampaignAction::class)->execute(
            key: 'march-news',
            name: ' March news ',
            email: EmailSource::contents([campaignEmail(), campaignEmail('fr', '<p>Bonjour</p>{{footer}}')]),
            settings: [
                'lists' => ['readers'],
                'track_opens' => false,
                'utm' => ['source' => 'digest'],
                'pace' => ['first_day' => 50, 'daily_cap' => null],
                'thresholds' => ['complaints' => 1],
                'audience' => ['filters' => ['locales' => ['fr']]],
            ],
            note: 'First draft',
            author: 'laptop',
        );

        expect($created->campaign)
            ->key->toBe('march-news')
            ->name->toBe('March news')
            ->status->toBe(CampaignStatus::Draft)
            ->from_email->toBe('news@news.example.com')
            ->from_name->toBe('Example News')
            ->reply_to->toBe('help@example.com')
            ->tag->toBe('march-news')
            ->track_opens->toBeFalse()
            ->track_clicks->toBeTrue()
            ->target_lists->toBe([$list->id])
            ->utm->toBe(['source' => 'digest'])
            ->pace->toBe(['first_day' => 50, 'daily_cap' => null])
            ->thresholds->toBe(['complaints' => 1])
            ->audience->toEqual(['filters' => ['locales' => ['fr']]])
            ->template_id->toBeNull()
            ->and($created->revision)
            ->number->toBe(1)
            ->note->toBe('First draft')
            ->and($created->revision->locales())->toBe(['en', 'fr'])
            ->and($created->campaign->utmTags())->toBe(['source' => 'digest', 'medium' => 'email', 'campaign' => 'march-news']);
    });

    it('copies a template\'s current email, or a starter\'s', function (): void {
        $template = app(CreateTemplateAction::class)->execute('monthly', 'Monthly', [campaignEmail()]);
        app(AddRevisionAction::class)->execute($template, [campaignEmail(html: '<p>Latest</p>{{footer}}')]);

        $fromTemplate = app(CreateCampaignAction::class)->execute('one', 'One', EmailSource::template('monthly'));
        $fromStarter = app(CreateCampaignAction::class)->execute('two', 'Two', EmailSource::template('starter'));

        expect($fromTemplate->campaign->template_id)->toBe($template->id)
            ->and($fromTemplate->revision->contentFor('en')->html)->toBe('<p>Latest</p>{{footer}}')
            ->and($fromStarter->campaign->template_id)->toBeNull()
            ->and($fromStarter->revision->contentFor('en')->html)->toContain('{{footer}}');
    });

    it('stores an uploaded email\'s images with its first revision', function (): void {
        Storage::fake('local');
        Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        app(ReceiveUploadAction::class)->execute($upload->uuid, zipOf([
            'email.html' => '<html><head><title>Hello</title></head><body><img src="images/a.png" alt="A">{{footer}}</body></html>',
            'images/a.png' => testImage('png'),
            'images/unused.gif' => testImage('gif'),
        ]));

        $created = app(CreateCampaignAction::class)->execute('zipped', 'Zipped', EmailSource::upload($upload->uuid));

        expect($created->assets)->toHaveCount(1)
            ->and($created->unusedImages)->toBe(['images/unused.gif'])
            ->and($created->revision->contentFor('en')->html)->toContain($created->assets[0]->url)
            ->and($created->revision->contentFor('en')->subject)->toBe('Hello');
    });

    it('refuses before using up the upload, and leaves nothing', function (array $settings, string $message): void {
        Storage::fake('local');
        Campaign::factory()->create(['key' => 'taken']);
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        app(ReceiveUploadAction::class)->execute($upload->uuid, '<html><body>Hi {{footer}}</body></html>');

        expect(fn () => app(CreateCampaignAction::class)->execute($settings['key'] ?? 'fresh', 'Name', EmailSource::upload($upload->uuid), $settings['settings'] ?? []))
            ->toThrow(CampaignsException::class, $message)
            ->and(Upload::query()->sole()->claimed_at)->toBeNull()
            ->and(Campaign::query()->count())->toBe(1);
    })->with([
        'a taken key' => [['key' => 'taken'], 'A campaign with the key "taken" already exists.'],
        'the test emails\' tag' => [['key' => 'campaigns-test'], 'the tag every test email carries'],
        'a From off the domain' => [['settings' => ['from_email' => 'news@example.com']], 'must be on the sending domain, news.example.com'],
        'an unknown list' => [['settings' => ['lists' => ['nope']]], 'No list has the key "nope".'],
        'an unknown setting' => [['settings' => ['colour' => 'red']], 'there is no such setting'],
    ]);

    it('refuses settings it can\'t use', function (array $settings, string $message): void {
        CampaignList::factory()->create(['key' => 'readers']);

        expect(fn () => app(CreateCampaignAction::class)->execute('news', 'News', EmailSource::contents([campaignEmail()]), $settings))
            ->toThrow(CampaignsException::class, $message)
            ->and(Campaign::query()->count())->toBe(0);
    })->with([
        'a name over two lines' => [['from_name' => "Example\nBcc: x@example.com"], 'it must fit on one line'],
        'a bad reply-to' => [['reply_to' => 'nobody'], 'reply_to can\'t be used'],
        'no lists' => [['lists' => []], 'one or more list keys'],
        'a text flag' => [['track_clicks' => 'yes'], 'it is true or false'],
        'an unknown UTM tag' => [['utm' => ['id' => 'x']], 'its tags are source, medium, campaign, content, term'],
        'a pace setting it lacks' => [['pace' => ['speed' => 1]], 'there is no such setting'],
        'a pace below one' => [['pace' => ['per_minute' => 0]], 'a whole number from 1 to 10000'],
        'midnight hours' => [['pace' => ['sending_hours' => ['09:00', '09:00']]], 'a start and an end time'],
        'a timezone it lacks' => [['pace' => ['timezone' => 'Mars/Olympus']], 'a timezone name'],
        'an unknown threshold' => [['thresholds' => ['bounces' => 1]], 'there is no threshold "bounces"'],
        'a negative threshold' => [['thresholds' => ['complaints' => -1]], 'complaints is a number of at least 0'],
        'an unknown audience part' => [['audience' => ['people' => []]], 'there is no such part'],
        'an audience the app lacks' => [['audience' => ['audience' => ['name' => 'buyers', 'parameters' => []]]], 'Nothing is configured as "buyers"'],
        'an unknown saved audience' => [['audience' => ['saved_audience' => 'fans']], 'no saved audience has that key'],
        'a filter on an unknown list' => [['lists' => ['readers'], 'audience' => ['filters' => ['lists' => ['nope']]]], 'No list has the key "nope".'],
        'an outcome of an unknown campaign' => [['audience' => ['filters' => ['campaigns' => [['campaign' => 'old', 'outcome' => 'opened']]]]], 'No campaign has the key "old".'],
        'a bad outcome' => [['audience' => ['filters' => ['campaigns' => [['campaign' => 'old', 'outcome' => 'liked']]]]], 'not_clicked'],
        'a bad address' => [['audience' => ['emails' => ['nobody']]], 'one of them isn\'t an email address'],
        'records of a list no source feeds' => [['audience' => ['records' => ['vips' => [1]]]], 'No list source is configured for the list "vips"'],
        'text ids for records keyed by numbers' => [['audience' => ['records' => ['members' => ['abc']]]], 'the "members" records\' ids are whole numbers'],
    ]);

    it('checks a named audience\'s parameters when the audience is set', function (): void {
        config()->set('campaigns.audiences', ['in-country' => MembersInCountry::class]);
        CampaignList::factory()->create(['key' => 'readers']);

        expect(fn () => app(CreateCampaignAction::class)->execute('news', 'News', EmailSource::contents([campaignEmail()]), [
            'lists' => ['readers'],
            'audience' => ['audience' => ['name' => 'in-country', 'parameters' => ['country' => 'France']]],
        ]))->toThrow(ValidationException::class);
    });

    it('keeps addresses out of the stored audience', function (): void {
        $list = CampaignList::factory()->create(['key' => 'readers']);
        $known = subscriberOn([$list], ['email' => 'ana@example.com']);

        $campaign = app(CreateCampaignAction::class)->execute('news', 'News', EmailSource::contents([campaignEmail()]), [
            'lists' => ['readers'],
            'audience' => ['emails' => [' ANA@example.com', 'ana@example.com', 'ghost@example.com'], 'subscribers' => [$known->id]],
        ])->campaign;

        $nobody = app(CreateCampaignAction::class)->execute('nobody', 'Nobody', EmailSource::contents([campaignEmail()]), [
            'lists' => ['readers'],
            'audience' => ['emails' => ['ghost@example.com']],
        ])->campaign;

        expect($campaign->audience)->toBe(['subscribers' => [$known->id], 'unmatched_emails' => 1])
            ->and(json_encode($campaign->audience))->not->toContain('@')
            ->and($nobody->audience)->toBe(['subscribers' => [], 'unmatched_emails' => 1])
            ->and(CampaignAudience::for($nobody)->preview()->recipients)->toBe(0);
    });
});

describe('changing a campaign', function (): void {
    it('changes only the settings given, merging the pace', function (): void {
        $campaign = Campaign::factory()->create(['pace' => ['first_day' => 50], 'utm' => ['source' => 'x']]);

        app(UpdateCampaignAction::class)->execute($campaign, ['pace' => ['per_minute' => 5], 'utm' => false, 'name' => 'Renamed']);

        expect($campaign->fresh())
            ->pace->toBe(['first_day' => 50, 'per_minute' => 5])
            ->utm->toBe([])
            ->name->toBe('Renamed')
            ->and($campaign->fresh()->utmTags())->toBe([]);
    });

    it('changes nothing once the campaign has left its draft', function (): void {
        $campaign = Campaign::factory()->sending()->create();

        expect(fn () => app(UpdateCampaignAction::class)->execute($campaign, ['name' => 'Renamed']))
            ->toThrow(CampaignsException::class, 'A sending campaign can\'t be changed.');
    });
});

describe('duplicating a campaign', function (): void {
    it('copies the settings and the email as its author made it, and the start tags the new key', function (string $driver): void {
        useDatabase($driver);
        config()->set('app.url', 'https://shop.example.com');
        $list = CampaignList::factory()->create(['key' => 'readers']);
        subscriberOn([$list]);
        $source = app(CreateCampaignAction::class)->execute('spring', 'Spring', EmailSource::contents([
            campaignEmail(html: '<a href="https://shop.example.com/sale">Sale</a>{{footer}}'),
        ]), ['lists' => ['readers'], 'pace' => ['first_day' => 30]])->campaign;
        app(StartCampaignAction::class)->execute($source, 1);

        $copy = app(DuplicateCampaignAction::class)->execute($source->fresh(), 'spring-again', 'Spring again', 'laptop');

        expect($copy->status)->toBe(CampaignStatus::Draft);

        app(StartCampaignAction::class)->execute($copy, 1);
        $sentHtml = fn (Campaign $campaign): string => $campaign->fresh()->frozenRevision->contentFor('en')->html;

        expect($copy)
            ->duplicated_from_id->toBe($source->id)
            ->target_lists->toBe([$list->id])
            ->pace->toBe(['first_day' => 30])
            ->tag->toBe('spring-again')
            ->and($copy->revisions()->first()->note)->toBe('Duplicated from campaign "spring", revision 1')
            ->and($copy->revisions()->first()->contentFor('en')->html)->toBe('<a href="https://shop.example.com/sale">Sale</a>{{footer}}')
            ->and($sentHtml($source))->toContain('utm_campaign=spring"')
            ->and($sentHtml($copy))->toContain('utm_campaign=spring-again"')
            ->and($sentHtml($copy))->not->toContain('utm_campaign=spring"');
    })->with('databases');

    it('refuses a key that is taken', function (): void {
        $source = Campaign::factory()->create(['key' => 'spring']);
        Revision::factory()->of($source)->create();

        expect(fn () => app(DuplicateCampaignAction::class)->execute($source, 'spring', 'Again'))
            ->toThrow(CampaignsException::class, 'A campaign with the key "spring" already exists.')
            ->and(fn () => app(DuplicateCampaignAction::class)->execute($source, 'campaigns-test', 'Again'))
            ->toThrow(CampaignsException::class, 'the tag every test email carries');
    });
});

describe('saved audiences and custom fields', function (): void {
    it('saves filters under a key, and replaces them under the same key', function (string $driver): void {
        useDatabase($driver);
        CampaignList::factory()->create(['key' => 'readers']);

        app(SaveAudienceAction::class)->execute('fans', 'Fans', ['tiers' => ['engaged']]);
        $saved = app(SaveAudienceAction::class)->execute('fans', 'French fans', ['lists' => ['readers'], 'locales' => ['fr']], 'Readers in French');

        expect(SavedAudience::query()->count())->toBe(1)
            ->and($saved->fresh())
            ->name->toBe('French fans')
            ->description->toBe('Readers in French')
            ->filters->toEqual(['lists' => ['readers'], 'list_status' => 'subscribed', 'locales' => ['fr']])
            ->and(fn () => app(SaveAudienceAction::class)->execute('fans', 'Fans', ['lists' => ['nope']]))
            ->toThrow(CampaignsException::class, 'No list has the key "nope".');
    })->with('databases');

    it('merges custom fields, a null removing one', function (): void {
        $subscriber = Subscriber::factory()->create(['custom_fields' => ['plan' => 'pro', 'seats' => 3]]);

        app(SetCustomFieldsAction::class)->execute($subscriber, ['seats' => null, 'vip' => true, 'city' => 'Lyon']);

        expect($subscriber->fresh()->custom_fields)->toEqual(['plan' => 'pro', 'vip' => true, 'city' => 'Lyon'])
            ->and(fn () => app(SetCustomFieldsAction::class)->execute($subscriber, ['1st' => 'x']))
            ->toThrow(CampaignsException::class, 'starting with a letter or an underscore')
            ->and(fn () => app(SetCustomFieldsAction::class)->execute($subscriber, ['note' => str_repeat('a', 1001)]))
            ->toThrow(CampaignsException::class, 'is text of at most 1000 characters')
            ->and(fn () => app(SetCustomFieldsAction::class)->execute($subscriber, ['score' => 1.5]))
            ->toThrow(CampaignsException::class, 'a whole number, true, false or null');
    });
});
