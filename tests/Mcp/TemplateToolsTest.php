<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\AddRevisionAction;
use Mozex\Campaigns\Actions\CreateTemplateAction;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Jobs\RunChecksJob;
use Mozex\Campaigns\Mcp\Tools\AddRevisionTool;
use Mozex\Campaigns\Mcp\Tools\ArchiveTemplateTool;
use Mozex\Campaigns\Mcp\Tools\CreateTemplateTool;
use Mozex\Campaigns\Mcp\Tools\GetCheckResultsTool;
use Mozex\Campaigns\Mcp\Tools\GetTemplateTool;
use Mozex\Campaigns\Mcp\Tools\ListRevisionsTool;
use Mozex\Campaigns\Mcp\Tools\ListTemplatesTool;
use Mozex\Campaigns\Mcp\Tools\RestoreRevisionTool;
use Mozex\Campaigns\Mcp\Tools\RunChecksTool;
use Mozex\Campaigns\Mcp\Tools\UpdateTemplateTool;
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Reports\CheckSummary;
use Mozex\Campaigns\Reports\TemplateSummary;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
});

/**
 * A zip of an email in English and French, with an image it uses and one it
 * doesn't.
 */
function templateZip(): string
{
    return zipOf([
        'email.html' => '<html><head><title>March news</title></head><body><img src="images/logo.png" alt="Logo">{{footer}}</body></html>',
        'translations/fr.html' => '<p>Bonjour</p><img src="images/logo.png" alt="Logo">{{footer}}',
        'images/logo.png' => testImage('png'),
        'images/spare.gif' => testImage('gif'),
    ]);
}

/**
 * A template whose revisions each hold the most languages an email may,
 * with every text an answer shows at its longest, its locales included,
 * and a failed check run.
 */
