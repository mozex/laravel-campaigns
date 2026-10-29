<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mozex\Campaigns\Actions\ResubscribeAction;
use Mozex\Campaigns\Actions\UnsubscribeAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Jobs\EnforceStopLossJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\DatabaseErrors;

/**
 * The page behind an email's unsubscribe and preferences links, and the
 * mail apps' one-click unsubscribe (RFC 8058). Opening the page changes
 * nothing, since link scanners open every link in an email; only a POST
 * does. A test email's link opens the same page and changes nothing.
 */
class UnsubscribeController extends PageController
{
    public function show(string $token): Response
    {
        $email = $this->email($token);

        return $email === null ? $this->invalid() : $this->page($token, $email);
    }

    public function unsubscribe(Request $request, string $token, UnsubscribeAction $unsubscribe): Response
    {
        $email = $this->email($token);
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';

        if ($email === null) {
            return $oneClick ? response('', 404) : $this->invalid();
        }

        // The opt-out's record holds the visitor's IP address and browser.
        $unsubscribed = DatabaseErrors::scrubbed('the unsubscribe', fn (): bool => $unsubscribe->execute($email, ConsentContext::fromRequest(
            $request,
            $oneClick ? UnsubscribeAction::ONE_CLICK : UnsubscribeAction::PAGE,
        )));

        // The opt-out stands whatever the queue does: a check that can't be
        // queued is reported, and the next unsubscribe queues it again.
        if ($unsubscribed && $email instanceof CampaignMessage) {
            rescue(function () use ($email): void {
                EnforceStopLossJob::dispatch($email->campaign_id);
            });
        }

        // A mail app's one-click gets a plain 200: RFC 8058 allows no
        // redirect, and nobody sees the answer.
        if ($oneClick) {
            return response('Unsubscribed.', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $this->page($token, $email, $email instanceof TestSend ? 'unsubscribed' : null);
    }

    public function resubscribe(Request $request, string $token, ResubscribeAction $resubscribe): Response
    {
        $email = $this->email($token);

        if ($email === null) {
            return $this->invalid();
        }

        if ($email instanceof TestSend) {
            return $this->page($token, $email, 'resubscribed');
        }

        $resubscribed = DatabaseErrors::scrubbed('the subscription', fn (): bool => $resubscribe->execute($email, ConsentContext::fromRequest(
            $request,
            UnsubscribeAction::PAGE,
            ConsentBasis::Consent,
            accepted: true,
        )));

        return $this->page($token, $email, $resubscribed ? 'resubscribed' : null);
    }

    /**
     * @param  ?string  $state  The state to show instead of the subscriber's: a test's, or the result of what was just done.
     */
    protected function page(string $token, CampaignMessage|TestSend $email, ?string $state = null): Response
    {
        if ($email instanceof TestSend) {
            return $this->render('campaigns::unsubscribe', [
                'state' => $state ?? 'subscribed',
                'email' => $email->email,
                'token' => $token,
                'canResubscribe' => true,
                'isTest' => true,
            ], [$email->locale]);
        }

        // Read again: the action may have changed it, or another request.
        $email->load('subscriber');
        $subscriber = $email->subscriber;

        return $this->render('campaigns::unsubscribe', [
            'state' => $state ?? match (true) {
                $subscriber === null || $email->email === null => 'gone',
                $subscriber->blocked_at !== null => 'blocked',
                $subscriber->opted_out_at !== null => 'unsubscribed',
                default => 'subscribed',
            },
            // The address this email went to, never the person's current
            // one: whoever reads an old address mustn't learn the new.
            'email' => $email->email,
            'token' => $token,
            'canResubscribe' => ResubscribeAction::allows($email),
            'isTest' => false,
        ], [$subscriber?->locale, $email->locale]);
    }
}
