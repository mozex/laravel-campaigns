<?php

declare(strict_types=1);

use Mozex\Campaigns\Actions\ArchiveListAction;
use Mozex\Campaigns\Actions\CreateListAction;
use Mozex\Campaigns\Actions\UpdateListAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Tests\Fixtures\MemberList;

describe('creating a list', function (): void {
    it('makes a static list, its people waiting to confirm when it is double opt-in', function (): void {
        $list = app(CreateListAction::class)->execute('events', '  Event contacts ', 'People met at events', doubleOptIn: true, basis: ConsentBasis::SoftOptIn);

        expect($list->fresh())
            ->key->toBe('events')
            ->name->toBe('Event contacts')
            ->description->toBe('People met at events')
            ->kind->toBe(ListKind::Static)
            ->double_opt_in->toBeTrue()
            ->default_status->toBe(SubscriptionStatus::Pending)
            ->default_basis->toBe(ConsentBasis::SoftOptIn)
            ->and(app(CreateListAction::class)->execute('readers', 'Readers')->default_status)->toBe(SubscriptionStatus::Subscribed);
    });

    it('refuses a key that is invalid, taken, or a dynamic list\'s', function (): void {
        config(['campaigns.list_sources' => ['members' => MemberList::class]]);
        CampaignList::factory()->create(['key' => 'readers']);

        expect(fn () => app(CreateListAction::class)->execute('Bad Key', 'Bad'))->toThrow(CampaignsException::class, 'can\'t be a key')
            ->and(fn () => app(CreateListAction::class)->execute('readers', 'Again'))->toThrow(CampaignsException::class, 'A list with the key "readers" already exists.')
            ->and(fn () => app(CreateListAction::class)->execute('members', 'Members'))->toThrow(CampaignsException::class, 'dynamic list in campaigns.list_sources')
            ->and(CampaignList::query()->count())->toBe(1);
    });
});

describe('changing a list', function (): void {
    it('renames and describes a static list, its key kept', function (): void {
        $list = CampaignList::factory()->create(['key' => 'readers', 'name' => 'Readers', 'description' => 'Old']);

        app(UpdateListAction::class)->execute($list, ['name' => ' Weekly readers ']);
        app(UpdateListAction::class)->execute($list, ['description' => null]);

        expect($list->fresh())->key->toBe('readers')->name->toBe('Weekly readers')->description->toBeNull()
            ->and(fn () => app(UpdateListAction::class)->execute($list, ['key' => 'other']))->toThrow(CampaignsException::class, 'can only be to name, description, not key')
            ->and(fn () => app(UpdateListAction::class)->execute($list, []))->toThrow(CampaignsException::class, 'can only be to name, description.');
    });

    it('archives a static list and restores it, once each', function (): void {
        $this->freezeSecond();
        $list = CampaignList::factory()->create();

        expect(app(ArchiveListAction::class)->execute($list))->toBeTrue()
            ->and($list->archived_at?->equalTo(now()))->toBeTrue()
            ->and($list->fresh()->archived_at?->equalTo(now()))->toBeTrue()
            ->and(app(ArchiveListAction::class)->execute($list))->toBeFalse()
            ->and(app(ArchiveListAction::class)->execute($list, archived: false))->toBeTrue()
            ->and($list->fresh()->archived_at)->toBeNull()
            ->and(app(ArchiveListAction::class)->execute($list, archived: false))->toBeFalse();
    });

    it('leaves a dynamic list to the app', function (): void {
        $list = CampaignList::factory()->dynamic()->create(['key' => 'members', 'name' => 'Members']);

        expect(fn () => app(UpdateListAction::class)->execute($list, ['name' => 'Accounts']))->toThrow(CampaignsException::class, 'can\'t be renamed or archived')
            ->and(fn () => app(ArchiveListAction::class)->execute($list))->toThrow(CampaignsException::class, 'can\'t be renamed or archived')
            ->and($list->fresh())->name->toBe('Members')->archived_at->toBeNull();
    });
});
