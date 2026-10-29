<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mozex\Campaigns\Actions\CreatePreviewLinkAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Http\Controllers\PreviewController;
use Mozex\Campaigns\Jobs\SendTestJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\MessageComposer;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\PreviewToken;
use Mozex\Campaigns\Support\Token;

/**
 * Every row a preview could touch, to prove opening one changed nothing.
 *
 * @return array<string, list<mixed>>
 */
function previewRows(): array
{
    $rows = [];

    foreach (['campaigns', 'campaign_templates', 'campaign_revisions', 'campaign_test_sends', 'campaign_subscribers'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

/**
 * The page's own elements, with the email's HTML left inside the frames'
 * srcdoc attributes, as a browser reads them.
 */
function previewDocument(string $html): DOMDocument
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    return $document;
}

/**
 * A link's address as the test client takes it: its path and query.
 */
function previewPath(string $link): string
{
    return (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
}

it('shows a template\'s email in two sandboxed frames with made-up details, its text and checks, and changes nothing', function (): void {
    config(['campaigns.url' => 'https://news.example.com', 'campaigns.mail.from' => ['address' => 'team@news.example.com', 'name' => 'The Team'], 'campaigns.mail.reply_to' => 'help@example.com']);
    $template = Template::factory()->create(['key' => 'march', 'name' => 'March news']);
    $revision = Revision::factory()->of($template)
        ->withContent('en', [
            'subject' => 'Hi {{first_name}}',
            'preheader' => 'Spring is here',
            'html' => '<html><head><title>T</title></head><body><p>Hello {{first_name}}</p><img src="https://cdn.example.com/a.png" alt="A">"></iframe><script>alert(1)</script>{{footer}}</body></html>',
            'text' => 'Hello {{name}} {{footer}}',
        ])
        ->create([
            'check_results' => ['status' => 'done', 'run' => 'r', 'passed' => 5, 'failed' => 1, 'checks' => [
                ['check' => 'images', 'locale' => 'en', 'status' => 'failed', 'message' => 'An image has no alt text.', 'items' => [['url' => 'https://cdn.example.com/b.png']]],
                ['check' => 'size', 'locale' => 'en', 'status' => 'passed', 'message' => 'Small enough.'],
            ]],
        ]);

    $link = app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt());
    $before = previewRows();

    // Signed without the host, so another host the app answers on works.
    $page = $this->get('https://other.example.org'.previewPath($link));

    $page->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertSee('March news')
        ->assertSee('The Team &lt;team@news.example.com&gt;', false)
        ->assertSee('help@example.com')
        ->assertSee('Hi Alexandra')
        ->assertSee('Spring is here')
        ->assertSee('Hello Alexandra Example')
        ->assertSee('5 passed, 1 failed.')
        ->assertSee('An image has no alt text.')
        ->assertDontSee('Small enough.');

    $policy = (string) $page->headers->get('Content-Security-Policy');
    $document = previewDocument((string) $page->getContent());
    $frames = $document->getElementsByTagName('iframe');
    $token = PreviewToken::for($revision, 'en');
    $test = TestSend::query()->where('token_hash', Token::hash($token))->firstOrFail();

    expect($policy)->toContain("default-src 'none'")
        ->and($policy)->toContain("script-src 'none'")
        ->and($policy)->toContain("base-uri 'none'")
        ->and($policy)->toContain("form-action 'none'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain('sandbox allow-popups allow-popups-to-escape-sandbox')
        ->and($policy)->toContain('img-src https: data:')
        ->and($policy)->not->toContain('allow-scripts')
        ->and($policy)->not->toContain('allow-same-origin')
        ->and(str_contains((string) $page->headers->get('Cache-Control'), 'no-store'))->toBeTrue()
        // The upload's markup stayed inside the attribute: the page has no
        // script of its own, and each frame holds the email whole.
        ->and($document->getElementsByTagName('script')->length)->toBe(0)
        ->and($frames->length)->toBe(2)
        ->and(array_map(fn (DOMElement $frame): string => $frame->getAttribute('width'), iterator_to_array($frames)))->toBe(['600', '375'])
        ->and(array_map(fn (DOMElement $frame): string => $frame->getAttribute('sandbox'), iterator_to_array($frames)))->toBe([PreviewController::FRAME_SANDBOX, PreviewController::FRAME_SANDBOX])
        ->and(PreviewController::FRAME_SANDBOX)->not->toContain('allow-scripts')
        ->and(PreviewController::FRAME_SANDBOX)->not->toContain('allow-same-origin')
        ->and($frames->item(0)?->getAttribute('srcdoc'))->toContain('<head><base target="_blank"><title>T</title>')
        ->and($frames->item(0)?->getAttribute('srcdoc'))->toContain('"></iframe><script>alert(1)</script>')
        ->and($frames->item(0)?->getAttribute('srcdoc'))->toContain('Hello Alexandra</p>')
        // The links carry the preview's token, a test row that was never sent.
        ->and($frames->item(0)?->getAttribute('srcdoc'))->toContain(Links::unsubscribe($token))
        ->and($test->only(['campaign_id', 'revision_id', 'email', 'locale', 'sent_at']))->toBe([
            'campaign_id' => null, 'revision_id' => $revision->id, 'email' => Personalization::SAMPLE_EMAIL, 'locale' => 'en', 'sent_at' => null,
        ])
        ->and(previewRows())->toBe($before);

    // The unsubscribe page opens as a test email's does.
    $this->get(Links::unsubscribe($token))->assertOk()->assertSee(__('campaigns::pages.test'));
});

it('shows a campaign\'s own sender, a tab per language, and its frozen copy\'s source', function (): void {
    config(['campaigns.url' => 'https://news.example.com']);
    $campaign = Campaign::factory()->create(['name' => 'Spring sale', 'from_name' => 'Sales', 'from_email' => 'sales@news.example.com', 'reply_to' => null]);
    $source = Revision::factory()->of($campaign)->withContent('en', ['html' => '<p>Hello</p>{{footer}}'])->create();
    $revision = Revision::factory()->of($campaign)
        ->withContent('en', ['html' => '<p>Hello</p>{{footer}}'])
        ->withContent('fr', ['subject' => 'Bonjour', 'html' => '<p>Bonjour</p>{{footer}}'])
        ->create(['frozen_from_id' => $source->id]);

    $link = previewPath(app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt()));
    $page = $this->get($link)->assertOk()
        ->assertSee('Sales &lt;sales@news.example.com&gt;', false)
        ->assertSee('the copy sent, made from revision '.$source->number)
        ->assertSee('Not run yet.');

    $tabs = collect(iterator_to_array(previewDocument((string) $page->getContent())->getElementsByTagName('a')))
        ->mapWithKeys(fn (DOMElement $tab): array => [trim($tab->textContent) => $tab->getAttribute('href')]);
    $french = $this->get(parse_url($link, PHP_URL_PATH).$tabs['fr'])->assertOk()->assertSee('Bonjour');

    expect($tabs->keys()->all())->toBe(['en', 'fr'])
        ->and($tabs['fr'])->toContain('signature=')
        ->and(previewDocument((string) $french->getContent())->getElementsByTagName('iframe')->item(0)?->getAttribute('srcdoc'))
        ->toContain(Links::unsubscribe(PreviewToken::for($revision, 'fr')))
        ->and(TestSend::query()->where('revision_id', $revision->id)->pluck('locale')->sort()->values()->all())->toBe(['en', 'fr']);
});

it('refuses a changed, moved or expired link, and a revision that is gone', function (): void {
    $template = Template::factory()->create();
    $revision = Revision::factory()->of($template)->withContent('en')->create();
    $other = Revision::factory()->of($template)->withContent('en')->create();
    $link = previewPath(app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt()));

    $this->get($link)->assertOk();
    $this->get($link.'0')->assertForbidden();
    $this->get(str_replace($revision->uuid, $other->uuid, $link))->assertForbidden();
    $this->get(str_replace('expires=', 'expires=1', $link))->assertForbidden();

    $this->travel(Links::PREVIEW_DAYS)->days();
    $this->travel(1)->minutes();
    $this->get($link)->assertStatus(410)->assertSee('This preview has expired');
    $this->travelBack();

    $revision->delete();
    $this->get($link)->assertNotFound();
});

it('makes one never-sent row per language, reused by every later link', function (): void {
    config(['campaigns.provider' => 'fake', 'campaigns.enabled' => true, 'campaigns.test_addresses' => [Personalization::SAMPLE_EMAIL]]);
    $revision = Revision::factory()->of(Template::factory()->create())
        ->withContent('en', ['html' => '<p>Hi {{first_name}}</p>{{footer}}'])
        ->withContent('fr')
        ->create();

    app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt());
    app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt());
    $rows = TestSend::query()->where('revision_id', $revision->id)->get();

    // A test email's job claims only a row whose token isn't made yet.
    app()->call([new SendTestJob($rows->firstOrFail()->id), 'handle']);

    expect($rows)->toHaveCount(2)
        ->and(app(CampaignProvider::class)->sent())->toBe([])
        ->and($rows->firstOrFail()->fresh()?->only(['sent_at', 'failed_at']))->toBe(['sent_at' => null, 'failed_at' => null]);

    // Its web version shows the preview's made-up details, as the preview did.
    $english = TestSend::query()->where('revision_id', $revision->id)->where('locale', 'en')->firstOrFail();
    $rendered = app(MessageComposer::class)->rendered($english, PreviewToken::for($revision, 'en'));

    expect($rendered->html)->toContain('Hi Alexandra');
});

