<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Actions\CreateTemplateAction;
use Mozex\Campaigns\Actions\SaveAudienceAction;
use Mozex\Campaigns\Audiences\AudienceDefinition;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Jobs\SendTestJob;
use Mozex\Campaigns\Mcp\Tools\CreateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\DuplicateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetPreviewLinkTool;
use Mozex\Campaigns\Mcp\Tools\GetTestSendsTool;
use Mozex\Campaigns\Mcp\Tools\ListAudiencesTool;
use Mozex\Campaigns\Mcp\Tools\ListCampaignsTool;
use Mozex\Campaigns\Mcp\Tools\PreviewAudienceTool;
use Mozex\Campaigns\Mcp\Tools\SaveAudienceTool;
use Mozex\Campaigns\Mcp\Tools\SendTestTool;
use Mozex\Campaigns\Mcp\Tools\UpdateCampaignTool;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Tests\Fixtures\MembersInCountry;
use Mozex\Campaigns\Tests\Fixtures\RecentContacts;
use Mozex\Campaigns\Tests\Fixtures\WordyAudience;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
    config(['campaigns.mail.from' => ['address' => 'news@news.example.com', 'name' => 'Example News']]);
});

/**
 * More target lists than one campaign's answer names, each key at its
 * longest.
 *
 * @return Collection<int, CampaignList>
 */
function longestTargetLists(): Collection
{
    return CampaignList::factory()
        ->count(CampaignSummary::DETAIL_LISTS + 5)
        ->sequence(fn (Sequence $sequence): array => ['key' => str_pad((string) $sequence->index, 125, 'l', STR_PAD_LEFT)])
        ->create();
}

/**
 * A draft whose every text an answer shows is at its longest, whose email
 * holds the most languages, and whose audience names the most people.
 *
 * @param  Collection<int, CampaignList>  $lists
 */
function longestCampaign(int $number, string $character, Collection $lists): Campaign
{
    $text = fn (int $length): string => mb_substr(str_repeat($character, $length), 0, $length);
    $campaign = Campaign::factory()->create([
        'key' => str_pad((string) $number, 125, 'c', STR_PAD_LEFT),
        'name' => $text(255),
        'from_name' => $text(255),
        'reply_to' => str_repeat('r', 240).'@example.com',
        'utm' => ['source' => $text(125), 'medium' => $text(125), 'content' => $text(125), 'term' => $text(125)],
        'target_lists' => $lists->pluck('id')->all(),
        'audience' => [
            'audience' => ['name' => 'recent', 'parameters' => ['note' => $text(5000)]],
            'saved_audience' => 'savers',
            'filters' => ['custom_fields' => ['note' => $text(1000)]],
            'subscribers' => range(1, AudienceDefinition::MAX_IDS),
            'records' => ['members' => range(1, AudienceDefinition::MAX_IDS)],
            'unmatched_emails' => 5,
        ],
        'start_error' => $text(5000),
        'pause_reason' => $text(5000),
    ]);

    $revision = Revision::factory()->of($campaign);

    foreach (range(1, ContentRules::MAX_LOCALES) as $index) {
        $revision = $revision->withContent('aaa-'.str_pad((string) $index, 31, 'x', STR_PAD_LEFT), ['subject' => $text(255), 'preheader' => $text(255)]);
    }

    $revision->create(['note' => $text(255), 'author' => $text(255)]);

    return $campaign;
}

