<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Tests\Fixtures\Mcp\FixtureServer;
use Mozex\Campaigns\Tests\Fixtures\Mcp\FixtureWriteTool;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MemberDirectory;
use Symfony\Component\HttpFoundation\Response;

/**
 * A JSON-RPC call to the campaigns server, as an MCP client sends it. The
 * guards are forgotten first, since a request guard keeps the user it found
 * for as long as the test runs.
 *
 * @param  array<string, mixed>  $params
 * @return TestResponse<Response>
 */
function mcp(string $method, array $params = [], ?string $token = null): TestResponse
{
    app('auth')->forgetGuards();

    $headers = ['Accept' => 'application/json, text/event-stream'];

    if ($token !== null) {
        $headers['Authorization'] = "Bearer {$token}";
    }

    return test()->postJson('/mcp/campaigns', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], $headers);
}

/**
 * The names of the tools a principal is shown.
 *
 * @return list<string>
 */
function listedTools(?string $token): array
{
    $response = mcp('tools/list', token: $token)->assertOk();

    expect($response->json('result.tools'))->toBeArray()
        ->and($response->json('result.nextCursor'))->toBeNull();

    return array_column($response->json('result.tools'), 'name');
}

beforeEach(function (): void {
    config([
        'app.debug' => false,
        'campaigns.mcp.guards' => ['campaigns', 'people'],
        'auth.guards.people' => ['driver' => 'people'],
    ]);

    $this->app->bind(CampaignsServer::class, FixtureServer::class);

    // A stand-in for an app's OAuth guard: a person per bearer token.
    Auth::viaRequest('people', fn (Request $request): ?GenericUser => match ($request->bearerToken()) {
        'person-full' => new GenericUser(['id' => 1, 'rights' => 'full']),
        'person-read' => new GenericUser(['id' => 2, 'rights' => 'read']),
        'person-none' => new GenericUser(['id' => 3, 'rights' => null]),
        default => null,
    });

    Gate::define('campaigns.full', fn (GenericUser $user): bool => $user->rights === 'full');
    Gate::define('campaigns.read', fn (GenericUser $user): bool => $user->rights === 'read');
});