it('puts the frames\' base inside the head, else after the html tag or the doctype', function (string $html, string $framed): void {
    $revision = Revision::factory()->of(Template::factory()->create())->withContent('en', ['html' => $html.'{{footer}}'])->create();
    $link = app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt());

    $page = $this->get(previewPath($link))->assertOk();

    expect(previewDocument((string) $page->getContent())->getElementsByTagName('iframe')->item(0)?->getAttribute('srcdoc'))->toStartWith($framed);
})->with([
    'a head' => ['<!DOCTYPE html><html><head><title>T</title></head><body>Hi', '<!DOCTYPE html><html><head><base target="_blank"><title>T</title>'],
    'no head' => ['<!DOCTYPE html><html lang="en"><body>Hi', '<!DOCTYPE html><html lang="en"><base target="_blank"><body>Hi'],
    'a doctype only' => ["\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\"><table><tr><td>Hi", "\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\"><base target=\"_blank\"><table>"],
    'a fragment' => ['<p>Hi</p>', '<base target="_blank"><p>Hi</p>'],
]);

it('keeps the links of a preview made before the app key changed', function (): void {
    $old = 'base64:'.base64_encode(str_repeat('o', 32));
    config(['app.key' => $old]);
    $revision = Revision::factory()->of(Template::factory()->create())->withContent('en', ['html' => '<p>Hi</p>{{footer}}'])->create();
    $link = previewPath(app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt()));
    $token = PreviewToken::for($revision, 'en');

    config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.previous_keys' => [$old]]);
    $page = $this->get($link)->assertOk();

    expect(previewDocument((string) $page->getContent())->getElementsByTagName('iframe')->item(0)?->getAttribute('srcdoc'))->toContain(Links::unsubscribe($token))
        ->and(PreviewToken::for($revision, 'en'))->not->toBe($token);

    $this->get(Links::unsubscribe($token))->assertOk()->assertSee(__('campaigns::pages.test'));
});

it('refuses to make a preview link without an app key', function (): void {
    config(['app.key' => '']);
    $revision = Revision::factory()->of(Template::factory()->create())->withContent('en')->create();

    expect(fn (): string => app(CreatePreviewLinkAction::class)->execute($revision, Links::previewExpiresAt()))
        ->toThrow(CampaignsException::class, 'The app has no key');
});
