<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp;

use Composer\InstalledVersions;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Resource as McpResource;
use Laravel\Mcp\Server\Tool;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Mcp\Resources\AppDataPageResource;
use Mozex\Campaigns\Mcp\Resources\AppDataResource;
use Mozex\Campaigns\Mcp\Resources\GuideResource;
use Mozex\Campaigns\Mcp\Tools\AddContactsTool;
use Mozex\Campaigns\Mcp\Tools\AddImageTool;
use Mozex\Campaigns\Mcp\Tools\AddRevisionTool;
use Mozex\Campaigns\Mcp\Tools\ArchiveListTool;
use Mozex\Campaigns\Mcp\Tools\ArchiveTemplateTool;
use Mozex\Campaigns\Mcp\Tools\BlockContactTool;
use Mozex\Campaigns\Mcp\Tools\CancelCampaignTool;
use Mozex\Campaigns\Mcp\Tools\CreateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\CreateListTool;
use Mozex\Campaigns\Mcp\Tools\CreateTemplateTool;
use Mozex\Campaigns\Mcp\Tools\CreateUploadLinkTool;
use Mozex\Campaigns\Mcp\Tools\DuplicateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignReportTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignStatusTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetCheckResultsTool;
use Mozex\Campaigns\Mcp\Tools\GetGuideTool;
use Mozex\Campaigns\Mcp\Tools\GetPreviewLinkTool;
use Mozex\Campaigns\Mcp\Tools\GetSubscriberTool;
use Mozex\Campaigns\Mcp\Tools\GetTemplateTool;
use Mozex\Campaigns\Mcp\Tools\GetTestSendsTool;
use Mozex\Campaigns\Mcp\Tools\ListAssetsTool;
use Mozex\Campaigns\Mcp\Tools\ListAudiencesTool;
use Mozex\Campaigns\Mcp\Tools\ListCampaignsTool;
use Mozex\Campaigns\Mcp\Tools\ListListsTool;
use Mozex\Campaigns\Mcp\Tools\ListRevisionsTool;
use Mozex\Campaigns\Mcp\Tools\ListSubscribersTool;
use Mozex\Campaigns\Mcp\Tools\ListTemplatesTool;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Mcp\Tools\PauseCampaignTool;
use Mozex\Campaigns\Mcp\Tools\PreviewAudienceTool;
use Mozex\Campaigns\Mcp\Tools\PreviewContactsImportTool;
use Mozex\Campaigns\Mcp\Tools\RemoveContactsTool;
use Mozex\Campaigns\Mcp\Tools\RestoreRevisionTool;
use Mozex\Campaigns\Mcp\Tools\ResumeCampaignTool;
use Mozex\Campaigns\Mcp\Tools\RunChecksTool;
use Mozex\Campaigns\Mcp\Tools\RunContactsImportTool;
use Mozex\Campaigns\Mcp\Tools\SaveAudienceTool;
use Mozex\Campaigns\Mcp\Tools\SendTestTool;
use Mozex\Campaigns\Mcp\Tools\SetContactFieldsTool;
use Mozex\Campaigns\Mcp\Tools\SlowDownCampaignTool;
use Mozex\Campaigns\Mcp\Tools\SpeedUpCampaignTool;
use Mozex\Campaigns\Mcp\Tools\StartCampaignTool;
use Mozex\Campaigns\Mcp\Tools\UnblockContactTool;
use Mozex\Campaigns\Mcp\Tools\UnsubscribeContactTool;
use Mozex\Campaigns\Mcp\Tools\UpdateCampaignTool;
use Mozex\Campaigns\Mcp\Tools\UpdateListTool;
use Mozex\Campaigns\Mcp\Tools\UpdateTemplateTool;
use Mozex\Campaigns\Reports\ResourcePage;