describe('drafting campaigns', function (): void {
    it('creates a draft from a template, a starter, an upload or inline content', function (string $driver): void {
        useDatabase($driver);
        app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail(), campaignEmail('fr')]);
        $upload = emailUpload(zipOf([
            'email.html' => '<html><head><title>Spring</title></head><body><img src="images/logo.png" alt="Logo">{{footer}}</body></html>',
            'images/logo.png' => testImage('png'),
            'images/spare.gif' => testImage('gif'),
        ]));

        $fromTemplate = structuredContent(agent()->tool(CreateCampaignTool::class, ['key' => 'march', 'name' => 'March', 'template' => 'welcome', 'note' => 'From the welcome'])->assertOk());
        $fromStarter = structuredContent(agent()->tool(CreateCampaignTool::class, ['key' => 'april', 'name' => 'April', 'template' => 'starter'])->assertOk());
        $fromUpload = structuredContent(agent()->tool(CreateCampaignTool::class, ['key' => 'may', 'name' => 'May', 'upload_id' => $upload->uuid, 'preheaders' => ['en' => 'Flowers']])->assertOk());
        $inline = structuredContent(agent(person: true)->tool(CreateCampaignTool::class, [
            'key' => 'june',
            'name' => 'June',
            'contents' => [['locale' => 'en', 'subject' => 'June news', 'html' => '<p>June</p>{{footer}}']],
            'settings' => ['reply_to' => 'help@example.com', 'track_opens' => false, 'pace' => ['per_minute' => 5]],
        ])->assertOk());

        expect($fromTemplate['campaign'])->toMatchArray(['key' => 'march', 'status' => 'draft', 'template' => 'welcome', 'from_email' => 'news@news.example.com', 'from_name' => 'Example News'])
            ->and($fromTemplate['campaign']['revision'])->toMatchArray(['number' => 1, 'note' => 'From the welcome', 'author' => 'laptop', 'locales' => ['en', 'fr']])
            ->and($fromTemplate['campaign']['revision']['downloads']['zip'])->toContain('/email.zip?expires=')
            ->and($fromTemplate)->not->toHaveKey('images')
            ->and($fromStarter['campaign'])->toMatchArray(['key' => 'april', 'template' => null])
            ->and($fromUpload['campaign']['revision']['versions'][0])->toMatchArray(['subject' => 'Spring', 'preheader' => 'Flowers'])
            ->and($fromUpload)->toMatchArray(['images' => 1, 'unused_images' => ['images/spare.gif'], 'unused_images_total' => 1])
            ->and($inline['campaign'])->toMatchArray(['key' => 'june', 'reply_to' => 'help@example.com', 'track_opens' => false, 'pace' => ['per_minute' => 5]])
            ->and($inline['campaign']['revision']['author'])->toBe('Ann Agent')
            ->and(Campaign::query()->orderBy('key')->pluck('key')->all())->toBe(['april', 'june', 'march', 'may']);
    })->with('databases');

    it('answers a draft made from the largest upload with the longest settings under the limit', function (string $character): void {
        $text = fn (int $length): string => mb_substr(str_repeat($character, $length), 0, $length);
        $locales = array_map(fn (int $index): string => sprintf('aaa-%08d-xxxxxxxx-xxxxxxxx-xx', $index), range(1, ContentRules::MAX_LOCALES - 1));
        $files = ['email.html' => '<p>Hi</p>{{footer}}'];

        foreach ($locales as $locale) {
            $files["translations/{$locale}.html"] = '<p>Hi</p>{{footer}}';
        }

        // Images no version uses, named with the costliest character a zip
        // entry may hold.
        foreach (range(1, 30) as $index) {
            $files['images/'.str_repeat('"', 150).$index.'.png'] = testImage('png');
        }

        $lists = longestTargetLists();
        $upload = emailUpload(zipOf($files));

        $answer = structuredContent(agent()->tool(CreateCampaignTool::class, [
            'key' => str_repeat('k', 125),
            'name' => $text(255),
            'upload_id' => $upload->uuid,
            'subjects' => array_fill_keys(['en', ...$locales], $text(255)),
            'preheaders' => array_fill_keys(['en', ...$locales], $text(255)),
            'note' => $text(255),
            'settings' => [
                'from_name' => $text(255),
                'reply_to' => str_repeat('r', 64).'@'.implode('.', [str_repeat('a', 60), str_repeat('b', 60), str_repeat('c', 50), 'example.com']),
                'lists' => $lists->pluck('key')->all(),
                'audience' => ['filters' => ['custom_fields' => ['note' => $text(1000)]], 'subscribers' => range(1, AudienceDefinition::MAX_IDS)],
                'utm' => ['source' => $text(125), 'medium' => $text(125), 'content' => $text(125), 'term' => $text(125)],
            ],
        ])->assertOk());

        expect($answer['campaign']['revision']['versions'])->toHaveCount(ContentRules::MAX_LOCALES)
            ->and($answer['campaign']['audience']['subscribers'])->toBe(AudienceDefinition::MAX_IDS)
            ->and($answer['unused_images'])->toHaveCount(20)
            ->and($answer['unused_images_total'])->toBe(30)
            ->and(json_encode($answer))->not->toContain('\u0001');
    })->with('costly characters');

    it('refuses a draft it can\'t make, before an upload is used up', function (): void {
        $upload = emailUpload('<p>Hi</p>{{footer}}');

        agent()->tool(CreateCampaignTool::class, ['key' => 'x', 'name' => 'X'])->assertHasErrors(['template']);
        agent()->tool(CreateCampaignTool::class, ['key' => 'x', 'name' => 'X', 'template' => 'nope'])->assertHasErrors(['There is no template with the key "nope".']);
        agent()->tool(CreateCampaignTool::class, ['key' => 'x', 'name' => 'X', 'upload_id' => $upload->uuid, 'settings' => ['lists' => ['nope']]])->assertHasErrors(['No list has the key "nope".']);
        agent()->tool(CreateCampaignTool::class, ['key' => 'x', 'name' => 'X', 'template' => 'starter', 'settings' => ['colour' => 'red']])->assertHasErrors(['there is no such setting']);
        agent(Rights::Read)->tool(CreateCampaignTool::class, ['key' => 'x', 'name' => 'X', 'template' => 'starter'])->assertNotRegistered();

        expect(Campaign::query()->count())->toBe(0)
            ->and($upload->fresh()?->claimed_at)->toBeNull();
    });

    it('changes only the settings given, merging the pace, and only on a draft', function (string $driver): void {
        useDatabase($driver);
        $readers = CampaignList::factory()->create(['key' => 'readers']);
        app(CreateCampaignAction::class)->execute('march', 'March', EmailSource::template('starter'), [
            'reply_to' => 'help@example.com',
            'pace' => ['per_minute' => 5, 'daily_cap' => 100],
        ]);

        $updated = structuredContent(agent()->tool(UpdateCampaignTool::class, ['key' => 'march', 'settings' => [
            'name' => 'March news',
            'lists' => ['readers'],
            'audience' => ['filters' => ['locales' => ['en']], 'emails' => ['nobody@example.com']],
            'pace' => ['per_minute' => 8],
            'utm' => false,
        ]])->assertOk());
        $cleared = structuredContent(agent()->tool(UpdateCampaignTool::class, ['key' => 'march', 'settings' => ['reply_to' => null]])->assertOk());

        expect($updated['campaign'])->toMatchArray([
            'name' => 'March news',
            'reply_to' => 'help@example.com',
            'lists' => ['readers'],
            'lists_total' => 1,
            'pace' => ['per_minute' => 8, 'daily_cap' => 100],
            'utm' => [],
        ])
            ->and($updated['campaign']['audience'])->toMatchArray(['filters' => '{"locales":["en"]}', 'subscribers' => 0, 'unmatched_emails' => 1])
            ->and($cleared['campaign']['reply_to'])->toBeNull()
            ->and($cleared['campaign']['name'])->toBe('March news')
            ->and(Campaign::forKey('march')->target_lists)->toBe([$readers->id]);

        Campaign::forKey('march')->update(['status' => CampaignStatus::Sending]);
        agent()->tool(UpdateCampaignTool::class, ['key' => 'march', 'settings' => ['name' => 'Late']])->assertHasErrors(['A sending campaign can\'t be changed.']);
        agent()->tool(UpdateCampaignTool::class, ['key' => 'nope', 'settings' => ['name' => 'X']])->assertHasErrors(['No campaign has the key "nope".']);
    })->with('databases');

    it('duplicates a campaign as a new draft', function (string $driver): void {
        useDatabase($driver);
        $readers = CampaignList::factory()->create(['key' => 'readers']);
        $source = app(CreateCampaignAction::class)->execute('march', 'March', EmailSource::template('starter'), ['lists' => ['readers']])->campaign;
        $source->update(['status' => CampaignStatus::Completed]);

        $copy = structuredContent(agent()->tool(DuplicateCampaignTool::class, ['from' => 'march', 'key' => 'march-again', 'name' => 'March again'])->assertOk());

        expect($copy['campaign'])->toMatchArray(['key' => 'march-again', 'status' => 'draft', 'duplicated_from' => 'march', 'lists' => ['readers']])
            ->and($copy['campaign']['revision']['note'])->toBe('Duplicated from campaign "march", revision 1')
            ->and(Campaign::forKey('march-again')->target_lists)->toBe([$readers->id]);

        agent()->tool(DuplicateCampaignTool::class, ['from' => 'march', 'key' => 'march-again', 'name' => 'Twice'])->assertHasErrors(['A campaign with the key "march-again" already exists.']);
    })->with('databases');
});

