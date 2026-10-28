<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mozex\Campaigns\Actions\ConfirmSubscriptionAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\Token;

/**
 * The page behind a double opt-in email's link. Opening it only asks; the
 * button's POST confirms, so a link scanner opening the email can't confirm
 * an address for someone who never asked. It names no address: the person
 * may have changed theirs since the email went out, and whoever reads the
 * old one mustn't learn the new.
 */
class ConfirmationController extends PageController
{
    public function show(string $token): Response
    {
        $subscription = $this->find($token);

        if ($subscription === null) {
            return $this->invalid();
        }

        return $this->page($token, $subscription, confirmed: false);
    }

    public function confirm(Request $request, string $token, ConfirmSubscriptionAction $confirm): Response
    {
        $subscription = $this->find($token);

        if ($subscription === null) {
            return $this->invalid();
        }

        $confirm->execute($subscription, ConsentContext::fromRequest(
            $request,
            ConfirmSubscriptionAction::PAGE,
            ConsentBasis::Consent,
            accepted: true,
        ));

        return $this->page($token, $subscription, confirmed: true);
    }

    /**
     * Confirming clears the token, so a used link finds nothing.
     */
    protected function find(string $token): ?Subscription
    {
        return Subscription::query()
            ->with(['subscriber', 'list'])
            ->where('confirm_token_hash', Token::hash($token))
            ->where('status', SubscriptionStatus::Pending)
            ->first();
    }

    protected function page(string $token, Subscription $subscription, bool $confirmed): Response
    {
        return $this->render('campaigns::confirm', [
            'confirmed' => $confirmed,
            'list' => $subscription->list->name,
            'token' => $token,
        ], [$subscription->subscriber->locale]);
    }
}
