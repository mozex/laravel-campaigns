<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * How recently someone showed they read the app's email or used the app,
 * by the windows in campaigns.engagement. Campaigns go to the engaged first
 * and the silent last.
 */
enum EngagementTier: string
{
    /** Opened or clicked an email, or was active in the app, within engaged_days. */
    case Engaged = 'engaged';

    /** Did so within active_days, but not within engaged_days. */
    case Active = 'active';

    /** Neither within active_days, or never. */
    case Silent = 'silent';
}
