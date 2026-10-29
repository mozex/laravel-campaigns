<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Sending\StopLoss;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
});

/**
 * A sending campaign with the given clean sends, hard bounces, spam reports
 * and unsubscribes.
 *
 * @param  array<string, int|float>  $thresholds
 */
function measured(int $clean, int $hard = 0, int $complaints = 0, int $unsubscribes = 0, array $thresholds = []): Campaign
{
    $campaign = Campaign::factory()->sending()->create(['thresholds' => $thresholds ?: null]);

    sends($campaign, $clean);
    sends($campaign, $hard, hardBounce());
    sends($campaign, $complaints, ['complained_at' => now()]);
    sends($campaign, $unsubscribes, ['unsubscribed_at' => now()]);

    return $campaign;
}

describe('a campaign', function (): void {
    it('pauses at each limit, never below it', function (int $clean, int $hard, int $complaints, int $unsubscribes, ?string $reason): void {
        $breach = StopLoss::campaignBreach(measured($clean, $hard, $complaints, $unsubscribes));

        $reason === null
            ? expect($breach)->toBeNull()
            : expect($breach)->toContain($reason);
    })->with([
        'early: 10 hard bounces reached' => [189, 10, 0, 0, '10 of 199 emails bounced hard, reaching the limit of 10 before 200 emails (early_hard_bounces)'],
        'early: 9 in 199 (4.52%)' => [190, 9, 0, 0, null],
        'early: 5% of 60 reached' => [57, 3, 0, 0, '3 of 60 emails bounced hard (5%), reaching the limit of 5% (early_hard_bounce_rate)'],
        'early: 3 of 61 (4.92%)' => [58, 3, 0, 0, null],
        'early: 6.12% of 49, under the 50 sends the rate needs' => [46, 3, 0, 0, null],
        'early: 3 of 50 (6%), at the 50 sends the rate needs' => [47, 3, 0, 0, '3 of 50 emails bounced hard (6%)'],
        'from 200: 9 of 200 (4.5%) passes 2%' => [191, 9, 0, 0, '9 of the last 200 emails bounced hard (4.5%), past the limit of 2% (hard_bounce_rate)'],
        'from 200: 4 of 200, exactly 2%' => [196, 4, 0, 0, null],
        'from 200: 5 of 200 (2.5%)' => [195, 5, 0, 0, '5 of the last 200 emails bounced hard (2.5%)'],
        'window: 10 of 500, exactly 2%' => [490, 10, 0, 0, null],
        'window: 11 of 500' => [489, 11, 0, 0, '11 of the last 500 emails bounced hard (2.2%)'],
        'complaints: 2 reached before 2,500' => [2497, 0, 2, 0, '2 spam reports in 2,499 emails, reaching the limit of 2 before 2,500 emails (complaints)'],
        'complaints: 1 before 2,500' => [2498, 0, 1, 0, null],
        'complaints: 2 of 2,500, exactly 0.08%' => [2498, 0, 2, 0, null],
        'complaints: 3 of 2,500' => [2497, 0, 3, 0, '3 spam reports in 2,500 emails (0.12%), past the limit of 0.08% (complaint_rate)'],
        'unsubscribes: 7 of 500, exactly 1.4%' => [493, 0, 0, 7, null],
        'unsubscribes: 8 of 500' => [492, 0, 0, 8, '8 unsubscribes in 500 emails (1.6%), past the limit of 1.4% (unsubscribe_rate)'],
        'unsubscribes: 8 of 499, under the 500 sends it needs' => [491, 0, 0, 8, null],
        'nothing at all' => [10, 0, 0, 0, null],
    ]);

    it('reads its last 500 sends by when they went', function (): void {
        $campaign = Campaign::factory()->sending()->create();

        // 500 clean, then (written later, so with higher ids, as a resent or
        // lower-tier message has) 20 hard bounces sent before them: 3.3% of
        // all 600, none of the last 500 by time, 4% of the last 500 by id.
        sends($campaign, 500, at: now()->subHour());
        sends($campaign, 80, at: now()->subHours(3));
        sends($campaign, 20, hardBounce(), now()->subHours(3));

        expect(StopLoss::campaignBreach($campaign))->toBeNull();

        sends($campaign, 11, hardBounce(), now()->subMinute());

        expect(StopLoss::campaignBreach($campaign))->toContain('11 of the last 500 emails bounced hard');
    });

    it('counts only the emails the provider took, and only its hard bounces', function (): void {
        $campaign = Campaign::factory()->sending()->create();

        sends($campaign, 40);
        sends($campaign, 9, hardBounce());
        // Never handed over, and addresses the provider held as bounced from
        // before: sends, but no bounce of this campaign's.
        sends($campaign, 100, ['status' => 'pending', 'sent_at' => null, 'delivered_at' => null]);
        sends($campaign, 5, ['status' => 'failed', 'failure_reason' => 'suppressed_bounce']);

        // Counting the suppressions would reach the count of 10, and the
        // pending ones would make the rate 5.8% of 154.
        expect(StopLoss::campaignBreach($campaign))->toContain('9 of 54 emails bounced hard (16.667%)');

        sends($campaign, 100, ['status' => 'unknown', 'sent_at' => null, 'delivered_at' => null]);

        expect(StopLoss::campaignBreach($campaign))->toContain('9 of 54 emails');
    });

    it('takes its own thresholds over the config', function (): void {
        expect(StopLoss::campaignBreach(measured(100, complaints: 1, thresholds: ['complaints' => 1])))
            ->toContain('1 spam report in 101 emails, reaching the limit of 1 before 2,500 emails')
            ->and(StopLoss::campaignBreach(measured(100, hard: 12, thresholds: ['early_hard_bounces' => 20, 'early_hard_bounce_rate' => 50])))->toBeNull()
            // A limit of 0 trips on the first of what it counts, never on none.
            ->and(StopLoss::campaignBreach(measured(100, unsubscribes: 1, thresholds: ['early_hard_bounces' => 0, 'complaints' => 0])))->toBeNull()
            ->and(StopLoss::campaignBreach(measured(600, thresholds: ['unsubscribe_rate' => 0])))->toBeNull()
            ->and(StopLoss::campaignBreach(measured(600, unsubscribes: 1, thresholds: ['unsubscribe_rate' => 0])))->toContain('1 unsubscribe in 601 emails');
    });

    it('counts again from the stop-loss pause', function (): void {
        $campaign = measured(100, hard: 12);
        $campaign->forceFill(['limits_reset_at' => now()->subMinutes(30)])->save();

        expect(StopLoss::campaignBreach($campaign))->toBeNull();

        sends($campaign, 5, at: now()->subMinutes(10));
        sends($campaign, 1, hardBounce(), now()->subMinutes(10));

        expect(StopLoss::complaints($campaign))->toBe(0)
            ->and(StopLoss::campaignBreach($campaign))->toBeNull();

        sends($campaign, 9, hardBounce(), now()->subMinutes(5));

        expect(StopLoss::campaignBreach($campaign))->toContain('10 of 15 emails bounced hard');
    });

    it('measures the same on every database', function (string $driver): void {
        useDatabase($driver);

        $windowed = Campaign::factory()->sending()->create();
        sends($windowed, 20, hardBounce(), now()->subHours(3));
        sends($windowed, 489, at: now()->subHours(2));
        sends($windowed, 11, hardBounce(), now()->subHour());

        expect(StopLoss::campaignBreach($windowed))->toContain('11 of the last 500 emails bounced hard (2.2%)')
            ->and(StopLoss::campaignBreach(measured(492, unsubscribes: 8)))->toContain('8 unsubscribes in 500 emails')
            ->and(StopLoss::campaignBreach(measured(100, complaints: 2)))->toContain('2 spam reports in 102 emails')
            ->and(StopLoss::complaints(measured(100, complaints: 2)))->toBe(2)
            // Each limit exactly at its value.
            ->and(StopLoss::campaignBreach(measured(57, hard: 3)))->toContain('3 of 60 emails bounced hard (5%), reaching')
            ->and(StopLoss::campaignBreach(measured(196, hard: 4)))->toBeNull()
            ->and(StopLoss::campaignBreach(measured(2498, complaints: 2)))->toBeNull()
            ->and(StopLoss::campaignBreach(measured(493, unsubscribes: 7)))->toBeNull();
    })->with('databases');
});