function longestTemplate(int $number, string $character, int $revisions = 1): Template
{
    $text = fn (int $length): string => mb_substr(str_repeat($character, $length), 0, $length);
    $template = Template::factory()->create([
        'key' => str_pad((string) $number, 125, 'k', STR_PAD_LEFT),
        'name' => $text(255),
        'description' => $text(5000),
    ]);

    foreach (range(1, $revisions) as $ignored) {
        $revision = Revision::factory()->of($template);

        foreach (range(1, ContentRules::MAX_LOCALES) as $index) {
            $revision = $revision->withContent('aaa-'.str_pad((string) $index, 31, 'x', STR_PAD_LEFT), [
                'subject' => $text(255),
                'preheader' => $text(255),
            ]);
        }

        $revision->create([
            'note' => $text(255),
            'author' => $text(255),
            'check_results' => ['status' => 'failed', 'run' => 'r', 'queued_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'message' => $text(5000)],
        ]);
    }

    return $template;
}

describe('listing and reading templates', function (): void {
    it('lists the starters, then the app\'s templates, defaults first, archived ones only on request', function (string $driver): void {
        useDatabase($driver);
        $create = app(CreateTemplateAction::class);
        $create->execute('welcome', 'Welcome', [campaignEmail(), campaignEmail('fr')]);
        $create->execute('brand', 'Brand', [campaignEmail()], isDefault: true);
        Template::factory()->archived()->create(['key' => 'old']);

        $listed = structuredContent(agent(Rights::Read)->tool(ListTemplatesTool::class)->assertOk());
        $everything = structuredContent(agent(Rights::Read)->tool(ListTemplatesTool::class, ['include_archived' => true])->assertOk());

        expect(array_column($listed['starters'], 'key'))->toBe(['starter'])
            ->and(array_column($listed['templates'], 'key'))->toBe(['brand', 'welcome'])
            ->and($listed['templates'][1])->toMatchArray(['is_default' => false, 'current_revision' => 1, 'locales' => ['en', 'fr']])
            ->and($listed['total'])->toBe(2)
            ->and(array_column($everything['templates'], 'key'))->toBe(['brand', 'old', 'welcome']);
    })->with('databases');

    it('pages the templates so the longest page stays under the limit', function (string $character): void {
        foreach (range(1, ListTemplatesTool::PER_PAGE + 1) as $number) {
            longestTemplate($number, $character);
        }

        $first = structuredContent(agent(Rights::Read)->tool(ListTemplatesTool::class)->assertOk());

        expect($first['templates'])->toHaveCount(ListTemplatesTool::PER_PAGE)
            ->and($first['pages'])->toBe(2)
            ->and(mb_strlen($first['templates'][0]['description']))->toBe(TemplateSummary::DESCRIPTION_LENGTH + 3)
            ->and($first['templates'][0]['locales'])->toHaveCount(ContentRules::MAX_LOCALES)
            ->and(json_encode($first))->not->toContain('\u0001');
    })->with('costly characters');

    it('gets a template\'s current email or an earlier one, with links to download it', function (string $driver): void {
        useDatabase($driver);
        $template = app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()], note: 'First');
        app(AddRevisionAction::class)->execute($template, [
            campaignEmail('fr', '<p>Salut</p>{{footer}}'),
            campaignEmail('en', '<p>Hi again</p>{{footer}}', 'Hi {{footer}}'),
        ], 'Second', 'laptop');

        $current = structuredContent(agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => 'welcome'])->assertOk());
        $first = structuredContent(agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => 'welcome', 'revision' => 1])->assertOk());

        expect($current['revisions'])->toBe(2)
            ->and($current['template'])->toMatchArray(['key' => 'welcome', 'current_revision' => 2, 'locales' => ['en', 'fr']])
            ->and($current['revision'])->toMatchArray(['number' => 2, 'note' => 'Second', 'author' => 'laptop', 'locales' => ['en', 'fr']])
            ->and($current['revision']['versions'])->toBe([
                ['locale' => 'en', 'subject' => 'News', 'preheader' => null, 'html_bytes' => 25, 'text' => 'written'],
                ['locale' => 'fr', 'subject' => 'News', 'preheader' => null, 'html_bytes' => 22, 'text' => 'generated'],
            ])
            ->and($current['revision']['downloads']['zip'])->toStartWith('http://localhost/mcp/campaigns/downloads/'.$template->currentRevision()->firstOrFail()->uuid.'/email.zip?expires=')
            ->and($current['revision']['downloads']['html'])->toContain('/email.html?expires=')
            ->and($first['revision'])->toMatchArray(['number' => 1, 'note' => 'First', 'locales' => ['en']]);

        agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => 'welcome', 'revision' => 3])->assertHasErrors(['The revision 3 doesn\'t exist.']);
        // Past the column's integer, which PostgreSQL answers with an error rather than no row.
        agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => 'welcome', 'revision' => 3_000_000_000])->assertHasErrors(['revision']);
        agent()->tool(RestoreRevisionTool::class, ['template' => 'welcome', 'revision' => 3_000_000_000])->assertHasErrors(['revision']);
        agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => 'nope'])->assertHasErrors(['There is no template with the key "nope".']);
    })->with('databases');

    it('answers a starter template\'s email inline', function (): void {
        $starter = structuredContent(agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => 'starter'])->assertOk());

        expect($starter['starter']['versions'][0]['locale'])->toBe('en')
            ->and($starter['starter']['versions'][0]['html'])->toContain('{{footer}}')
            ->and($starter)->not->toHaveKey('revision');
    });

    it('answers a template whose email is at its longest under the limit', function (string $character): void {
        $template = longestTemplate(1, $character);

        $answer = structuredContent(agent(Rights::Read)->tool(GetTemplateTool::class, ['key' => $template->key])->assertOk());

        expect($answer['revision']['versions'])->toHaveCount(ContentRules::MAX_LOCALES)
            ->and(mb_strlen($answer['template']['description']))->toBe(TemplateSummary::DETAIL_DESCRIPTION_LENGTH + 3)
            ->and(json_encode($answer))->not->toContain('\u0001');
    })->with('costly characters');
});