describe('reading campaigns', function (): void {
    it('lists campaigns newest first, by status, a page at a time', function (string $driver): void {
        useDatabase($driver);

        foreach (range(1, ListCampaignsTool::PER_PAGE + 1) as $number) {
            Campaign::factory()->create(['key' => "c{$number}"]);
        }

        Campaign::factory()->create(['key' => 'live', 'status' => CampaignStatus::Sending]);

        $first = structuredContent(agent(Rights::Read)->tool(ListCampaignsTool::class)->assertOk());
        $second = structuredContent(agent(Rights::Read)->tool(ListCampaignsTool::class, ['page' => 2])->assertOk());
        $sending = structuredContent(agent(Rights::Read)->tool(ListCampaignsTool::class, ['statuses' => ['sending']])->assertOk());

        expect(array_column($first['campaigns'], 'key'))->toHaveCount(ListCampaignsTool::PER_PAGE)
            ->and($first['campaigns'][0]['key'])->toBe('live')
            ->and($first)->toMatchArray(['total' => 22, 'pages' => 2])
            ->and(array_column($second['campaigns'], 'key'))->toBe(['c2', 'c1'])
            ->and(array_column($sending['campaigns'], 'key'))->toBe(['live']);

        agent(Rights::Read)->tool(ListCampaignsTool::class, ['statuses' => ['nope']])->assertHasErrors(['statuses.0']);
    })->with('databases');

    it('answers the longest campaign and page under the limit, counting its audience\'s people', function (string $character): void {
        $lists = longestTargetLists();

        foreach (range(1, ListCampaignsTool::PER_PAGE) as $number) {
            $campaign = longestCampaign($number, $character, $lists);
        }

        $answer = structuredContent(agent(Rights::Read)->tool(GetCampaignTool::class, ['key' => $campaign->key])->assertOk());
        $page = structuredContent(agent(Rights::Read)->tool(ListCampaignsTool::class)->assertOk());

        expect($answer['campaign']['lists'])->toHaveCount(CampaignSummary::DETAIL_LISTS)
            ->and($answer['campaign']['lists_total'])->toBe(CampaignSummary::DETAIL_LISTS + 5)
            ->and($answer['campaign']['audience'])->toMatchArray(['audience' => 'recent', 'saved_audience' => 'savers', 'subscribers' => AudienceDefinition::MAX_IDS, 'records' => ['members' => AudienceDefinition::MAX_IDS], 'unmatched_emails' => 5])
            // The cut drops a space it ends on before its "...".
            ->and(mb_strlen($answer['campaign']['audience']['parameters']))->toBeBetween(CampaignSummary::AUDIENCE_TEXT_LENGTH, CampaignSummary::AUDIENCE_TEXT_LENGTH + 3)
            ->and($answer['campaign']['revision']['versions'])->toHaveCount(ContentRules::MAX_LOCALES)
            ->and($page['campaigns'][0]['lists'])->toHaveCount(CampaignSummary::ROW_LISTS)
            ->and(json_encode($answer))->not->toContain('\u0001')
            ->and(json_encode($page))->not->toContain('\u0001');
    })->with('costly characters');
});

