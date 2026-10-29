<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Resources;

use Laravel\Mcp\Response;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Reports\ResourcePage;

/**
 * The first page of one of the app's own resources (campaigns.resources),
 * one per resource, made by the server in boot(); later pages are
 * AppDataPageResource's.
 */
class AppDataResource extends CampaignsResource
{
    public const SCHEME = 'campaigns://resources/';

    protected string $mimeType = 'application/json';

    public function __construct(protected string $key, string $description)
    {
        $this->name = $key;
        $this->title = $key;
        $this->description = $description.' A page as JSON: items and next_page, whose page is '.static::SCHEME.$key.'/pages/{page}.';
        $this->uri = static::SCHEME.$key;
    }

    public function handle(): Response
    {
        return static::page($this->key, 1);
    }

    public static function page(string $key, int $page): Response
    {
        try {
            $answer = ResourcePage::read($key, $page);
        } catch (CampaignsException $exception) {
            $answer = ['error' => $exception->getMessage()];
        }

        return Response::text((string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
