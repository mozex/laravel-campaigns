<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\MessageComposer;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Support\TestAddresses;
use Mozex\Campaigns\Support\Token;
use Throwable;

/**
 * Sends one test email. It carries only the test's id: the links' token and
 * the tracking's are made here, their hashes stored with the active driver's
 * name by a conditional update that only one run can make, so a duplicate
 * job sends nothing and a raw token never sits in the queue. A send that
 * fails is recorded on the test (failed_at, failure) for the agent that
 * asked to read.
 */
class SendTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * A retry could send the same test twice.
     */
    public int $tries = 1;

    /**
     * Above the 30 seconds a driver gives its mailer when the app set no
     * limit of its own.
     */
    public int $timeout = 60;

    public function __construct(public int $testSendId)
    {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
    }

    public function handle(DriverManager $drivers, MessageComposer $composer): void
    {
        $test = TestSend::query()->find($this->testSendId);

        if ($test === null || $test->token_hash !== null || $test->failed_at !== null) {
            return;
        }

        // Checked again here: the config may have changed since it queued.
        if (! config('campaigns.enabled')) {
            $this->markFailed($test, 'Sending was switched off before the test went out.', claimed: false);

            return;
        }

        if (! TestAddresses::allows($test->email)) {
            $this->markFailed($test, 'The address is no longer one of the test addresses (campaigns.test_addresses).', claimed: false);

            return;
        }

        try {
            $driver = $drivers->active();
        } catch (CampaignsException $exception) {
            $this->markFailed($test, $exception->getMessage(), claimed: false);

            return;
        }

        $token = Token::generate();
        $trackingToken = Token::generate();

        $claimed = TestSend::query()
            ->whereKey($test->id)
            ->whereNull('token_hash')
            ->whereNull('failed_at')
            ->update([
                'token_hash' => Token::hash($token),
                'tracking_token_hash' => Token::hash($trackingToken),
                'driver' => $drivers->getDefaultDriver(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        try {
            $receipt = $driver->send($composer->outgoing($test, $token, $trackingToken));
        } catch (SendFailed|CampaignsException $exception) {
            $this->markFailed($test, $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->markFailed($test, 'The test failed ('.class_basename($exception).').');

            throw $exception;
        }

        TestSend::query()->whereKey($test->id)->update([
            'provider_message_id' => mb_substr($receipt->providerMessageId, 0, 255),
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A job that died (a timeout, a lost worker) after claiming its test
     * leaves it neither sent nor failed: it is marked failed, as it may
     * have gone, so the agent polling it gets an answer.
     */
    public function failed(?Throwable $exception): void
    {
        TestSend::query()
            ->whereKey($this->testSendId)
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->update([
                'failed_at' => now(),
                'failure' => 'The test stopped before it was confirmed sent, so it may still arrive.',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  bool  $claimed  whether this job holds the test's claim; one that doesn't may only fail a
     *                         test no other job has claimed, which may be sending it this moment
     */
    protected function markFailed(TestSend $test, string $reason, bool $claimed = true): void
    {
        TestSend::query()
            ->whereKey($test->id)
            ->when(! $claimed, fn (Builder $query): Builder => $query->whereNull('token_hash'))
            ->update([
                'failed_at' => now(),
                'failure' => mb_strimwidth($reason, 0, 255, '...'),
                'updated_at' => now(),
            ]);
    }
}
