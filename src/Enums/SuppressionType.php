<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum SuppressionType: string
{
    case Bounce = 'bounce';

    case Complaint = 'complaint';

    case Unsubscribe = 'unsubscribe';
}
