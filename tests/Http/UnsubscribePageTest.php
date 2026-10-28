<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\Token;

/**
 * Laravel skips its CSRF check under unit tests; this one checks anyway.
 */
class CsrfCheckedEvenInTests extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

/**
 * @return list<string>
 */
function routeMiddleware(string $method, string $uri): array
{
    return array_values(array_map(
        fn (mixed $middleware): string => (string) $middleware,
        Route::getRoutes()->match(Request::create($uri, $method))->gatherMiddleware(),
    ));
}

it('opens the page without changing anything, so link scanners can\'t unsubscribe anyone', function (string $method): void {
    config()->set('campaigns.name', 'Acme News');
    [$message, $token] = sentMessage();
    $before = $message->subscriber?->fresh()?->toArray();

    $response = $this->call($method, "/campaigns/u/{$token}");

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([])
        ->and($message->subscriber?->fresh()?->toArray())->toBe($before)
        ->and($message->fresh()?->unsubscribed_at)->toBeNull()
        ->and(ConsentRecord::query()->count())->toBe(0);

    if ($method === 'GET') {
        $response->assertSee('Unsubscribe from all Acme News emails?')
            ->assertSee((string) $message->email)
            ->assertSee('<form method="POST" action="/campaigns/u/'.$token.'">', false)
            ->assertDontSee('name="_token"', false);
    }
})->with(['GET', 'HEAD']);

it('unsubscribes on a mail app\'s one-click POST, with CSRF protection on and no session', function (): void {
    // The app's web group (its cookies need a key), its CSRF check (renamed
    // in Laravel 13) checking even in tests.
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];
    $csrf = array_values(array_filter($web, fn (string $middleware): bool => preg_match('/Csrf|Forgery/', $middleware) === 1));
    expect($csrf)->toHaveCount(1);
    $this->app->bind($csrf[0], CsrfCheckedEvenInTests::class);
    Route::post('/web-form', fn (): string => 'ok')->middleware('web');
    [$message, $token] = sentMessage();

    // The app's own forms are refused without a token, so CSRF is on.
    $this->post('/web-form')->assertStatus(419);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('User-Agent', 'GoogleOneClick/1.0')
        ->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click']);
    $again = $this->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click']);

    $response->assertOk()->assertSeeText('Unsubscribed.');
    $again->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Location'))->toBeNull()
        ->and($message->subscriber?->fresh()?->opted_out_at)->not->toBeNull()
        ->and($message->fresh()?->unsubscribed_at)->not->toBeNull()
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::OptOut)
        ->source->toBe('one_click')
        ->ip_address->toBe('198.51.100.7')
        ->user_agent->toBe('GoogleOneClick/1.0')
        ->meta->toBe(['message' => $message->uuid])
        ->and(routeMiddleware('POST', "/campaigns/u/{$token}"))
        ->not->toContain('web')
        ->not->toContain(StartSession::class)
        ->toContain('throttle:campaigns.links')
        ->and(routeMiddleware('GET', "/campaigns/u/{$token}"))
        ->not->toContain('web')
        ->toContain('throttle:60,1');
});

it('unsubscribes from the page\'s button and offers to subscribe again', function (): void {
    [$message, $token] = sentMessage();

    $this->post("/campaigns/u/{$token}")
        ->assertOk()
        ->assertSee("You're unsubscribed")
        ->assertSee('<form method="POST" action="/campaigns/u/'.$token.'/subscribe">', false);

    expect($message->subscriber?->fresh()?->opted_out_at)->not->toBeNull()
        ->and($message->fresh()?->unsubscribed_at)->not->toBeNull()
        ->and(ConsentRecord::query()->sole()->source)->toBe('unsubscribe_page');
});

it('counts an unsubscribe against the email only when it opted the person out', function (): void {
    [$message, $token] = sentMessage(['subscriber_id' => Subscriber::factory()->optedOut()]);

    $this->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect($message->fresh()?->unsubscribed_at)->toBeNull()
        ->and(ConsentRecord::query()->count())->toBe(0);
});

it('subscribes the person again, even while a double opt-in waits, and leaves their lists alone', function (): void {
    $subscriber = Subscriber::factory()->optedOut()->create();
    $waiting = Subscription::factory()->for($subscriber)->for(CampaignList::factory()->doubleOptIn()->create(), 'list')->pending()->create();
    // An agent took the person off this list since the email went out.
    $news = CampaignList::factory()->create();
    [$message, $token] = sentMessage(['subscriber_id' => $subscriber->id, 'list_ids' => [$news->id]]);

    $this->post("/campaigns/u/{$token}/subscribe")
        ->assertOk()
        ->assertSee("You're subscribed again")
        ->assertDontSee('<form', false);

    expect($subscriber->fresh()?->opted_out_at)->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $subscriber->id)->sole()->is($waiting))->toBeTrue()
        ->and($waiting->fresh()?->status)->toBe(SubscriptionStatus::Pending)
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::OptIn)
        ->source->toBe('unsubscribe_page')
        ->basis->toBe(ConsentBasis::Consent)
        ->meta->toBe(['message' => $message->uuid]);
});

