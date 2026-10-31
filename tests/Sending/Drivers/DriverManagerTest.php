<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;

/**
 * An app's own driver that only sends, keeping what its creator gave it.
 */
class AcmeDriver implements CampaignDriver
{
    /** @var list<OutgoingMessage> */
    public array $sent = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public array $config, public ?Application $app = null) {}

    public function send(OutgoingMessage $message): SendReceipt
    {
        $this->sent[] = $message;

        return new SendReceipt('acme-'.$message->uuid);
    }
}

/**
 * An app's own driver whose service posts its events behind a shared secret.
 */
class AcmeHooksDriver extends AcmeDriver implements ReceivesWebhooks
{
    public function verifyWebhook(Request $request): bool
    {
        return hash_equals((string) ($this->config['secret'] ?? ''), (string) $request->header('X-Acme-Secret'));
    }

    public function parseWebhook(Request $request): array
    {
        return [new Feedback(
            providerEventId: (string) $request->json('id'),
            type: FeedbackType::Delivered,
            occurredAt: Carbon::now(),
            messageUuid: (string) $request->json('message'),
        )];
    }
}

class AcmeDriversServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Campaigns::extend('acme', fn (array $config, Application $app): CampaignDriver => new AcmeDriver($config, $app));
        Campaigns::extend('acme-hooks', fn (array $config): CampaignDriver => new AcmeHooksDriver($config));
    }
}

beforeEach(function (): void {
    app()->register(AcmeDriversServiceProvider::class);
});

function acme(string $name = 'acme'): AcmeDriver
{
    /** @var AcmeDriver */
    return app(DriverManager::class)->get($name);
}

it('sends a campaign through a driver an app registered, its creator given its own config block and the app', function (): void {
    Queue::fake([ProcessFeedbackJob::class]);
    $campaign = sendingCampaign(2);
    config()->set('campaigns.driver', 'acme');
    config()->set('campaigns.drivers.acme', ['region' => 'north']);

    app(PumpCampaignsAction::class)->execute();

    expect(acme()->config)->toBe(['region' => 'north'])
        ->and(acme()->app)->toBe(app())
        ->and(acme()->sent)->toHaveCount(2)
        ->and(app(CampaignDriver::class))->toBe(acme())
        ->and($campaign->messages()->pluck('driver')->unique()->all())->toBe(['acme'])
        ->and($campaign->messages()->pluck('status')->unique()->all())->toBe([MessageStatus::Sent])
        ->and($campaign->messages()->first()?->provider_message_id)->toStartWith('acme-');
});

it('answers a registered driver\'s webhooks, active or not, when it takes them, and 404 when it doesn\'t', function (): void {
    Queue::fake([ProcessFeedbackJob::class]);
    config()->set('campaigns.drivers.acme-hooks', ['secret' => 'shared-secret']);
    $message = CampaignMessage::factory()->create(['driver' => 'acme-hooks']);
    $post = fn (string $driver, string $secret = 'shared-secret') => $this->postJson(route('campaigns.webhooks', $driver), ['id' => 'acme-1', 'message' => $message->uuid], ['X-Acme-Secret' => $secret]);

    $post('acme-hooks')->assertOk();
    $post('acme-hooks', 'wrong')->assertStatus(406);
    $post('acme')->assertNotFound();

    expect(ProviderEvent::query()->sole())
        ->driver->toBe('acme-hooks')
        ->message_id->toBe($message->id);
});

it('refuses the reserved name and any name that can\'t be a path segment', function (string $name, string $says): void {
    expect(fn () => Campaigns::extend($name, fn (): CampaignDriver => new AcmeDriver([])))->toThrow(CampaignsException::class, $says);
})->with([
    'the package\'s own' => ['campaigns', 'records its own opens and clicks'],
    'upper case' => ['Acme', 'can\'t name a campaigns driver'],
    'a space' => ['acme mail', 'can\'t name a campaigns driver'],
    'a slash' => ['acme/mail', 'can\'t name a campaigns driver'],
    'past the column' => [str_repeat('a', 51), 'can\'t name a campaigns driver'],
    'empty' => ['', 'can\'t name a campaigns driver'],
]);