describe('making and changing templates', function (): void {
    it('creates a template from an upload, storing the images it uses', function (string $driver): void {
        useDatabase($driver);
        $upload = emailUpload(templateZip());

        $created = structuredContent(agent(person: true)->tool(CreateTemplateTool::class, [
            'key' => 'march',
            'name' => 'March',
            'upload_id' => $upload->uuid,
            'subjects' => ['fr' => 'Nouvelles de mars'],
            'preheaders' => ['en' => 'What is new'],
            'note' => 'From the scratchpad',
        ])->assertOk());

        $revision = Template::forKey('march')->currentRevision()->firstOrFail();

        expect($created)
            ->images->toBe(1)
            ->unused_images->toBe(['images/spare.gif'])
            ->unused_images_total->toBe(1)
            ->and($created['revision'])->toMatchArray(['number' => 1, 'note' => 'From the scratchpad', 'author' => 'Ann Agent', 'locales' => ['en', 'fr']])
            ->and($revision->contentFor('en'))->subject->toBe('March news')->preheader->toBe('What is new')
            ->and($revision->contentFor('fr')->subject)->toBe('Nouvelles de mars')
            ->and($revision->contentFor('fr')->html)->toContain('https://cdn.example.com/storage/campaigns/assets/'.hash('sha256', testImage('png')).'.png')
            ->and(Asset::query()->sole()->author)->toBe('Ann Agent')
            ->and($upload->refresh()->claimed_at)->not->toBeNull();
    })->with('databases');

    it('creates a template from a starter, from another template and from inline content', function (): void {
        app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()], description: 'Says hello');

        $fromStarter = structuredContent(agent()->tool(CreateTemplateTool::class, ['key' => 'plain', 'name' => 'Plain', 'from' => 'starter', 'is_default' => true])->assertOk());
        $copy = structuredContent(agent()->tool(CreateTemplateTool::class, ['key' => 'welcome-2', 'name' => 'Welcome again', 'from' => 'welcome'])->assertOk());
        $inline = structuredContent(agent()->tool(CreateTemplateTool::class, [
            'key' => 'inline',
            'name' => 'Inline',
            'contents' => [['locale' => 'en', 'subject' => 'Hi', 'preheader' => 'Read me', 'html' => '<p>Hi {{first_name|there}}</p>{{footer}}']],
        ])->assertOk());

        expect($fromStarter['template'])->toMatchArray(['key' => 'plain', 'is_default' => true])
            ->and($fromStarter['revision']['note'])->toBe('Duplicated from the starter template "starter"')
            ->and($copy['template']['description'])->toBe('Says hello')
            ->and($copy['revision']['note'])->toBe('Duplicated from template "welcome", revision 1')
            ->and($inline['revision'])->toMatchArray(['author' => 'laptop', 'locales' => ['en']])
            ->and($inline)->not->toHaveKey('images')
            ->and(Template::forKey('inline')->currentRevision()->firstOrFail()->contentFor('en')->preheader)->toBe('Read me');
    });

    it('checks what it was given before it uses up an upload', function (array $arguments, string $error): void {
        app(CreateTemplateAction::class)->execute('taken', 'Taken', [campaignEmail()]);
        $campaign = Campaign::factory()->create(['key' => 'spring']);
        $upload = emailUpload(templateZip());

        $tool = isset($arguments['campaign']) ? AddRevisionTool::class : CreateTemplateTool::class;
        agent()->tool($tool, ['key' => 'march', 'name' => 'March', 'upload_id' => $upload->uuid, ...$arguments])->assertHasErrors([$error]);

        expect($upload->refresh()->claimed_at)->toBeNull()
            ->and(Asset::query()->count())->toBe(0)
            ->and($campaign->revisions()->count())->toBe(0);
    })->with([
        'a taken key' => [['key' => 'taken'], 'A template with the key "taken" already exists.'],
        'a starter\'s key' => [['key' => 'starter'], 'is the key of one of the package\'s starter templates'],
        'a malformed key' => [['key' => 'March!'], 'can\'t be a key'],
        'a long note' => [['note' => str_repeat('n', 256)], 'The note may be at most 255 characters long.'],
        'a long note on a campaign\'s email' => [['campaign' => 'spring', 'note' => str_repeat('n', 256)], 'The note may be at most 255 characters long.'],
        'a subject for something that isn\'t a locale' => [['subjects' => ['english' => 'Hi']], 'A subject was given for "english", which is not a locale'],
        'a long preheader' => [['preheaders' => ['en' => str_repeat('p', 256)]], 'en: the preheader must be text of at most 255 characters.'],
    ]);

    it('refuses an email that breaks the rules in one error listing every problem, saving nothing', function (): void {
        $refused = agent()->tool(CreateTemplateTool::class, [
            'key' => 'broken',
            'name' => 'Broken',
            'contents' => [
                ['locale' => 'fr', 'subject' => 'Salut', 'html' => '<p>Salut {{frist_name}}</p>'],
                ['locale' => 'de', 'subject' => "Hallo\nWelt", 'html' => '<img src="http://example.com/x.png">{{footer}}'],
            ],
        ]);

        $errors = (fn (): array => $this->errors())->call($refused);

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toStartWith("The email can't be saved:\n- ")
            ->and(substr_count($errors[0], "\n- "))->toBeGreaterThanOrEqual(4)
            ->and($errors[0])->toContain('{{frist_name}}')->toContain('http://example.com/x.png')
            ->and(Template::query()->count())->toBe(0);
    });

    it('takes exactly one source', function (array $source): void {
        agent()->tool(CreateTemplateTool::class, ['key' => 'one', 'name' => 'One', ...$source])->assertHasErrors();

        expect(Template::query()->count())->toBe(0);
    })->with([
        'none' => [[]],
        'from and contents' => [['from' => 'starter', 'contents' => [['locale' => 'en', 'subject' => 'Hi', 'html' => '{{footer}}']]]],
        'an upload and contents' => [['upload_id' => 'x', 'contents' => [['locale' => 'en', 'subject' => 'Hi', 'html' => '{{footer}}']]]],
    ]);

    it('renames, describes and archives a template, which the list follows', function (): void {
        app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()], description: 'Old');

        $updated = structuredContent(agent()->tool(UpdateTemplateTool::class, ['key' => 'welcome', 'name' => 'Hello', 'description' => null, 'is_default' => true])->assertOk());
        $named = structuredContent(agent()->tool(UpdateTemplateTool::class, ['key' => 'welcome', 'name' => 'Hello there'])->assertOk());
        $archived = structuredContent(agent()->tool(ArchiveTemplateTool::class, ['key' => 'welcome'])->assertOk());
        $hidden = structuredContent(agent()->tool(ListTemplatesTool::class)->assertOk());
        $restored = structuredContent(agent()->tool(ArchiveTemplateTool::class, ['key' => 'welcome', 'archived' => false])->assertOk());

        expect($updated['template'])->toMatchArray(['name' => 'Hello', 'description' => null, 'is_default' => true])
            ->and($named['template'])->toMatchArray(['name' => 'Hello there', 'is_default' => true])
            ->and($archived['changed'])->toBeTrue()
            ->and($archived['template']['archived_at'])->not->toBeNull()
            ->and($hidden['templates'])->toBe([])
            ->and($restored)->changed->toBeTrue()
            ->and($restored['template']['archived_at'])->toBeNull();
    });
});

