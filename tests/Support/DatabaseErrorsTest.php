<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Mcp\Tools\BlockContactTool;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\DatabaseErrors;

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
    config()->set('campaigns.mailgun.webhook_signing_key', 'webhook-signing-key');
    Queue::fake([ProcessFeedbackJob::class]);
    // A spam report about mail an address change moved makes a row for the
    // old address.
    $person = Subscriber::factory()->create(['email' => 'reader-new@example.org']);
    $message = CampaignMessage::factory()->for($person)->create(['status' => MessageStatus::Sent, 'sent_at' => now()->subHour(), 'email' => 'reader-old@example.org']);
    $id = Str::random(22);

    $this->postJson(route('campaigns.webhooks.mailgun'), mailgunWebhook(mailgunEvent('complained', ['id' => $id, 'user-variables' => ['campaign-message' => $message->uuid]])))->assertOk();
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

    Exceptions::assertReported(fn (CampaignsException $exception): bool => $exception->getMessage() === 'The database refused the unsubscribe (SQLSTATE 23000).'
        && ! mentions($exception, ['203.0.113.77', 'SecretBrowser']));
    Exceptions::assertNotReported(QueryException::class);
});

it('answers a tool\'s database error without its values, and reports it', function (): void {
    Exceptions::fake();
    Subscriber::factory()->create(['email' => 'ann@example.com']);
    refuseInserts('campaign_consent_records');

    agent()->tool(BlockContactTool::class, ['email' => 'ann@example.com'])
        ->assertHasErrors(['The database refused the call (SQLSTATE 23000). It was reported.']);

    Exceptions::assertReported(fn (CampaignsException $exception): bool => ! mentions($exception, ['ann@example.com']));
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