it('turns away a missing or unknown token with a 401 that tells the client how to sign in', function (): void {
    ApiToken::factory()->withToken('real-token')->create();

    foreach ([null, 'not-a-token'] as $token) {
        mcp('initialize', token: $token)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"')
            ->assertJsonPath('message', 'Unauthenticated. Send a campaigns token as a Bearer token in the Authorization header, or sign in.');
    }

    // A client that doesn't ask for JSON still gets the 401, never a
    // redirect to a login page.
    app('auth')->forgetGuards();

    $this->call('POST', '/mcp/campaigns', server: ['HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize"}')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('points a refused client at the OAuth metadata once the app serves it', function (): void {
    Mcp::oauthRoutes();
    app('router')->getRoutes()->refreshNameLookups();

    mcp('initialize')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/campaigns').'"');

    $this->getJson('/.well-known/oauth-protected-resource/mcp/campaigns')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp/campaigns'));
});

it('refuses everything to a person with neither ability', function (): void {
    foreach (['initialize', 'tools/list', 'resources/list'] as $method) {
        mcp($method, token: 'person-none')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account may not use the campaigns server.');
    }

    mcp('tools/call', ['name' => 'get_overview', 'arguments' => (object) []], 'person-none')->assertForbidden();
});

it('shows read-only principals only the read tools and refuses the rest', function (): void {
    ApiToken::factory()->readOnly()->withToken('read-token')->create();

    foreach (['read-token', 'person-read'] as $token) {
        expect(listedTools($token))->toBe(['get_overview', 'fixture_read']);

        mcp('tools/call', ['name' => 'fixture_write', 'arguments' => ['outcome' => 'done']], $token)
            ->assertBadRequest()
            ->assertJsonPath('error.message', 'Tool [fixture_write] not found.');

        mcp('tools/call', ['name' => 'fixture_read', 'arguments' => (object) []], $token)
            ->assertOk()
            ->assertJsonPath('result.structuredContent', ['read' => true]);

        expect(array_column(mcp('resources/list', token: $token)->assertOk()->json('result.resources'), 'uri'))->toBe(['campaigns://guide'])
            ->and(mcp('resources/read', ['uri' => 'campaigns://guide'], $token)->assertOk()->json('result.contents.0.text'))
            ->toBe(file_get_contents(dirname(__DIR__, 2).'/resources/mcp/guide.md'));
    }
});

it('serves the app\'s own resources to readers, their first page and the rest through a template', function (): void {
    ApiToken::factory()->readOnly()->withToken('read-token')->create();
    config(['campaigns.resources' => ['members' => MemberDirectory::class]]);

    foreach (range(1, 3) as $n) {
        Member::query()->create(['email' => "member{$n}@example.org", 'name' => "Member {$n}"]);
    }

    $resources = collect(mcp('resources/list', token: 'read-token')->assertOk()->json('result.resources'))->keyBy('uri');
    $templates = mcp('resources/templates/list', token: 'read-token')->assertOk()->json('result.resourceTemplates');
    $first = json_decode((string) mcp('resources/read', ['uri' => 'campaigns://resources/members'], 'read-token')->assertOk()->json('result.contents.0.text'), true);
    $second = json_decode((string) mcp('resources/read', ['uri' => 'campaigns://resources/members/pages/2'], 'read-token')->assertOk()->json('result.contents.0.text'), true);
    $unknown = json_decode((string) mcp('resources/read', ['uri' => 'campaigns://resources/nope/pages/1'], 'read-token')->assertOk()->json('result.contents.0.text'), true);

    expect($resources->keys()->all())->toBe(['campaigns://guide', 'campaigns://resources/members'])
        ->and($resources['campaigns://resources/members'])->toMatchArray(['name' => 'members', 'mimeType' => 'application/json'])
        ->and($resources['campaigns://resources/members']['description'])->toStartWith('The members, by name.')
        ->and(array_column($templates, 'uriTemplate'))->toBe(['campaigns://resources/{name}/pages/{page}'])
        ->and($first)->toMatchArray(['resource' => 'members', 'page' => 1, 'items' => [['name' => 'Member 1'], ['name' => 'Member 2']], 'next_page' => 2])
        ->and($second)->toMatchArray(['page' => 2, 'items' => [['name' => 'Member 3']], 'next_page' => null])
        ->and($unknown['error'])->toContain('nope');

    mcp('resources/list', token: 'person-none')->assertForbidden();
});

it('lets a full token and a person with full rights call every tool, sending switched off or not', function (): void {
    ApiToken::factory()->withToken('full-token')->create();

    // Only the actions that send refuse while sending is off; the tools add
    // no refusal of their own.
    config(['campaigns.enabled' => false]);

    foreach (['full-token', 'person-full'] as $token) {
        expect(listedTools($token))->toBe(['get_overview', 'fixture_read', 'fixture_write']);

        mcp('tools/call', ['name' => 'fixture_write', 'arguments' => ['outcome' => 'done']], $token)
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent', ['done' => true]);

        mcp('tools/call', ['name' => 'get_overview', 'arguments' => (object) []], $token)
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.app', 'Laravel');
    }
});

it('marks only the tools that need the person\'s approval, and declares a refusal in every output schema', function (): void {
    ApiToken::factory()->withToken('full-token')->create();

    $tools = collect(mcp('tools/list', token: 'full-token')->assertOk()->json('result.tools'))->keyBy('name');

    expect($tools['fixture_write']['_meta'] ?? null)->toBe([CampaignsTool::APPROVAL_META => true])
        ->and($tools['fixture_write']['annotations'])->toMatchArray(['destructiveHint' => true])
        ->and($tools['get_overview'])->not->toHaveKey('_meta')
        ->and($tools['get_overview']['annotations'])->toMatchArray(['readOnlyHint' => true, 'destructiveHint' => false]);

    foreach ($tools as $tool) {
        expect($tool['outputSchema']['properties']['error'] ?? null)->toMatchArray(['type' => 'string'])
            ->and($tool['outputSchema'])->not->toHaveKey('required');
    }
});

it('answers a refusal, invalid input and an answer past the limit as tool errors', function (): void {
    ApiToken::factory()->withToken('full-token')->create();
    Exceptions::fake();

    $call = fn (string $outcome): TestResponse => mcp('tools/call', ['name' => 'fixture_write', 'arguments' => ['outcome' => $outcome]], 'full-token')->assertOk();

    $call('refuse')
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The package said no.')
        ->assertJsonPath('result.structuredContent', ['error' => 'The package said no.']);

    $call('invalid')
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent', ['error' => 'The missing field is required.']);

    $call('huge')
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.error', 'The answer is too long to return. Ask for less at a time: a smaller page, or fewer items.');

    Exceptions::assertReported(fn (CampaignsException $exception): bool => str_contains($exception->getMessage(), 'over the limit of 50000'));

    // Anything else is the package's bug: reported, and never shown.
    $call('crash')
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'An internal server error occurred.');
});

it('refuses a tool called directly by a principal it is hidden from', function (): void {
    $this->actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns');

    expect(app(FixtureWriteTool::class)->handle()->getStructuredContent())->toBe(['error' => 'This needs full rights on the campaigns server.']);
});

it('finds a package token behind a guard that blanks the header it can\'t read', function (): void {
    ApiToken::factory()->withToken('full-token')->create();

    // As Passport's guard does with a bearer that isn't one of its tokens.
    Auth::viaRequest('blanking', function (Request $request): null {
        $request->headers->set('Authorization', '');

        return null;
    });

    config(['auth.guards.oauth' => ['driver' => 'blanking'], 'campaigns.mcp.guards' => 'oauth, campaigns']);

    mcp('tools/call', ['name' => 'fixture_read', 'arguments' => (object) []], 'full-token')
        ->assertOk()
        ->assertJsonPath('result.structuredContent', ['read' => true]);
});

it('writes a token\'s last use at most once a minute, and refuses it once revoked', function (): void {
    $this->freezeSecond();
    $token = ApiToken::factory()->withToken('laptop-token')->create(['name' => 'laptop']);

    mcp('initialize', token: 'laptop-token')->assertOk();
    expect($token->refresh()->last_used_at?->equalTo(now()))->toBeTrue();

    $this->travel(30)->seconds();
    mcp('tools/list', token: 'laptop-token')->assertOk();
    expect($token->refresh()->last_used_at?->equalTo(now()->subSeconds(30)))->toBeTrue();

    $this->travel(31)->seconds();
    mcp('tools/list', token: 'laptop-token')->assertOk();
    expect($token->refresh()->last_used_at?->equalTo(now()))->toBeTrue();

    $this->artisan('campaigns:token', ['action' => 'revoke', 'name' => 'laptop'])->assertSuccessful();

    mcp('tools/list', token: 'laptop-token')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});

it('sends the package\'s instructions with the app\'s own, and refuses a pair a client would cut', function (): void {
    ApiToken::factory()->withToken('full-token')->create();
    config(['app.name' => 'Acme', 'campaigns.mcp.instructions' => 'Only start campaigns from the office.']);

    $instructions = mcp('initialize', token: 'full-token')->assertOk()->json('result.instructions');

    expect($instructions)->toStartWith("This server runs Acme's email marketing")
        ->toEndWith("\n\nOnly start campaigns from the office.")
        ->and(mb_strlen($instructions))->toBeLessThanOrEqual(CampaignsServer::INSTRUCTIONS_LIMIT);

    config(['campaigns.mcp.instructions' => str_repeat('a', CampaignsServer::INSTRUCTIONS_LIMIT)]);

    expect(fn (): string => CampaignsServer::instructions())
        ->toThrow(CampaignsException::class, 'Shorten campaigns.mcp.instructions');
});

it('answers through routes cached the way route:cache stores them', function (): void {
    ApiToken::factory()->withToken('full-token')->create();
    $router = app('router');

    foreach ($router->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    $router->setCompiledRoutes($router->getRoutes()->compile());

    expect($router->has('campaigns.mcp'))->toBeTrue();

    mcp('tools/call', ['name' => 'fixture_read', 'arguments' => (object) []], 'full-token')
        ->assertOk()
        ->assertJsonPath('result.structuredContent', ['read' => true]);

    mcp('tools/list')->assertUnauthorized();
});

it('serves at the configured path behind the app\'s middleware, at the default for an empty one, and nowhere when off', function (): void {
    ApiToken::factory()->withToken('full-token')->create();
    $router = app('router');

    $load = function (array $config) use ($router): void {
        config($config);
        $router->setRoutes(new RouteCollection);
        require dirname(__DIR__, 2).'/routes/mcp.php';
        $router->getRoutes()->refreshNameLookups();
    };

    $load(['campaigns.mcp.path' => 'agents/news', 'campaigns.mcp.middleware' => ['throttle:1,1']]);

    $call = fn (): TestResponse => test()->postJson('/agents/news', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer full-token', 'Accept' => 'application/json, text/event-stream']);

    $call()->assertOk();
    app('auth')->forgetGuards();
    $call()->assertTooManyRequests();

    $load(['campaigns.mcp.path' => ' ']);
    expect($router->getRoutes()->getByName('campaigns.mcp')?->uri())->toBe('mcp/campaigns');

    $load(['campaigns.mcp.enabled' => false]);
    expect($router->has('campaigns.mcp'))->toBeFalse();
});
