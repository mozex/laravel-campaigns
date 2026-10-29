<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * What an agent signed in to the MCP server may do: a token carries its own,
 * and a person signed in through the app's own guard gets theirs from the
 * campaigns.read and campaigns.full gate abilities.
 */
enum Rights: string
{
    /** The read-only tools: lists, people, templates, campaigns and their numbers. */
    case Read = 'read';

    /** Every tool, the ones that change something included. */
    case Full = 'full';

    public function allows(self $needed): bool
    {
        return $this === self::Full || $needed === self::Read;
    }

    /**
     * The gate ability that grants these rights to a person.
     */
    public function ability(): string
    {
        return 'campaigns.'.$this->value;
    }
}
