<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Support\UriTemplate;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;

/**
 * Any page of one of the app's own resources.
 */
#[Name('app-resource-page')]
#[Title('A page of the app\'s resources')]
#[Description('A page of one of the app\'s own read-only resources (get_guide lists them), as JSON: items and next_page.')]
#[MimeType('application/json')]
class AppDataPageResource extends CampaignsResource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(AppDataResource::SCHEME.'{name}/pages/{page}');
    }

    public function handle(Request $request): Response
    {
        $page = $request->get('page');

        // Clamped as the tools' page rules hold a page, so a huge one never
        // overflows the app's paginator.
        return AppDataResource::page((string) $request->get('name'), is_numeric($page) ? min(CampaignsTool::MAX_PAGE, max(1, (int) $page)) : 1);
    }
}
