<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';

    /**
     * Its audience is frozen and it starts by itself at its scheduled time.
     */
    case Scheduled = 'scheduled';

    case Sending = 'sending';

    case Paused = 'paused';

    case Completed = 'completed';

    case Canceled = 'canceled';
}