describe('audiences', function (): void {
    it('lists the named audiences with their parameters\' schema, and the saved ones a page at a time', function (string $driver): void {
        useDatabase($driver);
        // A name PHP keeps as an integer key, which must come back as written.
        config(['campaigns.audiences' => ['in-country' => MembersInCountry::class, 'recent' => RecentContacts::class, '7' => RecentContacts::class]]);
        app(SaveAudienceAction::class)->execute('english', 'English readers', ['locales' => ['en']], 'Everyone reading in English');

        $listed = structuredContent(agent(Rights::Read)->tool(ListAudiencesTool::class)->assertOk());

        expect($listed['named'])->toEqual([
            ['name' => 'in-country', 'description' => 'Members who live in a country.', 'parameters' => [
                'type' => 'object',
                'properties' => ['country' => ['type' => 'string', 'description' => 'A two-letter country code.']],
                'required' => ['country'],
            ]],
            ['name' => 'recent', 'description' => 'Contacts added recently.', 'parameters' => [
                'type' => 'object',
                'properties' => ['days' => ['type' => 'integer', 'description' => 'How many days back.']],
            ]],
            ['name' => '7', 'description' => 'Contacts added recently.', 'parameters' => [
                'type' => 'object',
                'properties' => ['days' => ['type' => 'integer', 'description' => 'How many days back.']],
            ]],
        ])
            ->and($listed['named_total'])->toBe(3)
            ->and($listed['saved'][0])->toMatchArray(['key' => 'english', 'name' => 'English readers', 'description' => 'Everyone reading in English', 'filters' => '{"locales":["en"]}'])
            ->and($listed['total'])->toBe(1);
    })->with('databases');

    it('pages the saved audiences so the longest page, with the most named audiences, stays under the limit', function (string $character): void {
        $text = fn (int $length): string => mb_substr(str_repeat($character, $length), 0, $length);
        config(['campaigns.audiences' => array_fill_keys(array_map(fn (int $index): string => "wordy-{$index}", range(1, ListAudiencesTool::NAMED_LIMIT + 1)), WordyAudience::class)]);

        foreach (range(1, ListAudiencesTool::PER_PAGE + 1) as $number) {
            SavedAudience::query()->create([
                'key' => str_pad((string) $number, 125, 'a', STR_PAD_LEFT),
                'name' => $text(255),
                'description' => $text(5000),
                'filters' => ['custom_fields' => array_fill_keys(array_map(fn (int $field): string => "field_{$field}", range(1, 20)), $text(1000))],
            ]);
        }

        $first = structuredContent(agent(Rights::Read)->tool(ListAudiencesTool::class)->assertOk());

        expect($first['saved'])->toHaveCount(ListAudiencesTool::PER_PAGE)
            ->and($first['named'])->toHaveCount(ListAudiencesTool::NAMED_LIMIT)
            ->and($first['named_total'])->toBe(ListAudiencesTool::NAMED_LIMIT + 1)
            ->and(mb_strlen($first['named'][0]['description']))->toBe(ListAudiencesTool::DESCRIPTION_LENGTH + 3)
            ->and($first['pages'])->toBe(2)
            ->and(mb_strlen($first['saved'][0]['filters']))->toBeBetween(CampaignSummary::AUDIENCE_TEXT_LENGTH, CampaignSummary::AUDIENCE_TEXT_LENGTH + 3)
            ->and(json_encode($first))->not->toContain('\u0001');
    })->with('costly characters');

    it('saves an audience and replaces it under the same key, refusing filters it can\'t use', function (string $driver): void {
        useDatabase($driver);
        CampaignList::factory()->create(['key' => 'readers']);

        $saved = structuredContent(agent()->tool(SaveAudienceTool::class, ['key' => 'english', 'name' => 'English', 'filters' => ['locales' => ['en']]])->assertOk());
        $replaced = structuredContent(agent()->tool(SaveAudienceTool::class, ['key' => 'english', 'name' => 'English readers', 'filters' => ['lists' => ['readers'], 'locales' => ['en']]])->assertOk());

        expect($saved)->toMatchArray(['key' => 'english', 'created' => true])
            ->and($replaced)->toMatchArray(['name' => 'English readers', 'created' => false])
            ->and(SavedAudience::query()->firstOrFail()->filters)->toEqual(['lists' => ['readers'], 'list_status' => 'subscribed', 'locales' => ['en']]);

        agent()->tool(SaveAudienceTool::class, ['key' => 'x', 'name' => 'X', 'filters' => ['lists' => ['nope']]])->assertHasErrors(['nope']);
        agent()->tool(SaveAudienceTool::class, ['key' => 'x', 'name' => 'X', 'filters' => ['colour' => 'red']])->assertHasErrors(['colour']);
    })->with('databases');

    it('previews a campaign\'s audience and one not saved yet, in counts only', function (string $driver): void {
        useDatabase($driver);
        $readers = CampaignList::factory()->create(['key' => 'readers']);
        $buyers = CampaignList::factory()->create(['key' => 'buyers']);
        subscriberOn([$readers, $buyers], ['email' => 'ann@gmail.com', 'locale' => 'en']);
        subscriberOn([$readers], ['email' => 'bob@example.org', 'locale' => 'fr']);
        subscriberOn([$readers], ['email' => 'cat@example.org', 'locale' => 'en', 'opted_out_at' => now()]);
        app(CreateCampaignAction::class)->execute('march', 'March', EmailSource::template('starter'), ['lists' => ['readers', 'buyers'], 'audience' => ['filters' => ['locales' => ['en']]]]);
        app(CreateCampaignAction::class)->execute('bare', 'Bare', EmailSource::template('starter'));

        $campaign = structuredContent(agent(Rights::Read)->tool(PreviewAudienceTool::class, ['campaign' => 'march'])->assertOk());
        $unsaved = structuredContent(agent(Rights::Read)->tool(PreviewAudienceTool::class, ['lists' => ['readers']])->assertOk());

        expect($campaign)->toMatchArray([
            'recipients' => 1,
            'targeted' => 2,
            'dropped' => ['blocked' => 0, 'opted_out' => 1, 'sunset' => 0],
            'by_list' => ['buyers' => 1, 'readers' => 1],
            'by_list_total' => 2,
            'by_locale' => ['en' => 1],
            'unmatched_emails' => 0,
        ])
            ->and($campaign['by_provider']['gmail'])->toBe(1)
            ->and($unsaved)->toMatchArray(['recipients' => 2, 'targeted' => 3, 'by_locale' => ['en' => 1, 'fr' => 1]])
            ->and(json_encode($campaign).json_encode($unsaved))->not->toContain('@');

        agent(Rights::Read)->tool(PreviewAudienceTool::class, ['campaign' => 'bare'])->assertHasErrors(['The campaign has no target lists']);
        agent(Rights::Read)->tool(PreviewAudienceTool::class, ['lists' => ['readers'], 'audience' => ['colour' => 'red']])->assertHasErrors(['colour']);
        agent(Rights::Read)->tool(PreviewAudienceTool::class, [])->assertHasErrors(['campaign']);
    })->with('databases');

    it('names only the largest lists and languages in a breakdown', function (): void {
        $lists = CampaignList::factory()->count(PreviewAudienceTool::BREAKDOWN_LIMIT + 2)->sequence(fn (Sequence $sequence): array => ['key' => 'list-'.$sequence->index])->create();
        subscriberOn([$lists[0], $lists[1]], ['locale' => 'en']);

        foreach ($lists as $index => $list) {
            subscriberOn([$list], ['locale' => 'l'.$index]);
        }

        $answer = structuredContent(agent(Rights::Read)->tool(PreviewAudienceTool::class, ['lists' => $lists->pluck('key')->all()])->assertOk());

        expect($answer['by_list'])->toHaveCount(PreviewAudienceTool::BREAKDOWN_LIMIT)
            ->and(array_slice($answer['by_list'], 0, 2))->toBe(['list-0' => 2, 'list-1' => 2])
            ->and($answer['by_list_total'])->toBe(PreviewAudienceTool::BREAKDOWN_LIMIT + 2)
            ->and(array_key_first($answer['by_locale']))->toBe('en')
            ->and($answer['by_locale_total'])->toBe(PreviewAudienceTool::BREAKDOWN_LIMIT + 3);
    });
});