describe('a sending domain', function (): void {
    it('pauses on its campaigns together over its last 24 hours', function (string $driver): void {
        useDatabase($driver);

        // Each within its own limits (6 of 46, under the 50 sends a rate
        // needs); together 12 hard bounces in 92.
        foreach (['first', 'second'] as $key) {
            $campaign = Campaign::factory()->sending()->create(['key' => $key]);
            sends($campaign, 40);
            sends($campaign, 6, hardBounce());
            expect(StopLoss::campaignBreach($campaign))->toBeNull();
        }

        // Another domain's, and the domain's own a day and a second ago.
        $other = Campaign::factory()->sending()->create(['from_email' => 'team@other.example.com']);
        sends($other, 50, hardBounce());
        $old = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()->subHours(2)]);
        sends($old, 50, hardBounce(), now()->subDay()->subSecond());

        expect(StopLoss::domainBreach('news.example.com', now()))->toBe('12 of 92 emails bounced hard, reaching the limit of 10 before 200 emails (early_hard_bounces).')
            ->and(StopLoss::domainBreach('example.com', now()))->toBeNull();
    })->with('databases');

    it('holds a campaign to the config, never to its own thresholds, and counts again from its last pause', function (): void {
        $loose = Campaign::factory()->sending()->create(['thresholds' => ['complaints' => 50]]);
        sends($loose, 100);
        sends($loose, 2, ['complained_at' => now()]);

        expect(StopLoss::campaignBreach($loose))->toBeNull()
            ->and(StopLoss::domainBreach('news.example.com', now()))->toContain('2 spam reports in 102 emails');

        // A finished campaign that was paused with the domain still sets
        // where the domain's numbers start.
        $finished = Campaign::factory()->create([
            'status' => CampaignStatus::Completed,
            'completed_at' => now()->subHours(3),
            'domain_limits_reset_at' => now()->subMinutes(30),
        ]);
        sends($finished, 1, at: now()->subHours(4));

        expect(StopLoss::domainBreach('news.example.com', now()))->toBeNull();

        sends($loose, 2, ['complained_at' => now()], now()->subMinutes(5));

        expect(StopLoss::domainBreach('news.example.com', now()))->toContain('2 spam reports in 2 emails');
    });
});
