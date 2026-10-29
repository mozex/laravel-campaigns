<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Tools\ArchiveListTool;
use Mozex\Campaigns\Mcp\Tools\ArchiveTemplateTool;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Mcp\Tools\ResumeCampaignTool;
use Mozex\Campaigns\Mcp\Tools\RunContactsImportTool;
use Mozex\Campaigns\Mcp\Tools\SendTestTool;
use Mozex\Campaigns\Mcp\Tools\SpeedUpCampaignTool;
use Mozex\Campaigns\Mcp\Tools\StartCampaignTool;
use Mozex\Campaigns\Mcp\Tools\UnblockContactTool;

/**
 * Every tool class in src/Mcp/Tools, found on disk.
 *
 * @return list<class-string<CampaignsTool>>
 */
function toolClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Mcp/Tools/*Tool.php') ?: [] as $file) {
        $class = 'Mozex\\Campaigns\\Mcp\\Tools\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            /** @var class-string<CampaignsTool> $class */
            $classes[] = $class;
        }
    }

    return $classes;
}

it('lists every tool the package has on its server', function (): void {
    $listed = (fn (): array => $this->tools)->call(app(CampaignsServer::class, ['transport' => new FakeTransporter]));

    foreach (toolClasses() as $class) {
        expect(in_array($class, $listed, true))->toBeTrue("{$class} isn't listed in CampaignsServer::\$tools.");
    }

    expect(count($listed))->toBe(count(toolClasses()));
});

it('lets every tool\'s output schema carry a refusal', function (): void {
    foreach (toolClasses() as $class) {
        $schema = app($class)->toArray()['outputSchema'] ?? [];

        expect($schema['properties']['error']['type'] ?? null)->toBe('string', "{$class} declares no error field.")
            ->and(array_key_exists('required', $schema))->toBeFalse("{$class} requires a field a refusal doesn't carry.");
    }
});

it('has every tool say whether it only reads or may destroy', function (): void {
    foreach (toolClasses() as $class) {
        $attributes = array_map(fn (ReflectionAttribute $attribute): string => $attribute->getName(), (new ReflectionClass($class))->getAttributes());

        expect(in_array(IsReadOnly::class, $attributes, true) || in_array(IsDestructive::class, $attributes, true))
            ->toBeTrue("{$class} declares neither #[IsReadOnly] nor #[IsDestructive].");
    }
});

it('lets each principal reach exactly the tools its rights allow', function (Rights $rights, bool $person): void {
    foreach (toolClasses() as $class) {
        $response = agent($rights, $person)->tool($class, []);

        if ($rights->allows(app($class)->rights())) {
            $errors = (fn (): array => $this->errors())->call($response);

            expect(array_filter($errors, fn (string $error): bool => str_contains($error, 'not found')))->toBe([], "{$class} is hidden from a {$rights->value} principal.");
        } else {
            $response->assertNotRegistered();
        }
    }
})->with([
    'a read token' => [Rights::Read, false],
    'a full token' => [Rights::Full, false],
    'a person who may read' => [Rights::Read, true],
    'a person who may run campaigns' => [Rights::Full, true],
]);

it('asks the person before exactly the risky calls', function (): void {
    $asking = array_values(array_filter(toolClasses(), fn (string $class): bool => app($class)->requiresApproval()));
    sort($asking);

    expect($asking)->toBe([
        ArchiveListTool::class,
        ArchiveTemplateTool::class,
        ResumeCampaignTool::class,
        RunContactsImportTool::class,
        SendTestTool::class,
        SpeedUpCampaignTool::class,
        StartCampaignTool::class,
        UnblockContactTool::class,
    ])
        ->and(app(ArchiveListTool::class)->toArray()['_meta'] ?? null)->toBe([CampaignsTool::APPROVAL_META => true]);
});
