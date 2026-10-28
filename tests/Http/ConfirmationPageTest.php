<?php

declare(strict_types=1);

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\Token;

/**
 * A double opt-in waiting for its confirmation, and the raw token of the
 * link its email carries.
 *
 * @return array{0: Subscription, 1: string}
 */
function waitingSubscription(?Subscriber $subscriber = null): array
{
    $token = Token::generate();

    $subscription = Subscription::factory()
        ->for($subscriber ?? Subscriber::factory()->create(), 'subscriber')
        ->for(CampaignList::factory()->doubleOptIn()->create(['name' => 'Weekly news']), 'list')
        ->pending()
        ->create(['confirm_token_hash' => Token::hash($token)]);

    return [$subscription, $token];
}

it('opens the confirmation page without confirming, so link scanners can\'t confirm anyone', function (string $method): void {
    [$subscription, $token] = waitingSubscription();

    $response = $this->call($method, "/campaigns/c/{$token}")
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    if ($method === 'GET') {
        $response->assertSee('Confirm that you want Weekly news emails')
            ->assertSee('<form method="POST" action="/campaigns/c/'.$token.'">', false);
    }

    expect($subscription->fresh())
        ->status->toBe(SubscriptionStatus::Pending)
        ->confirm_token_hash->toBe(Token::hash($token))
        ->and(ConsentRecord::query()->count())->toBe(0);
})->with(['GET', 'HEAD']);

it('confirms on the button\'s POST, clearing an opt-out, and then retires the link', function (): void {
    [$subscription, $token] = waitingSubscription(Subscriber::factory()->optedOut()->create());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->post("/campaigns/c/{$token}")
        ->assertOk()
        ->assertSee("You're subscribed")
        ->assertDontSee('<form', false);

    expect($subscription->fresh())
        ->status->toBe(SubscriptionStatus::Subscribed)
        ->confirm_token_hash->toBeNull()
        ->and($subscription->subscriber->fresh()?->opted_out_at)->toBeNull()
        ->and(ConsentRecord::query()->orderBy('id')->get()->map(fn (ConsentRecord $record): array => [
            $record->action, $record->source, $record->basis, $record->ip_address,
        ])->all())->toBe([
            [ConsentAction::Confirm, 'confirmation_page', ConsentBasis::Consent, '203.0.113.4'],
            [ConsentAction::OptIn, 'confirmation_page', ConsentBasis::Consent, '203.0.113.4'],
        ]);

    $this->get("/campaigns/c/{$token}")->assertNotFound()->assertSee("This link doesn't work");
    $this->post("/campaigns/c/{$token}")->assertNotFound();

    expect(ConsentRecord::query()->count())->toBe(2);
});

it('never names the address, which the person may have changed since', function (): void {
    [$subscription, $token] = waitingSubscription(Subscriber::factory()->create(['email' => 'old@example.com']));
    $subscription->subscriber->update(['email' => 'new@example.com']);

    $this->get("/campaigns/c/{$token}")->assertOk()->assertDontSee('new@example.com');
    $this->post("/campaigns/c/{$token}")->assertOk()->assertDontSee('new@example.com');
});

it('answers a link it doesn\'t know with a 404', function (): void {
    $this->get('/campaigns/c/'.Token::generate())->assertNotFound();
    $this->post('/campaigns/c/'.Token::generate())->assertNotFound();
});
