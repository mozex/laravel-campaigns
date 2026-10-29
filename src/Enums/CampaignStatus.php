<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';

    /**
     * A start or schedule was accepted and its audience is being frozen.
     * Nothing about it changes meanwhile; it becomes scheduled or sending
     * once frozen, or a draft again with start_error saying why not.
     */
    case Starting = 'starting';

    /**
     * Its audience is frozen and it starts by itself at its scheduled time.
     */
    case Scheduled = 'scheduled';

    case Sending = 'sending';

    case Paused = 'paused';

    case Completed = 'completed';

    case Canceled = 'canceled';
}