describe('tests and previews', function (): void {
    it('sends tests to the test addresses only, and reads back whether they went', function (string $driver): void {
        useDatabase($driver);
        Queue::fake([SendTestJob::class]);
        config(['campaigns.enabled' => true, 'campaigns.test_addresses' => ['me@example.com', 'team@example.com']]);
        app(CreateCampaignAction::class)->execute('march', 'March', EmailSource::contents([campaignEmail(), campaignEmail('fr')]));

        $sent = structuredContent(agent()->tool(SendTestTool::class, ['campaign' => 'march', 'addresses' => ['me@example.com'], 'locales' => ['en', 'fr']])->assertOk());
        $uuids = array_column($sent['tests'], 'uuid');
        TestSend::query()->where('uuid', $uuids[0])->update(['sent_at' => now(), 'token_hash' => str_repeat('a', 64)]);
        TestSend::query()->where('uuid', $uuids[1])->update(['failed_at' => now(), 'failure' => 'The provider refused it.']);

        $read = structuredContent(agent(Rights::Read)->tool(GetTestSendsTool::class, ['tests' => [$uuids[1], $uuids[0], fake()->uuid()]])->assertOk());

        expect($sent['tests'])->toHaveCount(2)
            ->and(array_column($sent['tests'], 'locale'))->toBe(['en', 'fr'])
            ->and(array_column($read['tests'], 'uuid'))->toBe([$uuids[1], $uuids[0]])
            ->and($read['tests'][0])->toMatchArray(['campaign' => 'march', 'locale' => 'fr', 'failure' => 'The provider refused it.', 'sent_at' => null])
            ->and($read['tests'][1]['sent_at'])->not->toBeNull();

        Queue::assertPushed(SendTestJob::class, 2);

        agent()->tool(SendTestTool::class, ['campaign' => 'march', 'addresses' => ['someone@else.com']])->assertHasErrors(['Test emails go only to the addresses in campaigns.test_addresses']);
        agent()->tool(SendTestTool::class, ['template' => 'nope'])->assertHasErrors(['There is no template with the key "nope".']);
        agent(Rights::Read)->tool(GetTestSendsTool::class, ['tests' => ['not-a-uuid']])->assertHasErrors(['tests.0']);

        config(['campaigns.enabled' => false]);
        agent()->tool(SendTestTool::class, ['campaign' => 'march'])->assertHasErrors(['switched off']);

        expect(TestSend::query()->count())->toBe(2);
    })->with('databases');

    it('links to a template\'s or a campaign\'s preview, its current email or an earlier one', function (string $driver): void {
        useDatabase($driver);
        $template = app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail(), campaignEmail('fr')]);
        app(CreateCampaignAction::class)->execute('march', 'March', EmailSource::template('welcome'));

        $current = structuredContent(agent(Rights::Read)->tool(GetPreviewLinkTool::class, ['template' => 'welcome'])->assertOk());
        $campaign = structuredContent(agent(person: true)->tool(GetPreviewLinkTool::class, ['campaign' => 'march', 'revision' => 1])->assertOk());

        expect($current)->toMatchArray(['revision' => 1, 'locales' => ['en', 'fr']])
            ->and($current['url'])->toStartWith('http://localhost/campaigns/preview/'.$template->currentRevision()->firstOrFail()->uuid.'?expires=')
            ->and(TestSend::query()->whereNull('campaign_id')->count())->toBe(4);

        $this->get((string) parse_url($current['url'], PHP_URL_PATH).'?'.parse_url($current['url'], PHP_URL_QUERY))->assertOk()->assertSee('Welcome');
        $this->get((string) parse_url($campaign['url'], PHP_URL_PATH).'?'.parse_url($campaign['url'], PHP_URL_QUERY))->assertOk()->assertSee('March');

        agent(Rights::Read)->tool(GetPreviewLinkTool::class, ['template' => 'welcome', 'revision' => 2])->assertHasErrors(['The revision 2 doesn\'t exist.']);
        // Past the column's integer, which PostgreSQL answers with an error rather than no row.
        agent(Rights::Read)->tool(GetPreviewLinkTool::class, ['template' => 'welcome', 'revision' => 3_000_000_000])->assertHasErrors(['revision']);
        agent(Rights::Read)->tool(GetPreviewLinkTool::class, ['template' => 'welcome', 'campaign' => 'march'])->assertHasErrors(['template']);
    })->with('databases');
});
