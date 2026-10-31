<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ImportContactsAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\DatabaseFailedException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Imports\ImportedContact;
use Mozex\Campaigns\Imports\ImportResult;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Mcp\Tools\BlockContactTool;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\DatabaseErrors;
use Mozex\Campaigns\Support\Token;
use Mozex\Campaigns\Tests\Fixtures\Member;

/**
 * Makes the database refuse every insert into the table, as a constraint or
 * a deadlock would, so its error's SQL carries the row's values.
 */
function refuseInserts(string $table): void
{
    DB::statement("CREATE TRIGGER refuse_{$table} BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'refused'); END");
}

/**
 * Whether the exception, or one it chains, says any of the values.
 *
 * @param  list<string>  $values
 */
function mentions(Throwable $exception, array $values): bool
{
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        foreach ($values as $value) {
            if (str_contains($current->getMessage(), $value)) {
                return true;
            }
        }
    }

    return false;
}

it('keeps the address out of a feedback job\'s database error', function (): void {
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'webhook-signing-key');
    Queue::fake([ProcessFeedbackJob::class]);
    // A spam report about mail an address change moved makes a row for the
    // old address.
    $person = Subscriber::factory()->create(['email' => 'reader-new@example.org']);
    $message = CampaignMessage::factory()->for($person)->create(['status' => MessageStatus::Sent, 'sent_at' => now()->subHour(), 'email' => 'reader-old@example.org']);
    $id = Str::random(22);

    $this->postJson(route('campaigns.webhooks', 'mailgun'), mailgunWebhook(mailgunEvent('complained', ['id' => $id, 'user-variables' => ['campaign-message' => $message->uuid]])))->assertOk();
    refuseInserts('campaign_subscribers');

    $thrown = null;

    try {
        app()->call([new ProcessFeedbackJob(ProviderEvent::query()->where('provider_event_id', $id)->sole()->id), 'handle']);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(CampaignsException::class)
        ->and($thrown?->getMessage())->toBe('The database refused a provider event\'s effects (SQLSTATE 23000).')
        ->and(mentions($thrown, ['reader-old@example.org']))->toBeFalse()
        ->and(ProviderEvent::query()->where('provider_event_id', $id)->sole()->processed_at)->toBeNull();
});

it('keeps the visitor\'s IP address and browser out of an unsubscribe\'s database error', function (): void {
    Exceptions::fake();
    [, $token] = sentMessage();
    refuseInserts('campaign_consent_records');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->withHeader('User-Agent', 'SecretBrowser/9.1')
        ->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])
        ->assertServerError();

    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => $exception->getMessage() === 'The database refused the unsubscribe (SQLSTATE 23000).'
        && ! mentions($exception, ['203.0.113.77', 'SecretBrowser']));
    Exceptions::assertNotReported(QueryException::class);
});

it('keeps the visitor\'s IP address and browser out of a confirmation\'s and a resubscribe\'s database error', function (): void {
    Exceptions::fake();
    $token = Token::generate();
    Subscription::factory()->for(Subscriber::factory()->create(), 'subscriber')->create(['status' => SubscriptionStatus::Pending, 'confirm_token_hash' => Token::hash($token)]);
    [$message, $unsubscribeToken] = sentMessage();
    $message->subscriber?->forceFill(['opted_out_at' => now()])->save();
    refuseInserts('campaign_consent_records');

    foreach (["/campaigns/c/{$token}", "/campaigns/u/{$unsubscribeToken}/subscribe"] as $page) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeader('User-Agent', 'SecretBrowser/9.1')
            ->post($page)
            ->assertServerError();
    }

    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => $exception->getMessage() === 'The database refused the confirmation (SQLSTATE 23000).');
    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => $exception->getMessage() === 'The database refused the subscription (SQLSTATE 23000).');
    Exceptions::assertNotReported(QueryException::class);
});

it('scrubs a webhook\'s database error', function (): void {
    Exceptions::fake();
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'webhook-signing-key');
    $message = CampaignMessage::factory()->create();
    refuseInserts('campaign_provider_events');

    $this->postJson(route('campaigns.webhooks', 'mailgun'), mailgunWebhook(mailgunEvent('delivered', ['user-variables' => ['campaign-message' => $message->uuid]])))
        ->assertServerError();

    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => $exception->getMessage() === 'The database refused a provider event (SQLSTATE 23000).');
    Exceptions::assertNotReported(QueryException::class);
});

it('reports a sync\'s database error, naming the record and never its address', function (): void {
    Exceptions::fake();
    $member = Member::withoutEvents(fn (): Member => Member::query()->create(['email' => 'secret-member@example.com']));
    refuseInserts('campaign_subscribers');

    $status = Artisan::call('campaigns:sync-list', ['list' => 'members', '--force' => true]);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain("The database refused record {$member->id}'s sync (SQLSTATE 23000).");

    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => ! mentions($exception, ['secret-member@example.com']));
    Exceptions::assertNotReported(QueryException::class);
});

it('passes a deadlock inside the app\'s transaction up from an import, without its values', function (): void {
    $list = CampaignList::factory()->create();
    Subscriber::creating(function (): void {
        throw new DeadlockException('Deadlock found when inserting ann@example.com', 0);
    });

    $thrown = null;

    try {
        DB::transaction(fn (): ImportResult => app(ImportContactsAction::class)->execute([new ImportedContact(email: 'ann@example.com')], $list, new ConsentContext(source: 'import', basis: ConsentBasis::Consent), dryRun: false));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(DeadlockException::class)
        ->and($thrown?->getCode())->toBe(40001)
        ->and(mentions($thrown, ['ann@example.com']))->toBeFalse();
});

it('answers a tool\'s database error without its values, and reports it', function (): void {
    Exceptions::fake();
    Subscriber::factory()->create(['email' => 'ann@example.com']);
    refuseInserts('campaign_consent_records');

    agent()->tool(BlockContactTool::class, ['email' => 'ann@example.com'])
        ->assertHasErrors(['The database refused the call (SQLSTATE 23000). It was reported.']);

    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => ! mentions($exception, ['ann@example.com']));
    Exceptions::assertNotReported(QueryException::class);
});

it('finds the SQLSTATE a deadlock keeps on the error it wraps', function (): void {
    $query = new QueryException('mysql', 'insert into t values (?)', ['ann@example.com'], new PDOException('Deadlock found'));
    $query->errorInfo = ['40001', 1213, 'Deadlock found'];
    $deadlock = new DeadlockException($query->getMessage(), 0, $query);

    $scrubbed = DatabaseErrors::scrub('the import', $deadlock);

    expect(DatabaseErrors::sqlState($deadlock))->toBe('40001')
        ->and($scrubbed->getMessage())->toBe('The database refused the import (SQLSTATE 40001).')
        ->and($scrubbed->getPrevious())->toBeNull();
});
