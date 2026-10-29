<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * How one pre-send check came out. A failed check reports, it never blocks
 * a send: the person deciding reads the results.
 */
enum CheckStatus: string
{
    case Passed = 'passed';

    case Failed = 'failed';

    /** Not run: switched off, or a service it needs didn't answer. */
    case Skipped = 'skipped';
}
