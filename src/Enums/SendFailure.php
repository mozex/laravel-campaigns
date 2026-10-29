<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * Why the provider didn't take a message, which decides what happens to it.
 */
enum SendFailure: string
{
    /**
     * The provider is busy or briefly down (a 429, or a 500, 502 or 503) and
     * took nothing: the message can wait and go later, at a slower rate.
     */
    case NotAccepted = 'not_accepted';

    /**
     * The provider refuses the sending domain or the account (a 401, 403 or
     * 404), or the app can't reach it as configured: nothing more can go
     * from that domain until someone acts.
     */
    case DomainRefused = 'domain_refused';

    /**
     * Anything else, a timeout or a lost connection among them: the provider
     * may have taken the message, so it is never sent again blindly, only
     * looked up in the provider's logs.
     */
    case Unclear = 'unclear';
}
