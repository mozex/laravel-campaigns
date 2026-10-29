<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Illuminate\Console\Command;
use Mozex\Campaigns\Actions\CreateApiTokenAction;
use Mozex\Campaigns\Actions\RevokeApiTokenAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Support\Links;

/**
 * Tokens for the MCP server, made, listed and revoked on the server itself,
 * so a token never travels through a chat: it is printed once, here.
 */
class TokenCommand extends Command
{
    protected $signature = 'campaigns:token
        {action : create, list or revoke}
        {name? : The token\'s name, one per machine (create and revoke)}
        {--read-only : Make a token that can only read (create)}';

    protected $description = 'Create, list or revoke the tokens agents sign in to the MCP server with';

    public function handle(CreateApiTokenAction $create, RevokeApiTokenAction $revoke): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create($create),
            'list' => $this->list(),
            'revoke' => $this->revoke($revoke),
            default => $this->refuse('The action is create, list or revoke.'),
        };
    }

    protected function create(CreateApiTokenAction $create): int
    {
        $name = $this->name();

        if ($name === null) {
            return $this->refuse('Give the token a name, such as the machine it will live on.');
        }

        $rights = $this->option('read-only') ? Rights::Read : Rights::Full;

        try {
            [, $token] = $create->execute($name, $rights);
        } catch (CampaignsException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $this->info("Created the token \"{$name}\" with {$rights->value} rights. It is shown only this once:");
        $this->line($token);

        $server = Links::mcpServer();

        $this->line($server === null
            ? 'The MCP server is switched off (campaigns.mcp.enabled).'
            : "Send it to {$server} as \"Authorization: Bearer <token>\".");

        return self::SUCCESS;
    }

    protected function list(): int
    {
        $tokens = ApiToken::query()->orderBy('name')->get();

        if ($tokens->isEmpty()) {
            $this->info('There are no tokens.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Rights', 'Last used', 'Created'], $tokens->map(fn (ApiToken $token): array => [
            $token->name,
            $token->rights->value,
            $token->last_used_at?->toDateTimeString() ?? 'never',
            $token->created_at?->toDateTimeString() ?? '',
        ])->all());

        return self::SUCCESS;
    }

    protected function revoke(RevokeApiTokenAction $revoke): int
    {
        $name = $this->name();

        if ($name === null) {
            return $this->refuse('Name the token to revoke.');
        }

        if (! $revoke->execute($name)) {
            return $this->refuse("No token is named \"{$name}\".");
        }

        $this->info("Revoked the token \"{$name}\".");

        return self::SUCCESS;
    }

    protected function name(): ?string
    {
        $name = trim((string) $this->argument('name'));

        return $name === '' ? null : $name;
    }

    protected function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
