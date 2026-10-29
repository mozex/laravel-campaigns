<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mozex\Campaigns\Actions\AddRevisionAction;
use Mozex\Campaigns\Actions\ArchiveTemplateAction;
use Mozex\Campaigns\Actions\CreateTemplateAction;
use Mozex\Campaigns\Actions\DuplicateTemplateAction;
use Mozex\Campaigns\Actions\RestoreRevisionAction;
use Mozex\Campaigns\Actions\UpdateTemplateAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;

function email(string $locale = 'en', string $body = 'Hello', ?string $text = null): EmailContent
{
    return new EmailContent(
        locale: $locale,
        subject: "Subject {$body}",
        html: "<p>{$body}</p>{{footer}}",
        text: $text,
    );
}

function createTemplate(string $key = 'monthly', string $name = 'Monthly news'): Template
{
    return app(CreateTemplateAction::class)->execute($key, $name, [email()]);
}

describe('creating a template', function (): void {
    it('saves the template with its first revision', function (): void {
        $template = app(CreateTemplateAction::class)->execute(
            key: 'monthly',
            name: '  Monthly news ',
            contents: [email(), email('fa', 'Salam', "Salam\n{{footer}}")],
            description: 'Sent on the first of the month.',
            isDefault: true,
            note: 'First draft',
            author: 'laptop',
        );

        $revision = $template->currentRevision()->sole();

        expect($template->fresh())
            ->key->toBe('monthly')
            ->name->toBe('Monthly news')
            ->description->toBe('Sent on the first of the month.')
            ->is_default->toBeTrue()
            ->and($revision)
            ->number->toBe(1)
            ->note->toBe('First draft')
            ->author->toBe('laptop')
            ->and($revision->contentFor('en'))
            ->subject->toBe('Subject Hello')
            ->text->toBe("Hello\n\n{{footer}}")
            ->text_generated->toBeTrue()
            ->and($revision->contentFor('fa'))
            ->text->toBe("Salam\n{{footer}}")
            ->text_generated->toBeFalse()
            ->and($revision->contentFor('de')->locale)->toBe('en');
    });

    it('saves nothing when the email breaks the rules', function (): void {
        expect(fn () => app(CreateTemplateAction::class)->execute('monthly', 'Monthly', [new EmailContent('en', 'Hi', '<p>No link</p>')]))
            ->toThrow(CampaignsException::class, 'the HTML has no unsubscribe link')
            ->and(Template::query()->count())->toBe(0)
            ->and(Revision::query()->count())->toBe(0);
    });

    it('refuses a key that is malformed, a starter template\'s or taken, and a blank name', function (): void {
        createTemplate();

        expect(fn () => createTemplate('Monthly News'))->toThrow(CampaignsException::class, 'can\'t be a key')
            ->and(fn () => createTemplate('starter'))->toThrow(CampaignsException::class, 'starter templates')
            ->and(fn () => createTemplate('monthly'))->toThrow(CampaignsException::class, 'A template with the key "monthly" already exists.')
            ->and(fn () => createTemplate('weekly', '   '))->toThrow(CampaignsException::class, 'The name is empty.')
            ->and(Template::query()->count())->toBe(1);
    });
});

describe('revisions', function (): void {
    it('numbers each new revision after the last and never changes an older one', function (): void {
        $template = createTemplate();
        $updatedAt = $template->updated_at;
        $this->travel(1)->minutes();

        $second = app(AddRevisionAction::class)->execute($template, [email(body: 'Second')], 'Shorter intro');
        $third = app(AddRevisionAction::class)->execute($template, [email(body: 'Third')]);

        expect([$second->number, $third->number])->toBe([2, 3])
            ->and($second->note)->toBe('Shorter intro')
            ->and($template->revisions()->orderBy('number')->first()->contentFor('en')->subject)->toBe('Subject Hello')
            ->and($template->currentRevision()->sole()->is($third))->toBeTrue()
            ->and($template->fresh()->updated_at->gt($updatedAt))->toBeTrue();
    });

    it('restores an earlier revision as a new one', function (): void {
        $template = createTemplate();
        $first = $template->currentRevision()->sole();
        app(AddRevisionAction::class)->execute($template, [email(body: 'Second', text: "Own text\n{{footer}}")]);

        $restored = app(RestoreRevisionAction::class)->execute($first, 'laptop');

        expect($restored)
            ->number->toBe(3)
            ->note->toBe('Restored from revision 1')
            ->author->toBe('laptop')
            ->and($restored->contentFor('en'))
            ->subject->toBe('Subject Hello')
            ->text->toBe("Hello\n\n{{footer}}")
            ->text_generated->toBeTrue()
            ->and(Revision::query()->count())->toBe(3);
    });

    it('changes a campaign\'s email only while it is a draft', function (CampaignStatus $status): void {
        $campaign = Campaign::factory()->create(['status' => $status]);

        $add = fn (): Revision => app(AddRevisionAction::class)->execute($campaign, [email()]);

        if ($status === CampaignStatus::Draft) {
            expect($add()->revisable->is($campaign))->toBeTrue();

            return;
        }

        expect($add)->toThrow(CampaignsException::class, "The campaign is {$status->value}, and only a draft's email can change.")
            ->and(Revision::query()->count())->toBe(0);
    })->with([CampaignStatus::Draft, CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Completed]);

    it('reads the campaign\'s status under its lock, not the copy in hand', function (): void {
        $campaign = Campaign::factory()->create();
        Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending]);

        expect(fn () => app(AddRevisionAction::class)->execute($campaign, [email()]))
            ->toThrow(CampaignsException::class, 'The campaign is sending');
    });

    it('refuses a note too long for its column', function (): void {
        expect(fn () => app(AddRevisionAction::class)->execute(createTemplate(), [email()], str_repeat('n', 256)))
            ->toThrow(CampaignsException::class, 'The note may be at most 255 characters long.');
    });

    // SQLite has one writer at a time, so the race can't happen there; the
    // unique number index (DataModelTest) is the backstop everywhere.
    it('makes a second writer wait for the first before numbering', function (string $driver): void {
        useDatabase($driver);

        // A frozen clock leaves touch() nothing to write, so only the locking
        // read can be what waits.
        $this->freezeTime();
        $template = createTemplate();

        config()->set('database.connections.second', config("database.connections.{$driver}"));
        $second = DB::connection('second');
        $second->beginTransaction();
        $second->table('campaign_templates')->where('id', $template->id)->lockForUpdate()->first();

        DB::statement($driver === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");

        try {
            expect(fn () => app(AddRevisionAction::class)->execute($template, [email(body: 'Second')]))
                ->toThrow(function (QueryException $exception): void {
                    expect(strtolower($exception->getSql()))->toContain('for update');
                });
        } finally {
            $second->rollBack();
            DB::purge('second');
        }

        expect(Revision::query()->count())->toBe(1)
            ->and(app(AddRevisionAction::class)->execute($template, [email(body: 'Second')])->number)->toBe(2);
    })->with(['mysql', 'pgsql']);
});