/**
 * The MCP server agents run the app's campaigns through, at
 * campaigns.mcp.path. Its tools call the package's actions and hold no
 * logic of their own, so a later screen can call the same code. It resolves
 * through the container, so an app can bind a subclass.
 *
 * Its name, version and instructions are set in boot(), because laravel/mcp
 * reads an attribute before the property and the instructions hold the
 * app's own addition.
 */
class CampaignsServer extends Server
{
    /**
     * What Claude Code keeps of a server's instructions.
     */
    public const INSTRUCTIONS_LIMIT = 4096;

    public const INSTRUCTIONS_PATH = __DIR__.'/../../resources/mcp/instructions.md';

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        OverviewTool::class,
        ListListsTool::class,
        CreateListTool::class,
        UpdateListTool::class,
        ArchiveListTool::class,
        AddContactsTool::class,
        RemoveContactsTool::class,
        SetContactFieldsTool::class,
        ListSubscribersTool::class,
        GetSubscriberTool::class,
        UnsubscribeContactTool::class,
        BlockContactTool::class,
        UnblockContactTool::class,
        PreviewContactsImportTool::class,
        RunContactsImportTool::class,
        CreateUploadLinkTool::class,
        ListAssetsTool::class,
        AddImageTool::class,
        ListTemplatesTool::class,
        GetTemplateTool::class,
        CreateTemplateTool::class,
        UpdateTemplateTool::class,
        ArchiveTemplateTool::class,
        AddRevisionTool::class,
        ListRevisionsTool::class,
        RestoreRevisionTool::class,
        RunChecksTool::class,
        GetCheckResultsTool::class,
        GetPreviewLinkTool::class,
        ListCampaignsTool::class,
        GetCampaignTool::class,
        CreateCampaignTool::class,
        UpdateCampaignTool::class,
        DuplicateCampaignTool::class,
        ListAudiencesTool::class,
        SaveAudienceTool::class,
        PreviewAudienceTool::class,
        SendTestTool::class,
        GetTestSendsTool::class,
        StartCampaignTool::class,
        PauseCampaignTool::class,
        ResumeCampaignTool::class,
        SlowDownCampaignTool::class,
        SpeedUpCampaignTool::class,
        CancelCampaignTool::class,
        GetCampaignStatusTool::class,
        GetCampaignReportTool::class,
        GetGuideTool::class,
    ];

    /** @var array<int, McpResource|class-string<McpResource>> */
    protected array $resources = [
        GuideResource::class,
    ];

    protected function boot(): void
    {
        $resources = ResourcePage::list();

        // The app's own resources: each one's first page, and a template for
        // the rest.
        foreach ($resources as $resource) {
            $this->resources[] = new AppDataResource($resource['name'], (string) $resource['description']);
        }

        if ($resources !== []) {
            $this->resources[] = AppDataPageResource::class;
        }

        $this->name = app(Campaigns::class)->name().' campaigns';
        $this->version = InstalledVersions::isInstalled('mozex/laravel-campaigns')
            ? (string) InstalledVersions::getPrettyVersion('mozex/laravel-campaigns')
            : $this->version;
        $this->instructions = static::instructions();

        // laravel/mcp lists 15 at a time and at most 50: a client that reads
        // only the first page would miss the rest.
        $this->defaultPaginationLength = $this->maxPaginationLength = max(50, count($this->tools), count($this->resources));
    }

    /**
     * The package's instructions, then the app's own (campaigns.mcp.instructions).
     *
     * @throws CampaignsException when together they are longer than a client keeps
     */
    public static function instructions(): string
    {
        $text = str_replace(':app', app(Campaigns::class)->name(), trim((string) file_get_contents(static::INSTRUCTIONS_PATH)));

        $addition = config('campaigns.mcp.instructions');

        if (is_string($addition) && trim($addition) !== '') {
            $text .= "\n\n".trim($addition);
        }

        if (mb_strlen($text) > static::INSTRUCTIONS_LIMIT) {
            throw CampaignsException::instructionsTooLong(mb_strlen($text), static::INSTRUCTIONS_LIMIT);
        }

        return $text;
    }
}
