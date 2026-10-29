<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mozex\Campaigns\Actions\CreateUploadAction;
use Mozex\Campaigns\Actions\ReadEmailUploadAction;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Http\Controllers\AgentFileController;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Support\Links;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Storage::fake('local');
    config(['campaigns.url' => 'https://portal.example.com']);
});

/**
 * A request to a signed link as curl sends it, on another host than the
 * link was made for (an app whose main domain moved): the signature covers
 * the path and the query only.
 *
 * @param  array<string, string>  $server
 * @return TestResponse<Response>
 */
function sendTo(string $link, string $method = 'GET', ?string $body = null, array $server = []): TestResponse
{
    expect($link)->toStartWith('https://portal.example.com/mcp/campaigns/');

    return test()->call($method, 'http://localhost'.substr($link, strlen('https://portal.example.com')), [], [], [], $server, $body);
}

/**
 * A template whose revision has an English version with written text and a
 * French one whose text was generated.
 */
function downloadableRevision(): Revision
{
    $template = Template::factory()->create(['key' => 'news']);

    return Revision::factory()->of($template)
        ->withContent('en', ['subject' => 'News', 'html' => '<p>Hi</p>{{footer}}', 'text' => "Hi\n{{footer}}"])
        ->withContent('fr', ['subject' => 'Nouvelles', 'html' => '<p>Salut</p>{{footer}}', 'text' => "Salut\n\n{{footer}}", 'text_generated' => true])
        ->create();
}

/**
 * @return array<string, string>
 */
function unzipped(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'campaigns-test-unzip-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $files[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
    }

    $zip->close();
    unlink($path);

    return $files;
}