describe('duplicating, changing and archiving templates', function (): void {
    it('duplicates a template\'s current email into a new template', function (): void {
        $source = createTemplate();
        $source->update(['description' => 'Our monthly email.']);
        app(AddRevisionAction::class)->execute($source, [email(body: 'Latest')]);

        $copy = app(DuplicateTemplateAction::class)->execute('monthly', 'monthly-copy', 'Monthly copy', 'laptop');
        $revision = $copy->currentRevision()->sole();

        expect($copy)
            ->key->toBe('monthly-copy')
            ->description->toBe('Our monthly email.')
            ->is_default->toBeFalse()
            ->and($revision)
            ->number->toBe(1)
            ->note->toBe('Duplicated from template "monthly", revision 2')
            ->author->toBe('laptop')
            ->and($revision->contentFor('en')->subject)->toBe('Subject Latest');
    });

    it('starts a template from the package\'s starter', function (): void {
        $template = app(DuplicateTemplateAction::class)->execute('starter', 'news', 'News');
        $content = $template->currentRevision()->sole()->contentFor('en');

        expect($template->currentRevision()->sole()->note)->toBe('Duplicated from the starter template "starter"')
            ->and($content->html)->toContain('{{footer}}')
            ->and($content->preheader)->not->toBeNull()
            ->and($content->text_generated)->toBeTrue();
    });

    it('refuses an unknown source', function (): void {
        expect(fn () => app(DuplicateTemplateAction::class)->execute('nothing', 'copy', 'Copy'))
            ->toThrow(CampaignsException::class, 'There is no template with the key "nothing".');
    });

    it('changes a template\'s name, description and default mark, and nothing else', function (): void {
        $template = createTemplate();
        $template->update(['description' => 'Old']);

        app(UpdateTemplateAction::class)->execute($template, ['name' => 'Renamed', 'description' => null, 'is_default' => true]);

        expect($template->fresh())
            ->name->toBe('Renamed')
            ->description->toBeNull()
            ->is_default->toBeTrue()
            ->key->toBe('monthly')
            ->and(fn () => app(UpdateTemplateAction::class)->execute($template, ['key' => 'other']))
            ->toThrow(CampaignsException::class, 'not key')
            ->and(fn () => app(UpdateTemplateAction::class)->execute($template, ['name' => ' ']))
            ->toThrow(CampaignsException::class, 'The name is empty.')
            ->and(fn () => app(UpdateTemplateAction::class)->execute($template, ['name' => 5]))
            ->toThrow(CampaignsException::class, 'The name must be text.')
            ->and(fn () => app(UpdateTemplateAction::class)->execute($template, ['is_default' => 'yes']))
            ->toThrow(CampaignsException::class, 'must be true or false');
    });

    it('archives a template and brings it back, once each', function (): void {
        $template = createTemplate();
        $action = app(ArchiveTemplateAction::class);

        expect($action->execute($template))->toBeTrue()
            ->and($action->execute($template))->toBeFalse()
            ->and(Template::query()->active()->count())->toBe(0)
            ->and($template->archived_at)->not->toBeNull()
            ->and($action->execute($template, archived: false))->toBeTrue()
            ->and($template->fresh()->archived_at)->toBeNull()
            ->and($template->revisions()->count())->toBe(1);
    });
});