describe('revisions', function (): void {
    it('saves new versions of a template and a draft campaign, and restores an earlier one through its own email only', function (string $driver): void {
        useDatabase($driver);
        app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()]);
        $campaign = Campaign::factory()->create(['key' => 'spring']);
        app(AddRevisionAction::class)->execute($campaign, [campaignEmail()]);

        $uploaded = structuredContent(agent()->tool(AddRevisionTool::class, ['template' => 'welcome', 'upload_id' => emailUpload(templateZip())->uuid, 'subjects' => ['fr' => 'Mars'], 'note' => 'March'])->assertOk());
        $drafted = structuredContent(agent(person: true)->tool(AddRevisionTool::class, [
            'campaign' => 'spring',
            'contents' => [['locale' => 'en', 'subject' => 'Spring', 'html' => '<p>Spring</p>{{footer}}']],
        ])->assertOk());
        $restored = structuredContent(agent()->tool(RestoreRevisionTool::class, ['template' => 'welcome', 'revision' => 1])->assertOk());
        $history = structuredContent(agent(Rights::Read)->tool(ListRevisionsTool::class, ['template' => 'welcome'])->assertOk());

        agent()->tool(RestoreRevisionTool::class, ['campaign' => 'spring', 'revision' => 3])->assertHasErrors(['The revision 3 doesn\'t exist.']);

        expect($uploaded)->images->toBe(1)->unused_images->toBe(['images/spare.gif'])
            ->and($uploaded['revision'])->toMatchArray(['number' => 2, 'note' => 'March', 'locales' => ['en', 'fr']])
            ->and($drafted['revision'])->toMatchArray(['number' => 2, 'author' => 'Ann Agent'])
            ->and($restored['revision'])->toMatchArray(['number' => 3, 'note' => 'Restored from revision 1', 'locales' => ['en']])
            ->and(array_column($history['revisions'], 'number'))->toBe([3, 2, 1])
            ->and($history['total'])->toBe(3);
    })->with('databases');

    it('refuses a started campaign\'s email before the upload is used up', function (): void {
        Campaign::factory()->sending()->create(['key' => 'live']);
        $upload = emailUpload(templateZip());

        agent()->tool(AddRevisionTool::class, ['campaign' => 'live', 'upload_id' => $upload->uuid])->assertHasErrors(['The campaign is sending']);

        expect($upload->refresh()->claimed_at)->toBeNull();
    });

    it('names exactly one email', function (array $owner): void {
        app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()]);
        Campaign::factory()->create(['key' => 'spring']);

        agent(Rights::Read)->tool(ListRevisionsTool::class, $owner)->assertHasErrors();
    })->with([
        'neither' => [[]],
        'both' => [['template' => 'welcome', 'campaign' => 'spring']],
    ]);

    it('pages the history so the longest page stays under the limit', function (string $character): void {
        $template = longestTemplate(1, $character, ListRevisionsTool::PER_PAGE + 1);

        $first = structuredContent(agent(Rights::Read)->tool(ListRevisionsTool::class, ['template' => $template->key])->assertOk());

        expect($first['revisions'])->toHaveCount(ListRevisionsTool::PER_PAGE)
            ->and($first['pages'])->toBe(2)
            ->and(json_encode($first))->not->toContain('\u0001');
    })->with('costly characters');
});

