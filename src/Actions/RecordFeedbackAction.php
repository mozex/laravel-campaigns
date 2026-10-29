<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mozex\Campaigns\Enums\FeedbackResult;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Models\WebhookToken;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Support\Rows;
use Mozex\Campaigns\Support\Token;

/**
 * Stores one event the provider reported, once, against the campaign message
 * or test email it is about, and queues its effects. An event is known by the
 * provider's id. A webhook's signature covers only its timestamp and one-time
 * token, never the body, so every verified webhook's token is recorded with
 * the event it carried, in the same transaction as the event: the same token
 * with the same event is a retry, answered as done; with another event it is
 * a replay, refused. Events about mail the package didn't send are dropped,
 * their token recorded all the same.
 */
class RecordFeedbackAction
{
    /**
     * Mail apps whose image loads are the person opening the email: Gmail
     * fetches images through its proxy only when the email is opened. Every
     * other machine (Apple's privacy prefetch, scanners) is a bot.
     */
    public const HUMAN_PROXIES = ['gmail'];

    public function execute(Feedback $feedback): FeedbackResult
    {
        [$messageId, $testSendId] = $this->target($feedback);

        $result = (new ProviderEvent)->getConnection()->transaction(function () use ($feedback, $messageId, $testSendId): FeedbackResult|ProviderEvent {
            if ($feedback->replayKey !== null) {
                $token = Rows::firstOrCreate(
                    WebhookToken::query(),
                    ['token_hash' => Token::hash($feedback->replayKey)],
                    ['provider_event_id' => $feedback->providerEventId],
                );

                if (! $token->wasRecentlyCreated) {
                    return $token->provider_event_id === $feedback->providerEventId
                        ? FeedbackResult::Duplicate
                        : FeedbackResult::Refused;
                }
            }

            if ($messageId === null && $testSendId === null) {
                return FeedbackResult::Unknown;
            }

            return $this->store($feedback, $messageId, $testSendId) ?? FeedbackResult::Duplicate;
        });

        if ($result instanceof ProviderEvent) {
            ProcessFeedbackJob::dispatch($result->id);

            return FeedbackResult::Stored;
        }

        if ($result === FeedbackResult::Duplicate) {
            $this->requeue($feedback);
        }

        return $result;
    }

    /**
     * The event's row, or null when it is stored already (a re-signed
     * retry, a second webhook, the provider's logs read back). Its own
     * savepoint, so the lost insert leaves the token's transaction usable on
     * PostgreSQL and the token is still recorded. Any other unique clash (a
     * primary key behind its sequence after a restore) is rethrown, so the
     * token rolls back with it and the provider's retry can store the event.
     */
    protected function store(Feedback $feedback, ?int $messageId, ?int $testSendId): ?ProviderEvent
    {
        try {
            return (new ProviderEvent)->getConnection()->transaction(fn (): ProviderEvent => ProviderEvent::query()->create([
                'provider_event_id' => $feedback->providerEventId,
                'message_id' => $messageId,
                'test_send_id' => $testSendId,
                'type' => $feedback->type,
                'is_bot' => $feedback->bot !== null && ! in_array($feedback->bot, static::HUMAN_PROXIES, true),
                'payload' => $this->payload($feedback),
                'occurred_at' => Carbon::instance($feedback->occurredAt)->setTimezone((string) config('app.timezone')),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $stored = ProviderEvent::query()
                ->where('provider_event_id', $feedback->providerEventId)
                ->sharedLock()
                ->exists();

            if (! $stored) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * A stored event whose job never ran (the queue was down when it was
     * stored, or every try failed) is queued again when the event comes
     * back; the job applies it once whatever runs it. A plain read: a stale
     * one costs at most one job too many or one left to the stored event's
     * own, while a locking one would wait behind a running job's lock.
     */
    protected function requeue(Feedback $feedback): void
    {
        $event = ProviderEvent::query()
            ->where('provider_event_id', $feedback->providerEventId)
            ->first(['id', 'processed_at']);

        if ($event !== null && $event->processed_at === null) {
            ProcessFeedbackJob::dispatch($event->id);
        }
    }

    /**
     * The campaign message or test email an event is about: by the uuid the
     * package sent with it, else by the provider's own message id. A uuid is
     * checked before it reaches a query, since PostgreSQL's uuid columns
     * answer a malformed one with an error, and another sender on the domain
     * may use the same variable name.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function target(Feedback $feedback): array
    {
        if ($feedback->messageUuid !== null && Str::isUuid($feedback->messageUuid)) {
            $id = CampaignMessage::query()->where('uuid', strtolower($feedback->messageUuid))->value('id');

            if (is_int($id)) {
                return [$id, null];
            }
        }

        if ($feedback->testSendUuid !== null && Str::isUuid($feedback->testSendUuid)) {
            $id = TestSend::query()->where('uuid', strtolower($feedback->testSendUuid))->value('id');

            if (is_int($id)) {
                return [null, $id];
            }
        }

        $providerMessageId = trim((string) $feedback->providerMessageId, "<> \t");

        if ($providerMessageId === '') {
            return [null, null];
        }

        $id = CampaignMessage::query()->where('provider_message_id', $providerMessageId)->value('id');

        if (is_int($id)) {
            return [$id, null];
        }

        $id = TestSend::query()->where('provider_message_id', $providerMessageId)->value('id');

        return [null, is_int($id) ? $id : null];
    }

    /**
     * What is kept of an event: the package's own fields and the provider's
     * details, never the recipient's address or IP. A clicked link keeps
     * neither a token of the package's own pages nor an address it carries.
     *
     * @return array<string, mixed>
     */
    protected function payload(Feedback $feedback): array
    {
        return array_filter([
            'severity' => $feedback->severity,
            'reason' => $feedback->reason,
            'code' => $feedback->code,
            'bot' => $feedback->bot,
            'url' => $feedback->url === null ? null : static::storedUrl($feedback->url),
            'provider_message_id' => $feedback->providerMessageId,
            'details' => $feedback->payload,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * A clicked address as it is kept: the token in a link to the package's
     * own pages replaced, any part holding an address (raw or encoded)
     * replaced, and the whole cut to 2,000 characters.
     */
    public static function storedUrl(string $url): string
    {
        $url = (string) preg_replace('#/campaigns/([ucv])/[A-Za-z0-9]{16,128}#', '/campaigns/$1/{token}', $url);
        $url = (string) preg_replace('#([/?&=\#])[^/?&=\#]*(?:@|%40)[^/?&=\#]*#i', '$1[address]', $url);

        return mb_strimwidth($url, 0, 2000, '...');
    }
}
