<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;

/**
 * The tools that work on an email's revisions name whose email it is: a
 * template's, or a campaign's (which changes only while it is a draft). A
 * revision is always found through its owner, so a tool can't reach
 * another email's history by a revision's number or id.
 */
trait NamesEmailOwner
{
    /**
     * The rules of a revision's number: within the column's integer, since
     * PostgreSQL answers a larger one with a database error rather than no
     * row.
     *
     * @return list<string>
     */
    protected static function revisionRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'integer', 'min:1', 'max:2147483647'];
    }

    /**
     * @return array<string, Type>
     */
    protected static function ownerInput(JsonSchema $schema): array
    {
        return [
            'template' => $schema->string()->description('The template\'s key. Give this or campaign.'),
            'campaign' => $schema->string()->description('The campaign\'s key. Give this or template.'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function ownerRules(): array
    {
        return [
            'template' => ['nullable', 'string', 'required_without:campaign', 'prohibits:campaign'],
            'campaign' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws CampaignsException
     */
    protected static function owner(array $input): Template|Campaign
    {
        return isset($input['template'])
            ? Template::forKey((string) $input['template'])
            : Campaign::forKey((string) $input['campaign']);
    }

    /**
     * The owner's revision by its number, else its current one.
     *
     * @throws CampaignsException
     */
    protected static function revision(Template|Campaign $owner, ?int $number = null): Revision
    {
        $query = $number === null ? $owner->currentRevision() : $owner->revisions()->where('number', $number);

        return $query->first() ?? throw ($number === null
            ? CampaignsException::noRevision()
            : CampaignsException::invalidValue('revision', "{$number} doesn't exist"));
    }
}
