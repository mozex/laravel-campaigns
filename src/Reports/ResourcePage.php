<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Support\ShownText;
use Throwable;

/**
 * One page of an app's own read-only data (campaigns.resources), as the
 * MCP resources and get_guide give it. The app decides a page's size, so a
 * page longer than an answer may be is refused and reported, as a tool's
 * answer is.
 */
class ResourcePage
{
    /** How much of a resource's description a list shows. */
    public const DESCRIPTION_LENGTH = 300;

    /**
     * The app's resources by name, with what each holds. One whose
     * description fails is reported and left out: the server lists these on
     * every request, and one broken resource must never stop an agent
     * pausing a campaign.
     *
     * @return list<array{name: string, description: ?string}>
     */
    public static function list(): array
    {
        $resources = [];

        foreach (app(Campaigns::class)->resources() as $key => $resource) {
            try {
                $resources[] = ['name' => $key, 'description' => ShownText::of($resource->description(), static::DESCRIPTION_LENGTH)];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $resources;
    }

    /**
     * @return array{resource: string, description: ?string, page: int, items: list<mixed>, next_page: ?int}
     *
     * @throws CampaignsException for a resource the app doesn't have, or a page too long to answer with
     */
    public static function read(string $key, int $page): array
    {
        $resource = app(Campaigns::class)->resource($key);
        $paginator = $resource->read(max(1, $page));

        $answer = [
            'resource' => $key,
            'description' => ShownText::of($resource->description(), static::DESCRIPTION_LENGTH),
            'page' => $paginator->currentPage(),
            'items' => array_values($paginator->items()),
            'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
        ];

        $length = mb_strlen((string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($length > CampaignsTool::RESULT_LIMIT) {
            report(new CampaignsException("The campaigns resource {$key}'s page {$page} was {$length} characters long, over the limit of ".CampaignsTool::RESULT_LIMIT.'.'));

            throw CampaignsException::resourcePageTooLong($key);
        }

        return $answer;
    }
}