it('answers a driver\'s webhooks only while it is configured, never building one that isn\'t', function (): void {
    $manager = app(DriverManager::class);

    // Neither active nor holding its signing key; an unknown name; the
    // fake while another driver sends.
    $this->postJson(route('campaigns.webhooks', 'mailgun'), [])->assertNotFound();
    $this->postJson(route('campaigns.webhooks', 'postmark'), [])->assertNotFound();
    $this->postJson('/campaigns/webhooks/campaigns', [])->assertNotFound();

    expect(array_keys($manager->getDrivers()))->toBe([]);

    config()->set('campaigns.driver', 'acme');
    $this->postJson(route('campaigns.webhooks', 'fake'), [])->assertNotFound();

    // Once its key is set, Mailgun's are verified, whichever driver sends.
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'webhook-signing-key');
    $this->postJson(route('campaigns.webhooks', 'mailgun'), [])->assertStatus(406);

    expect(array_keys($manager->getDrivers()))->toBe(['mailgun']);
});

it('takes the fake\'s webhooks only while it is active, and only what a test told it to post', function (): void {
    Queue::fake([ProcessFeedbackJob::class]);
    $message = CampaignMessage::factory()->create();

    $this->postJson(route('campaigns.webhooks', 'fake'), [])->assertStatus(406);

    fakeDriver()->webhook(new Feedback(providerEventId: 'fake-1', type: FeedbackType::Delivered, occurredAt: now(), messageUuid: $message->uuid));
    $this->postJson(route('campaigns.webhooks', 'fake'), [])->assertOk();
    $this->postJson(route('campaigns.webhooks', 'fake'), [])->assertStatus(406);

    expect(ProviderEvent::query()->sole())->driver->toBe('fake')->message_id->toBe($message->id);
});

it('has the fake refuse production for the whole domain, sending nothing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $message = new OutgoingMessage(
        uuid: 'a', to: 'reader@example.org', fromEmail: 'news@news.example.com', fromName: null, replyTo: null, subject: 'Hi',
        html: '<p>Hi</p>', text: 'Hi', unsubscribeUrl: null, tag: 't', trackOpens: false, trackClicks: false,
    );

    expect(fn () => fakeDriver()->send($message))->toThrow(fn (SendFailed $failed) => expect($failed->failure)->toBe(SendFailure::DomainRefused))
        ->and(fakeDriver()->sent())->toBe([]);
});

it('names Mailgun nowhere in the package\'s code outside the drivers', function (): void {
    $named = [];
    $root = realpath(__DIR__.'/../../../src');
    $drivers = $root.DIRECTORY_SEPARATOR.'Sending'.DIRECTORY_SEPARATOR.'Drivers'.DIRECTORY_SEPARATOR;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && ! str_starts_with($file->getPathname(), $drivers) && stripos((string) file_get_contents($file->getPathname()), 'mailgun') !== false) {
            $named[] = substr($file->getPathname(), strlen((string) $root) + 1);
        }
    }

    expect($named)->toBe([]);
});

it('tells whether a driver reads back, reporting a creator that breaks rather than failing its caller', function (): void {
    Exceptions::fake();
    Campaigns::extend('broken', fn (): CampaignDriver => throw new RuntimeException('The app\'s creator failed.'));
    $drivers = app(DriverManager::class);

    expect($drivers->readsBack('fake'))->toBeTrue()
        ->and($drivers->readsBack('broken'))->toBeFalse()
        ->and($drivers->readsBack('nobody'))->toBeFalse()
        // Configured only while active or holding its signing key.
        ->and($drivers->readsBack('mailgun'))->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
});
