<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\AddUploadedRevisionAction;
use Mozex\Campaigns\Actions\ClaimUploadAction;
use Mozex\Campaigns\Actions\CreateUploadAction;
use Mozex\Campaigns\Actions\ReadEmailUploadAction;
use Mozex\Campaigns\Actions\ReceiveUploadAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\Upload;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
});

/**
 * The id of an upload link a file has arrived through.
 */
function uploaded(string $bytes): string
{
    $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);

    return app(ReceiveUploadAction::class)->execute($upload->uuid, $bytes)->uuid;
}

/**
 * The address the asset library gives an image.
 */
function assetUrl(string $bytes, string $extension): string
{
    return 'https://cdn.example.com/storage/campaigns/assets/'.hash('sha256', $bytes).".{$extension}";
}

function newsletterZip(): string
{
    return zipOf([
        'email.html' => <<<'HTML'
            <html><head><title>  March
              news </title><style>.hero { background: url("images/bg.gif"); }</style></head>
            <body><a href="{{web_version_url}}">View</a>
            <img src="images/logo%20big.png" alt="Logo"><img srcset="./images/logo%20big.png 1x" src="https://cdn.example.com/x.png" alt="">
            {{footer}}</body></html>
            HTML,
        'email.txt' => "March news\n{{unsubscribe_url}}",
        'translations/fr.html' => '<html><body><img src="../images/Logo Big.png" alt="Logo"><td background="images/bg.gif"></td>{{footer}}</body></html>',
        'images/logo big.png' => testImage('png'),
        'images/bg.gif' => testImage('gif'),
        'images/unused.webp' => testImage('webp'),
    ]);
}

describe('saving an uploaded email', function (): void {
    it('stores its images in the library and points the email at them', function (): void {
        $template = Template::factory()->create();
        $uploadId = uploaded(newsletterZip());
        $logo = assetUrl(testImage('png'), 'png');
        $background = assetUrl(testImage('gif'), 'gif');

        $saved = app(AddUploadedRevisionAction::class)->execute(
            owner: $template,
            uploadId: $uploadId,
            // Written as an agent may write them: they still reach fr and en.
            subjects: ['FR' => 'Les nouvelles de mars'],
            preheaders: ['En' => 'What changed in March'],
            note: 'From the zip',
            author: 'laptop',
        );

        $english = $saved->revision->contentFor('en');
        $french = $saved->revision->contentFor('fr');

        expect($saved->revision)
            ->number->toBe(1)
            ->note->toBe('From the zip')
            ->and($saved->unusedImages)->toBe(['images/unused.webp'])
            ->and(collect($saved->assets)->pluck('url')->all())->toEqualCanonicalizing([$logo, $background])
            ->and(collect($saved->assets)->pluck('name')->all())->toEqualCanonicalizing(['logo big.png', 'bg.gif'])
            ->and(Storage::disk('public')->allFiles())->toHaveCount(2)
            ->and($english)
            ->subject->toBe('March news')
            ->preheader->toBe('What changed in March')
            ->text->toBe("March news\n{{unsubscribe_url}}")
            ->text_generated->toBeFalse()
            ->and($english->html)
            ->toContain('<a href="{{web_version_url}}">View</a>')
            ->toContain("url(\"{$background}\")")
            ->toContain("<img src=\"{$logo}\" alt=\"Logo\">")
            ->toContain("srcset=\"{$logo} 1x\"")
            ->and($french)
            ->subject->toBe('Les nouvelles de mars')
            ->text_generated->toBeTrue()
            ->and($french->html)->toBe("<html><body><img src=\"{$logo}\" alt=\"Logo\"><td background=\"{$background}\"></td>{{footer}}</body></html>")
            ->and(Upload::query()->sole())
            ->claimed_at->not->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        $again = app(AddUploadedRevisionAction::class)->execute($template, uploaded(newsletterZip()), ['fr' => 'Mars']);

        expect($again->revision->number)->toBe(2)
            ->and(Asset::query()->count())->toBe(2)
            ->and(collect($again->assets)->pluck('id')->all())->toEqualCanonicalizing(collect($saved->assets)->pluck('id')->all());
    });

    it('takes one HTML file whose images are already on https', function (): void {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

        $saved = app(AddUploadedRevisionAction::class)->execute(
            $campaign,
            uploaded('<html><head><title>Hello</title></head><body><img src="https://cdn.example.com/a.png" alt="">{{footer}}</body></html>'),
        );

        expect($saved->revision->contentFor('en')->subject)->toBe('Hello')
            ->and($saved->assets)->toBe([])
            ->and($campaign->currentRevision()->sole()->id)->toBe($saved->revision->id);
    });

    it('refuses a broken upload whole, storing nothing', function (string $bytes, array $subjects, array $problems): void {
        $template = Template::factory()->create();

        try {
            app(AddUploadedRevisionAction::class)->execute($template, uploaded($bytes), $subjects);

            $this->fail('The upload was not refused.');
        } catch (CampaignsException $exception) {
            foreach ($problems as $problem) {
                expect($exception->getMessage())->toContain($problem);
            }
        }

        expect(Asset::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([])
            ->and($template->revisions()->count())->toBe(0);
    })->with([
        'a missing image, no subject and a stray one' => [
            zipOf([
                'email.html' => '<img src="images/missing.png" alt=""><img src="images/a.png" alt="">{{footer}}',
                'images/a.png' => testImage(),
            ]),
            ['de' => 'Hallo'],
            [
                'en: the image "images/missing.png" is not in the upload',
                'en: there is no subject',
                'A subject or preheader was given for de',
            ],
        ],
        'an image that isn\'t one' => [
            zipOf([
                'email.html' => '<title>Hi</title><img src="images/a.svg" alt="">{{footer}}',
                'images/a.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            ]),
            [],
            ['The image "a.svg" is not a JPEG, PNG, GIF or WebP image.'],
        ],
        'an email the revision rules refuse' => [
            zipOf([
                'email.html' => '<title>Hi</title><img src="images/a.png" alt="">No way out',
                'images/a.png' => testImage(),
            ]),
            [],
            ['en: the HTML has no unsubscribe link'],
        ],
        'a relative image in a single HTML file' => [
            '<title>Hi</title><img src="logo.png" alt="">{{footer}}',
            [],
            ['the image "logo.png" is not in the upload'],
        ],
    ]);

    it('refuses a campaign past its draft before using up the upload', function (): void {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
        $uploadId = uploaded('<title>Hi</title>{{footer}}');

        expect(fn () => app(AddUploadedRevisionAction::class)->execute($campaign, $uploadId))
            ->toThrow(CampaignsException::class, 'The campaign is sending')
            ->and(app(ClaimUploadAction::class)->execute($uploadId, UploadPurpose::Email)->claimed_at)->not->toBeNull();
    });

    it('reads an upload only once', function (): void {
        $uploadId = uploaded('<title>Hi</title>{{footer}}');

        app(ReadEmailUploadAction::class)->execute($uploadId);

        expect(fn () => app(ReadEmailUploadAction::class)->execute($uploadId))
            ->toThrow(CampaignsException::class, 'it was already used');
    });
});
