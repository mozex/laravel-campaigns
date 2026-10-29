<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Support\Token;

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function tokenCommand(array $arguments): array
{
    $status = Artisan::call('campaigns:token', $arguments);

    // Windows consoles end lines with \r\n.
    return [$status, str_replace("\r\n", "\n", Artisan::output())];
}

it('creates a token, shows it once and stores only its hash', function (): void {
    config(['campaigns.url' => 'https://news.example.com']);

    [$status, $output] = tokenCommand(['action' => 'create', 'name' => ' laptop ']);
    [$readStatus] = tokenCommand(['action' => 'create', 'name' => 'ci', '--read-only' => true]);

    preg_match('/^([A-Za-z0-9]{48})$/m', $output, $match);
    $token = ApiToken::query()->where('name', 'laptop')->sole();

    expect($status)->toBe(0)
        ->and($readStatus)->toBe(0)
        ->and($output)->toContain('Created the token "laptop" with full rights. It is shown only this once:')
        ->toContain('Send it to https://news.example.com/mcp/campaigns as "Authorization: Bearer <token>".')
        ->and($match)->toHaveCount(2)
        ->and($token->token_hash)->toBe(Token::hash($match[1]))
        ->and($token->rights)->toBe(Rights::Full)
        ->and(ApiToken::query()->where('name', 'ci')->sole()->rights)->toBe(Rights::Read)
        ->and(ApiToken::findByToken($match[1])?->is($token))->toBeTrue();
});

it('lists the tokens without their secrets and revokes one by name', function (): void {
    $this->freezeSecond();
    ApiToken::factory()->create(['name' => 'laptop', 'last_used_at' => now()]);
    ApiToken::factory()->readOnly()->create(['name' => 'ci']);

    [, $list] = tokenCommand(['action' => 'list']);
    [$revoked, $revokeOutput] = tokenCommand(['action' => 'revoke', 'name' => 'laptop']);

    expect($list)->toContain('laptop', 'full', now()->toDateTimeString(), 'ci', 'read', 'never')
        ->and($revoked)->toBe(0)
        ->and($revokeOutput)->toContain('Revoked the token "laptop".')
        ->and(ApiToken::query()->pluck('name')->all())->toBe(['ci']);

    // The machine's next token takes the name again.
    expect(tokenCommand(['action' => 'create', 'name' => 'laptop'])[0])->toBe(0);
});

it('refuses a name taken, a token that doesn\'t exist, a missing name and an unknown action', function (): void {
    ApiToken::factory()->create(['name' => 'laptop']);

    expect(tokenCommand(['action' => 'create', 'name' => 'laptop']))->toBe([1, "A token named \"laptop\" already exists. Revoke it first, or choose another name.\n"])
        ->and(tokenCommand(['action' => 'revoke', 'name' => 'desktop']))->toBe([1, "No token is named \"desktop\".\n"])
        ->and(tokenCommand(['action' => 'create']))->toBe([1, "Give the token a name, such as the machine it will live on.\n"])
        ->and(tokenCommand(['action' => 'revoke', 'name' => ' ']))->toBe([1, "Name the token to revoke.\n"])
        ->and(tokenCommand(['action' => 'rotate']))->toBe([1, "The action is create, list or revoke.\n"])
        ->and(ApiToken::query()->count())->toBe(1);
});

it('says so when the MCP server is switched off', function (): void {
    // As routes/mcp.php leaves them while campaigns.mcp.enabled is off.
    app('router')->setRoutes(new RouteCollection);

    expect(tokenCommand(['action' => 'create', 'name' => 'laptop'])[1])->toContain('The MCP server is switched off (campaigns.mcp.enabled).');
});
