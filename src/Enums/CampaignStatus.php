<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';

    case Sending = 'sending';

    case Paused = 'paused';

    case Completed = 'completed';

    case Canceled = 'canceled';
}
