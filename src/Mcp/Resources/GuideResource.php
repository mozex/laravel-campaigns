<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;

/**
 * The full workflow an agent follows, longer than the instructions a
 * client keeps, read from the package's own file.
 */
#[Name('guide')]
#[Title('Campaigns guide')]
#[Description('How to run this app\'s email campaigns: the workflow from writing an email to its report, and the checks that come before every start.')]
#[Uri('campaigns://guide')]
#[MimeType('text/markdown')]
class GuideResource extends CampaignsResource
{
    public const PATH = __DIR__.'/../../../resources/mcp/guide.md';

    public function handle(): Response
    {
        return Response::text(static::text());
    }

    public static function text(): string
    {
        return (string) file_get_contents(static::PATH);
    }
}
