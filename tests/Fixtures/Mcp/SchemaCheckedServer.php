<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures\Mcp;

use Illuminate\Container\Container;
use Laravel\Mcp\Server\Primitive;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * The campaigns server under test, checking every tool's answer against the
 * tool's own output schema, as a client does: Claude Code refuses an answer
 * that doesn't match. Both are checked as the JSON that goes over the wire,
 * so an empty map and an empty list are typed as the client reads them.
 */
class SchemaCheckedServer extends PendingTestResponse
{
    /**
     * The tools that gave an answer other than a refusal in this test, each
     * checked against its schema.
     *
     * @var array<class-string<Tool>, true>
     */
    public static array $answered = [];

    /**
     * The paths each tool's answers gave a value other than null, in this
     * test.
     *
     * @var array<class-string<Tool>, array<string, true>>
     */
    public static array $filled = [];

    public function __construct()
    {
        parent::__construct(Container::getInstance(), CampaignsServer::class);
    }

    /**
     * @param  class-string<Tool>|Tool  $tool
     * @param  array<string, mixed>  $arguments
     */
    public function tool(Tool|string $tool, array $arguments = []): TestResponse
    {
        $response = parent::tool($tool, $arguments);
        $tool = (fn (): Primitive => $this->primitive)->call($response);
        $answer = (fn (): JsonRpcResponse => $this->response)->call($response);

        // An answer that can't be encoded goes out empty, which no client
        // reads as an answer.
        $wire = json_decode(json_encode($answer->toArray(), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $content = $wire->result->structuredContent ?? null;

        if (! $tool instanceof Tool) {
            return $response;
        }

        // A tool that isn't listed for this principal, or an error
        // laravel/mcp answers itself, carries no structured content; a
        // client refuses any other answer of a tool with an output schema
        // that carries none.
        if ($content === null) {
            Assert::assertTrue($wire->result->isError ?? true, $tool::class.' answered without structured content.');

            return $response;
        }

        $schema = json_decode(json_encode($tool->toArray()['outputSchema'] ?? [], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $result = (new Validator)->setMaxErrors(10)->validate($content, $schema);

        if (! $result->isValid()) {
            Assert::fail(sprintf(
                "%s answered outside its output schema:\n%s\nThe answer: %s",
                $tool::class,
                json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                mb_strimwidth((string) json_encode($content, JSON_UNESCAPED_SLASHES), 0, 2000, '...'),
            ));
        }

        if (! isset($content->error)) {
            static::$answered[$tool::class] = true;
            static::fill($tool::class, $content, '');
        }

        return $response;
    }

    /**
     * What a tool's answer may leave without a value to check, as paths (a
     * list's items as "[]"): a field that may be null, and a list's items,
     * which an empty list leaves unchecked.
     *
     * @param  class-string<Tool>  $tool
     * @return list<string>
     */
    public static function optionalPaths(string $tool): array
    {
        $paths = [];

        $walk = function (array $node, string $path) use (&$walk, &$paths): void {
            if ($path !== '' && in_array('null', (array) ($node['type'] ?? []), true)) {
                $paths[] = $path;
            }

            foreach ($node['properties'] ?? [] as $key => $child) {
                $walk((array) $child, "{$path}.{$key}");
            }

            if (is_array($node['items'] ?? null)) {
                $paths[] = "{$path}[]";
                $walk($node['items'], "{$path}[]");
            }
        };

        /** @var Tool $instance */
        $instance = Container::getInstance()->make($tool);
        $walk($instance->toArray()['outputSchema'] ?? [], '');

        return $paths;
    }

    /**
     * Records the paths an answer gave a value other than null.
     */
    protected static function fill(string $tool, mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }

        static::$filled[$tool][$path] = true;

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $child) {
                static::fill($tool, $child, "{$path}.{$key}");
            }
        } elseif (is_array($value)) {
            foreach ($value as $child) {
                static::fill($tool, $child, "{$path}[]");
            }
        }
    }
}
