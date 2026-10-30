<?php

declare(strict_types=1);

use Mozex\Campaigns\Actions\StartCampaignAction;
use Mozex\Campaigns\Audiences\AudienceDefinition;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\Audiences\SubscriberFilters;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MembersInCountry;

beforeEach(fn () => sendFromExample());

describe('the audience', function (): void {
    it('previews exactly the recipients a start freezes', function (string $driver): void {
        useDatabase($driver);
        $this->freezeTime();
        [$news, $offers, $other] = CampaignList::factory()->count(3)->create();
        $members = CampaignList::factory()->dynamic()->create(['key' => 'members']);

        $both = subscriberOn([$news, $offers], ['email' => 'both@gmail.com', 'locale' => 'fr', 'last_clicked_at' => now()->subDays(10)]);
        subscriberOn([$news], ['email' => 'pending@example.com'], SubscriptionStatus::Pending);
        subscriberOn([$news], ['email' => 'off-list@example.com'], SubscriptionStatus::Unsubscribed);
        subscriberOn([$other], ['email' => 'elsewhere@example.com']);
        subscriberOn([$news])->forceFill(['opted_out_at' => now(), 'blocked_at' => now()])->save();
        subscriberOn([$offers], ['opted_out_at' => now()]);
        subscriberOn([$news], ['first_mailed_at' => now()->subYears(2), 'last_opened_at' => now()->subYears(2)]);
        $active = subscriberOn([$offers], ['email' => 'active@outlook.com', 'locale' => null, 'last_active_at' => now()->subDays(200)]);
        $silent = subscriberOn([$news], ['email' => 'silent@yahoo.co.uk', 'locale' => 'fr']);
        $member = subscriberOn([$members], ['email' => 'member@icloud.com']);

        $campaign = Campaign::factory()->create(['target_lists' => [$news->id, $offers->id, $members->id]]);
        Revision::factory()->of($campaign)->withContent()->create();
        $preview = CampaignAudience::for($campaign)->preview();

        app(StartCampaignAction::class)->execute($campaign, $preview->recipients);
        $messages = CampaignMessage::query()->orderBy('position')->get();
        $byList = [$news->key => 2, $offers->key => 2, 'members' => 1];
        ksort($byList);

        expect($preview->toArray())->toBe([
            'recipients' => 4,
            'targeted' => 7,
            'dropped' => ['blocked' => 1, 'opted_out' => 1, 'sunset' => 1],
            'by_list' => $byList,
            'by_locale' => ['en' => 1, 'fr' => 2, 'none' => 1],
            'by_tier' => ['engaged' => 1, 'active' => 1, 'silent' => 2],
            'by_provider' => ['gmail' => 1, 'microsoft' => 1, 'yahoo' => 1, 'apple' => 1, 'other' => 0],
            'unmatched_emails' => 0,
        ])
            ->and($campaign->fresh())
            ->status->toBe(CampaignStatus::Sending)
            ->recipient_count->toBe(4)
            ->and($messages->pluck('subscriber_id')->sort()->values()->all())->toEqual(collect([$both, $active, $silent, $member])->pluck('id')->sort()->values()->all())
            ->and($messages->firstWhere('subscriber_id', $both->id))
            ->list_ids->toEqual([$news->id, $offers->id])
            ->email->toBe('both@gmail.com')
            ->locale->toBe('fr')
            ->status->toBe(MessageStatus::Pending);
    })->with('databases');

    it('counts languages as written, as the report does, whatever the database\'s collation', function (string $driver): void {
        useDatabase($driver);
        $list = CampaignList::factory()->create();
        subscriberOn([$list], ['locale' => 'pt_BR']);
        subscriberOn([$list], ['locale' => 'pt_br']);

        // MySQL's case-insensitive collation would merge them in a GROUP BY.
        expect((new CampaignAudience([$list->id], AudienceDefinition::fromStored(null)))->preview()->byLocale)->toBe(['pt_BR' => 1, 'pt_br' => 1]);
    })->with('databases');

    it('narrows the lists by every part of the audience, given together', function (): void {
        config()->set('campaigns.audiences', ['in-country' => MembersInCountry::class]);
        $news = CampaignList::factory()->create();

        // A member's subscriber, also on the news list, with a plan.
        $make = function (string $country, string $plan) use ($news): Subscriber {
            $member = Member::query()->create(['email' => fake()->unique()->safeEmail(), 'country' => $country]);
            $subscriber = Subscriber::findFor($member);
            $subscriber->forceFill(['custom_fields' => ['plan' => $plan]])->save();
            Subscription::factory()->for($news, 'list')->for($subscriber)->create();

            return $subscriber;
        };

        // Each of the others fails exactly one part.
        $match = $make('FR', 'pro');
        $otherCountry = $make('DE', 'pro');
        $otherPlan = $make('FR', 'free');
        $withLocale = $make('FR', 'pro');
        $withLocale->forceFill(['locale' => 'en'])->save();
        $notARecord = $make('FR', 'pro');
        $notASubscriber = $make('FR', 'pro');
        SavedAudience::factory()->create(['key' => 'pros', 'filters' => ['custom_fields' => ['plan' => 'pro']]]);
        $records = collect([$match, $otherCountry, $otherPlan, $withLocale, $notASubscriber])->pluck('subscribable_id')->all();

        $definition = AudienceDefinition::fromArray([
            'audience' => ['name' => 'in-country', 'parameters' => ['country' => 'FR']],
            'saved_audience' => 'pros',
            'filters' => ['locales' => [null]],
            'records' => ['members' => [...$records, 999]],
            'subscribers' => collect([$match, $otherCountry, $otherPlan, $withLocale, $notARecord])->pluck('id')->all(),
        ]);

        $named = fn (array $given): array => (new CampaignAudience([$news->id], AudienceDefinition::fromArray($given)))->recipients()->orderBy('id')->pluck('id')->all();

        expect((new CampaignAudience([$news->id], $definition))->recipients()->pluck('id')->all())->toBe([$match->id])
            ->and($named(['subscribers' => [$match->id], 'emails' => [$otherPlan->email]]))->toBe([$match->id, $otherPlan->id])
            ->and($named(['emails' => ['nobody@example.com']]))->toBe([])
            ->and($named(['subscribers' => []]))->toBe([])
            ->and(fn () => (new CampaignAudience([], $definition))->targeted())
            ->toThrow(CampaignsException::class, 'The campaign has no target lists');
    });

    it('targets what people did with an earlier campaign', function (string $driver): void {
        useDatabase($driver);
        $list = CampaignList::factory()->create();
        $earlier = Campaign::factory()->create(['key' => 'earlier']);
        $people = [];

        foreach (['clicked' => MessageStatus::Delivered, 'opened' => MessageStatus::Delivered, 'ignored' => MessageStatus::Sent, 'bounced' => MessageStatus::Failed, 'never' => null] as $name => $status) {
            $people[$name] = subscriberOn([$list], ['email' => "{$name}@example.com"]);

            if ($status !== null) {
                CampaignMessage::factory()->for($earlier)->for($people[$name])->create([
                    'status' => $status,
                    'opened_at' => in_array($name, ['clicked', 'opened'], true) ? now() : null,
                    'clicked_at' => $name === 'clicked' ? now() : null,
                ]);
            }
        }

        $who = fn (string $outcome): array => SubscriberFilters::fromArray(['campaigns' => [['campaign' => 'earlier', 'outcome' => $outcome]]])
            ->apply(Subscriber::query())
            ->orderBy('email')
            ->pluck('email')
            ->map(fn (string $email): string => strstr($email, '@', true))
            ->all();

        expect($who('received'))->toBe(['clicked', 'ignored', 'opened'])
            ->and($who('not_received'))->toBe(['bounced', 'never'])
            ->and($who('opened'))->toBe(['clicked', 'opened'])
            ->and($who('not_opened'))->toBe(['ignored'])
            ->and($who('clicked'))->toBe(['clicked'])
            ->and($who('not_clicked'))->toBe(['ignored', 'opened'])
            ->and(SubscriberFilters::fromArray(['campaigns' => [['campaign' => 'earlier', 'outcome' => 'opened']]])->toArray())
            ->toBe(['campaigns' => [['campaign' => 'earlier', 'outcome' => 'opened']]]);
    })->with('databases');
});