describe('checks', function (): void {
    it('queues the checks of a template\'s and a campaign\'s current email', function (string $driver): void {
        useDatabase($driver);
        Queue::fake();
        $template = app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()]);
        $campaign = Campaign::factory()->create(['key' => 'spring', 'track_clicks' => false]);
        app(AddRevisionAction::class)->execute($campaign, [campaignEmail()]);

        $queued = structuredContent(agent()->tool(RunChecksTool::class, ['template' => 'welcome'])->assertOk());
        agent()->tool(RunChecksTool::class, ['campaign' => 'spring'])->assertOk();
        $waiting = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome'])->assertOk());

        expect($queued)->revision->toBe(1)->status->toBe('queued')
            ->and($waiting['run']['status'])->toBe('queued')
            ->and($waiting['checks'])->toBe([]);

        Queue::assertPushed(RunChecksJob::class, fn (RunChecksJob $job): bool => $job->revisionId === $template->currentRevision()->firstOrFail()->id && $job->clickTracking);
        Queue::assertPushed(RunChecksJob::class, fn (RunChecksJob $job): bool => $job->revisionId === $campaign->currentRevision()->firstOrFail()->id && ! $job->clickTracking);
    })->with('databases');

    it('reads every check, then one check\'s problems a page at a time', function (): void {
        $template = app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail(), campaignEmail('fr')]);
        $revision = $template->currentRevision()->firstOrFail();
        $links = array_map(fn (int $number): array => ['url' => "https://example.com/{$number}", 'problem' => 'answered 404', 'locales' => ['en', 'fr']], range(1, 30));
        $revision->update(['check_results' => [
            'status' => 'done', 'run' => 'r', 'queued_at' => '2026-10-29T10:00:00+00:00', 'finished_at' => '2026-10-29T10:01:00+00:00', 'passed' => 1, 'failed' => 3,
            'checks' => [
                ['check' => 'size', 'locale' => 'en', 'status' => 'passed', 'message' => 'Fine.', 'bytes' => 2048],
                ['check' => 'images', 'locale' => 'en', 'status' => 'failed', 'message' => '1 image problem.', 'items' => [['url' => 'https://example.com/a.png', 'problem' => 'has no alt text']]],
                ['check' => 'images', 'locale' => 'fr', 'status' => 'failed', 'message' => '1 image problem.', 'items' => [['url' => 'https://example.com/b.png', 'problem' => 'is not on https']]],
                ['check' => 'links', 'locale' => null, 'status' => 'failed', 'message' => '30 of the 30 have a problem.', 'checked' => 30, 'items' => $links],
            ],
        ]]);

        $all = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome'])->assertOk());
        $linksFirst = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome', 'check' => 'links'])->assertOk());
        $linksLast = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome', 'check' => 'links', 'page' => 2])->assertOk());
        $french = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome', 'check' => 'images', 'locale' => 'fr'])->assertOk());

        expect($all['run'])->toMatchArray(['status' => 'done', 'passed' => 1, 'failed' => 3])
            ->and($all['checks'][0])->toBe(['check' => 'size', 'locale' => 'en', 'status' => 'passed', 'message' => 'Fine.', 'items' => 0, 'score' => null, 'bytes' => 2048])
            ->and(array_column($all['checks'], 'items'))->toBe([0, 1, 1, 30])
            ->and($linksFirst)->total->toBe(30)->pages->toBe(2)
            ->and($linksFirst['items'])->toHaveCount(GetCheckResultsTool::PER_PAGE)
            ->and($linksFirst['items'][0])->toBe(['url' => 'https://example.com/1', 'problem' => 'answered 404', 'locales' => ['en', 'fr']])
            ->and($linksLast['items'])->toHaveCount(5)
            ->and($french['items'])->toBe([['locale' => 'fr', 'url' => 'https://example.com/b.png', 'problem' => 'is not on https']]);
    });

    it('answers the longest results under the limit', function (string $character): void {
        $template = app(CreateTemplateAction::class)->execute('welcome', 'Welcome', [campaignEmail()]);
        $text = fn (int $length): string => mb_substr(str_repeat($character, $length), 0, $length);
        $items = array_map(fn (int $number): array => ['url' => $text(2048), 'problem' => $text(500), 'locales' => array_fill(0, 30, $text(35))], range(1, 30));
        $checks = array_map(fn (int $number): array => ['check' => 'links', 'locale' => $text(35), 'status' => 'failed', 'message' => $text(2000), 'items' => $items], range(1, 30));
        $template->currentRevision()->firstOrFail()->update(['check_results' => ['status' => 'done', 'run' => 'r', 'passed' => 0, 'failed' => 30, 'checks' => $checks]]);

        $rows = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome'])->assertOk());
        $problems = structuredContent(agent(Rights::Read)->tool(GetCheckResultsTool::class, ['template' => 'welcome', 'check' => 'links'])->assertOk());

        expect($rows['checks'])->toHaveCount(GetCheckResultsTool::PER_PAGE)
            ->and($problems['items'])->toHaveCount(GetCheckResultsTool::PER_PAGE)
            ->and($problems['items'][0]['locales'])->toHaveCount(CheckSummary::ITEM_LOCALES)
            ->and(json_encode([$rows, $problems]))->not->toContain('\u0001');
    })->with('costly characters');
});
