<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;

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
