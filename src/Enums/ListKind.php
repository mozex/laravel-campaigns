<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum ListKind: string
{
    /** Kept in step with the app's own records through a list source. */
    case Dynamic = 'dynamic';

    /** Holds contacts added by hand, by an agent or by an import. */
    case Static = 'static';
}