describe('the upload link', function (): void {
    it('takes a file once, on whatever host the request names', function (string $driver): void {
        useDatabase($driver);
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Contacts);
        $link = Links::upload($upload);

        $received = sendTo($link, 'PUT', "email\nann@example.com\n", ['CONTENT_TYPE' => 'text/csv']);
        $again = sendTo($link, 'PUT', "email\nbob@example.com\n");

        expect($link)->toStartWith("https://portal.example.com/mcp/campaigns/uploads/{$upload->uuid}?expires=")
            ->and($received->status())->toBe(201)
            ->and($received->json())->toMatchArray(['upload_id' => $upload->uuid, 'size' => 22])
            ->and($received->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
            ->and($upload->refresh()->received_at)->not->toBeNull()
            ->and($upload->mime)->toBe('text/csv')
            ->and($upload->contents())->toBe("email\nann@example.com\n")
            ->and($again->status())->toBe(409)
            ->and($again->json('error'))->toContain('a file already arrived through it');
    })->with('databases');

    it('refuses a link whose signature was changed, taken off or moved to another upload', function (): void {
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        $other = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        $link = Links::upload($upload);

        $changed = sendTo((string) preg_replace('/signature=.{4}/', 'signature=0000', $link), 'PUT', '<p>Hi</p>');
        $unsigned = sendTo((string) preg_replace('/&signature=.*/', '', $link), 'PUT', '<p>Hi</p>');
        $moved = sendTo(str_replace($upload->uuid, $other->uuid, $link), 'PUT', '<p>Hi</p>');

        expect([$changed->status(), $unsigned->status(), $moved->status()])->toBe([403, 403, 403])
            ->and($changed->json('error'))->toBe('This link isn\'t valid. Ask for a new one.')
            ->and(Upload::query()->whereNotNull('received_at')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('refuses a link past its minutes', function (): void {
        $this->freezeSecond();
        $link = Links::upload(app(CreateUploadAction::class)->execute(UploadPurpose::Image));

        $this->travel(Upload::LINK_MINUTES)->minutes();
        $this->travel(1)->second();

        $response = sendTo($link, 'PUT', testImage());

        expect($response->status())->toBe(410)
            ->and($response->json('error'))->toBe('This link has expired. Ask for a new one.');
    });

    it('refuses a file over its purpose\'s limit, declared or counted, and still takes a smaller one', function (): void {
        config(['campaigns.uploads.max_size' => 10, 'campaigns.assets.max_size' => 4]);
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Contacts);
        $link = Links::upload($upload);
        $image = Links::upload(app(CreateUploadAction::class)->execute(UploadPurpose::Image));

        $counted = sendTo($link, 'PUT', str_repeat('a', 11));
        $declared = sendTo($link, 'PUT', 'email', ['CONTENT_LENGTH' => '11']);
        $empty = sendTo($link, 'PUT', '');
        $taken = sendTo($link, 'PUT', 'email');
        $largeImage = sendTo($image, 'PUT', 'abcde');

        expect([$counted->status(), $declared->status(), $empty->status(), $taken->status(), $largeImage->status()])->toBe([413, 413, 400, 201, 413])
            ->and($counted->json('error'))->toContain('over the upload limit')
            ->and($upload->refresh()->size)->toBe(5);
    });

    it('answers 404 for an upload that is gone', function (): void {
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        $link = Links::upload($upload);
        $upload->delete();

        $response = sendTo($link, 'PUT', '<p>Hi</p>');

        expect($response->status())->toBe(404)
            ->and($response->json('error'))->toBe('There is no upload with that id.');
    });

    it('throttles one link, never the others', function (): void {
        $link = Links::upload(app(CreateUploadAction::class)->execute(UploadPurpose::Email));
        $statuses = [];

        foreach (range(1, AgentFileController::PER_MINUTE + 1) as $attempt) {
            $statuses[] = sendTo($link, 'PUT', '<p>Hi</p>')->status();
        }

        $other = sendTo(Links::upload(app(CreateUploadAction::class)->execute(UploadPurpose::Email)), 'PUT', '<p>Hi</p>');

        expect($statuses[0])->toBe(201)
            ->and(array_unique(array_slice($statuses, 1, -1)))->toBe([409])
            ->and(end($statuses))->toBe(429)
            ->and($other->status())->toBe(201);
    });
});

describe('the download link', function (): void {
    it('downloads a revision as a zip an upload takes back unchanged, and its default language as HTML', function (string $driver): void {
        useDatabase($driver);
        $revision = downloadableRevision();
        $expires = Links::downloadsExpireAt();

        $zip = sendTo(Links::download($revision, 'email.zip', $expires));
        $html = sendTo(Links::download($revision, 'email.html', $expires));
        $files = unzipped((string) $zip->getContent());
        $uploaded = app(ReadEmailUploadAction::class)->read((string) $zip->getContent(), ['en' => 'News', 'fr' => 'Nouvelles']);

        expect($zip->status())->toBe(200)
            ->and($zip->headers->get('Content-Type'))->toBe('application/zip')
            ->and($zip->headers->get('Content-Disposition'))->toBe('attachment; filename="email.zip"')
            ->and($zip->headers->get('Cache-Control'))->toContain('no-store')
            ->and($files)->toBe([
                'email.html' => '<p>Hi</p>{{footer}}',
                'email.txt' => "Hi\n{{footer}}",
                'translations/fr.html' => '<p>Salut</p>{{footer}}',
            ])
            ->and(array_map(fn (EmailContent $content): array => [$content->locale, $content->subject, $content->html, $content->text], $uploaded->contents))
            ->toBe(array_map(fn (EmailContent $content): array => [$content->locale, $content->subject, $content->html, $content->text], $revision->contentsToCopy()))
            ->and($html->status())->toBe(200)
            ->and($html->getContent())->toBe('<p>Hi</p>{{footer}}')
            ->and($html->headers->get('Content-Disposition'))->toBe('attachment; filename="email.html"')
            ->and($html->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($html->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox");
    })->with('databases');

    it('refuses a download link that was changed, is past its hour, or names an email that is gone', function (): void {
        $this->freezeSecond();
        $revision = downloadableRevision();
        $link = Links::download($revision, 'email.zip', Links::downloadsExpireAt());
        $gone = Links::download(Revision::factory()->of(Template::factory()->create())->withContent('en')->create(), 'email.zip', Links::downloadsExpireAt());
        Revision::query()->whereKeyNot($revision->id)->delete();

        $changed = sendTo(str_replace('email.zip', 'email.html', $link));
        $missing = sendTo($gone);
        $this->travel(Links::DOWNLOAD_MINUTES * 60 + 1)->seconds();
        $expired = sendTo($link);

        expect([$changed->status(), $missing->status(), $expired->status()])->toBe([403, 404, 410])
            ->and($missing->json('error'))->toBe('There is no such email to download.');
    });
});

it('serves both links through routes cached the way route:cache stores them', function (): void {
    $router = app('router');

    foreach ($router->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    $router->setCompiledRoutes($router->getRoutes()->compile());

    $upload = sendTo(Links::upload(app(CreateUploadAction::class)->execute(UploadPurpose::Email)), 'PUT', '<p>Hi</p>');
    $download = sendTo(Links::download(downloadableRevision(), 'email.html', Links::downloadsExpireAt()));

    expect($upload->status())->toBe(201)
        ->and($download->status())->toBe(200);
});