it('never lets an old, forgotten or blocked address sign the person back up', function (Closure $makeMessage, string $heading, ?string $shownAddress): void {
    /** @var CampaignMessage $message */
    $message = $makeMessage();
    $token = Token::generate();
    $message->forceFill(['token_hash' => Token::hash($token)])->save();
    $before = $message->subscriber?->fresh()?->toArray();

    $page = $this->get("/campaigns/u/{$token}")
        ->assertOk()
        ->assertSee($heading)
        ->assertDontSee('/subscribe"', false)
        ->assertDontSee('new@example.com');
    $this->post("/campaigns/u/{$token}/subscribe")->assertOk()->assertDontSee("You're subscribed again");

    if ($shownAddress !== null) {
        $page->assertSee($shownAddress);
    }

    expect($message->subscriber?->fresh()?->toArray())->toBe($before)
        ->and(ConsentRecord::query()->where('action', ConsentAction::OptIn)->count())->toBe(0);
})->with([
    // The person moved to another address: whoever reads the old one mustn't
    // see the new one, or sign the person up.
    'an address the person left' => [
        fn (): CampaignMessage => CampaignMessage::factory()->create([
            'subscriber_id' => Subscriber::factory()->optedOut()->create(['email' => 'new@example.com'])->id,
            'email' => 'old@example.com',
        ]),
        "You're unsubscribed",
        'old@example.com',
    ],
    'a forgotten person' => [
        fn (): CampaignMessage => CampaignMessage::factory()->create([
            'subscriber_id' => Subscriber::factory()->optedOut()->create()->id,
            'email' => null,
        ]),
        "This address isn't on our lists",
        null,
    ],
    'a deleted subscriber' => [
        fn (): CampaignMessage => CampaignMessage::factory()->create(['subscriber_id' => null, 'email' => null]),
        "This address isn't on our lists",
        null,
    ],
    'a blocked address' => [
        fn (): CampaignMessage => CampaignMessage::factory()->create([
            'subscriber_id' => Subscriber::factory()->optedOut()->blocked(BlockReason::Complaint)->create()->id,
        ]),
        "We've stopped emailing this address",
        null,
    ],
]);

it('opens a test email\'s link like a real one and changes nothing', function (): void {
    $token = Token::generate();
    $test = TestSend::factory()->create(['token_hash' => Token::hash($token), 'email' => 'owner@example.com']);
    $subscribers = Subscriber::query()->count();

    $this->get("/campaigns/u/{$token}")
        ->assertOk()
        ->assertSee('Unsubscribe from all')
        ->assertSee('owner@example.com')
        ->assertSee('This link came from a test email');
    $this->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->post("/campaigns/u/{$token}")
        ->assertOk()
        ->assertSee("You're unsubscribed")
        ->assertSee('/campaigns/u/'.$token.'/subscribe', false);
    $this->post("/campaigns/u/{$token}/subscribe")->assertOk()->assertSee("You're subscribed again");

    expect($test->fresh()?->unsubscribed_at)->not->toBeNull()
        ->and(ConsentRecord::query()->count())->toBe(0)
        ->and(Subscriber::query()->count())->toBe($subscribers);
});

it('answers a link it doesn\'t know with a 404, and a malformed one without a lookup', function (): void {
    $this->get('/campaigns/u/'.Token::generate())
        ->assertNotFound()
        ->assertSee("This link doesn't work")
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->post('/campaigns/u/'.Token::generate(), ['List-Unsubscribe' => 'One-Click'])->assertNotFound();
    $this->post('/campaigns/u/'.Token::generate().'/subscribe')->assertNotFound();

    DB::enableQueryLog();
    $this->get('/campaigns/u/short')->assertNotFound();

    expect(DB::getQueryLog())->toBe([]);
});

it('limits each link\'s posts, never the server posting them', function (): void {
    // Mail apps post one-click unsubscribes for many people from one address.
    for ($i = 0; $i < 70; $i++) {
        [, $token] = sentMessage();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])
            ->assertOk();
    }

    [, $token] = sentMessage();

    for ($i = 0; $i < 10; $i++) {
        $this->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();
    }

    $this->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])->assertTooManyRequests();
});

it('shows the page in the subscriber\'s language and direction', function (?string $subscriberLocale, ?string $messageLocale, string $html): void {
    app('translator')->addLines(['pages.subscribed.heading' => 'إلغاء الاشتراك من :app؟'], 'ar', 'campaigns');
    app('translator')->addLines(['pages.subscribed.heading' => 'Cancelar a inscrição em :app?'], 'pt_BR', 'campaigns');
    $subscriber = Subscriber::factory()->create(['locale' => $subscriberLocale]);
    [, $token] = sentMessage(['subscriber_id' => $subscriber->id, 'locale' => $messageLocale]);

    $response = $this->get("/campaigns/u/{$token}")->assertOk()->assertSee($html, false);

    if ($subscriberLocale === 'ar') {
        $response->assertSee('إلغاء الاشتراك من');
    }

    expect(app()->getLocale())->toBe('en');
})->with([
    'the subscriber\'s, right to left' => ['ar', 'en', '<html lang="ar" dir="rtl">'],
    'the email\'s, when the subscriber has none' => [null, 'pt_BR', '<html lang="pt-BR" dir="ltr">'],
    'the app\'s, for one that isn\'t a locale' => ['../../etc', null, '<html lang="en" dir="ltr">'],
    // English text laid out right to left would be worse than English.
    'the app\'s, for a language with no strings' => ['fa', 'fa', '<html lang="en" dir="ltr">'],
]);
