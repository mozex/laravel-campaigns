<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\Token;

/**
 * Every table's rows, to prove a request changed none of them.
 *
 * @return array<string, list<mixed>>
 */
function everyRow(): array
{
    $rows = [];

    foreach (['campaigns', 'campaign_messages', 'campaign_test_sends', 'campaign_subscribers', 'campaign_consent_records', 'campaign_revisions'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

it('shows the email a message carried, in the recipient\'s language, sandboxed, and changes nothing', function (): void {
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
    $frozen = Revision::factory()->of($campaign)
        ->withContent('en', ['html' => '<html><body><p>Hello {{first_name}}</p><a href="{{web_version_url}}">View</a>{{footer}}<script>alert(1)</script></body></html>', 'text' => '{{footer}}'])
        ->withContent('fr', ['html' => '<p>Bonjour {{first_name}}</p>{{footer}}', 'text' => '{{footer}}'])
        ->create();
    $campaign->update(['frozen_revision_id' => $frozen->id]);
    Revision::factory()->of($campaign)->withContent('en', ['html' => '<p>A later draft</p>{{footer}}'])->create();

    $subscriber = Subscriber::factory()->create(['first_name' => 'Zoë']);
    [$english, $token] = sentMessage(['campaign_id' => $campaign->id, 'subscriber_id' => $subscriber->id, 'email' => $subscriber->email, 'locale' => 'en']);
    [, $frenchToken] = sentMessage(['campaign_id' => $campaign->id, 'locale' => 'fr_CA']);
    $before = everyRow();

    $page = $this->get(Links::webVersion($token));
    $french = $this->get(Links::webVersion($frenchToken));

    $page->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('Hello Zoë', false)
        ->assertSee('href="'.Links::webVersion($token).'"', false)
        ->assertSee(Links::unsubscribe($token), false)
        ->assertSee($english->email, false)
        ->assertDontSee('A later draft');

    $policy = (string) $page->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'none'")
        ->and($policy)->toContain('sandbox allow-popups')
        ->and($policy)->not->toContain('allow-scripts')
        ->and($policy)->not->toContain('allow-same-origin')
        ->and($page->headers->getCookies())->toBe([])
        ->and($french->getContent())->toContain('Bonjour')
        ->and(everyRow())->toBe($before);
});

it('shows a test email\'s own revision, and nothing for an unknown or forgotten one', function (): void {
    $campaign = Campaign::factory()->create();
    $tested = Revision::factory()->of($campaign)->withContent('en', ['html' => '<p>Tested</p>{{footer}}'])->create();
    Revision::factory()->of($campaign)->withContent('en', ['html' => '<p>Edited after the test</p>{{footer}}'])->create();

    $token = Token::generate();
    TestSend::factory()->for($campaign)->create(['revision_id' => $tested->id, 'token_hash' => Token::hash($token)]);

    [, $forgottenToken] = sentMessage(['email' => null]);
    $before = everyRow();

    $this->get(Links::webVersion($token))->assertOk()->assertSee('Tested')->assertDontSee('Edited after the test');
    $this->get(Links::webVersion(Token::generate()))->assertNotFound();
    $this->get(Links::webVersion($forgottenToken))->assertNotFound();

    expect(everyRow())->toBe($before);
});
