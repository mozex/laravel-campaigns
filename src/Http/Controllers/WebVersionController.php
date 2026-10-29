<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Response;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\MessageComposer;

/**
 * An email's "open it in your browser" page: the email as its recipient got
 * it, filled in through the same composer as the send. Opening it changes
 * nothing. The HTML is what an agent uploaded, shown on the app's own
 * origin, so it runs under a sandboxing policy: no script, no same-origin
 * access, no forms, while its links and images still work.
 */
class WebVersionController extends PageController
{
    protected const POLICY = "default-src 'none'; script-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; "
        ."font-src https: data:; media-src https:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; "
        .'sandbox allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation';

    public function __invoke(string $token, MessageComposer $composer): Response
    {
        $email = $this->email($token);

        if ($email === null) {
            return $this->invalid();
        }

        try {
            $rendered = $composer->rendered($email, $token);
        } catch (CampaignsException) {
            // The person was forgotten, or the email has no revision left.
            return $this->invalid();
        }

        return response($rendered->html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => static::POLICY,
        ]);
    }
}
