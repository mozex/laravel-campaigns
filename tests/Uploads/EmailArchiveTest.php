<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Uploads\EmailArchive;

readonly class SmallEmailArchive extends EmailArchive
{
    public const MAX_UNPACKED = 100;
}

it('reads a zip\'s email, translations and images, from inside a folder too, skipping what zip tools add', function (): void {
    $archive = EmailArchive::fromBytes(zipOf([
        'newsletter/email.html' => '<p>Hello</p>',
        'newsletter/email.txt' => 'Hello',
        'newsletter/translations/pt-BR.html' => '<p>Olá</p>',
        'newsletter/images/icons/a.png' => testImage(),
        'newsletter/.DS_Store' => 'junk',
        '__MACOSX/newsletter/._email.html' => 'junk',
    ]));

    expect($archive->documents)->toBe([
        'email.html' => '<p>Hello</p>',
        'email.txt' => 'Hello',
        'translations/pt-BR.html' => '<p>Olá</p>',
    ])
        ->and(array_keys($archive->images))->toBe(['images/icons/a.png'])
        ->and($archive->locales())->toBe([
            'en' => ['html' => 'email.html', 'text' => 'email.txt'],
            'pt-BR' => ['html' => 'translations/pt-BR.html', 'text' => null],
        ])
        ->and(EmailArchive::fromBytes('<p>Just HTML</p>')->documents)->toBe(['email.html' => '<p>Just HTML</p>']);
});

it('refuses a zip holding anything unsafe or unexpected, naming every problem', function (Closure $zip, string $problem): void {
    config()->set('campaigns.assets.max_size', 1000);

    expect(fn () => EmailArchive::fromBytes($zip()))->toThrow(CampaignsException::class, $problem);
})->with([
    'a path that climbs out' => [fn (): string => zipOf(['email.html' => 'x', '../evil.html' => 'x']), '"../evil.html" climbs out of the zip'],
    'an absolute path' => [fn (): string => zipOf(['email.html' => 'x', '/etc/cron.d/evil' => 'x']), 'is an absolute path'],
    'a drive' => [fn (): string => zipOf(['email.html' => 'x', 'C:/evil.html' => 'x']), 'is an absolute path'],
    'a backslash' => [fn (): string => zipOf(['email.html' => 'x', 'images\\..\\evil.png' => 'x']), 'backslash or a control character'],
    'a link' => [fn (): string => zipOf(['email.html' => 'x', 'images/a.png' => '/etc/passwd'], links: ['images/a.png']), '"images/a.png" is a link'],
    'an encrypted file' => [fn (): string => zipOf(['email.html' => 'x'], encrypted: ['email.html']), '"email.html" is encrypted'],
    'an unexpected name' => [fn (): string => zipOf(['email.html' => 'x', 'script.js' => 'x']), '"script.js" is not expected'],
    'an unknown locale' => [fn (): string => zipOf(['email.html' => 'x', 'translations/not a locale.html' => 'x']), 'is not expected'],
    'one name twice' => [fn (): string => zipOf(['email.html' => 'x', 'images/a.png' => 'x', 'images/A.png' => 'x']), 'is in the zip twice'],
    'no email' => [fn (): string => zipOf(['email.txt' => 'x']), 'The zip has no email.html.'],
    'a text file alone' => [fn (): string => zipOf(['email.html' => 'x', 'translations/fr.txt' => 'x']), '"translations/fr.txt" has no HTML file beside it'],
    'an image over the limit' => [fn (): string => zipOf(['email.html' => 'x', 'images/a.png' => str_repeat('x', 1001)]), '"images/a.png" is over'],
    'an HTML file over the limit' => [fn (): string => zipOf(['email.html' => str_repeat('x', EmailArchive::MAX_DOCUMENT + 1)]), '"email.html" is over 1 MB'],
    'too many files' => [fn (): string => zipOf(['email.html' => 'x', ...array_fill_keys(array_map(fn (int $i): string => "images/{$i}.png", range(1, EmailArchive::MAX_ENTRIES)), 'x')]), 'more than 200 files'],
    'the default language twice' => [fn (): string => zipOf(['email.html' => 'x', 'translations/EN.html' => 'y']), 'translations/EN.html is in en, the language email.html is in'],
    'a broken zip' => [fn (): string => "PK\x03\x04broken", 'neither HTML nor a zip'],
]);

it('stops reading once the zip unpacks past its limit, counting what zip tools add against nothing', function (): void {
    $junk = array_fill_keys(array_map(fn (int $i): string => "__MACOSX/images/._{$i}.png", range(1, EmailArchive::MAX_ENTRIES)), 'x');

    expect(EmailArchive::fromBytes(zipOf(['email.html' => 'x', ...$junk]))->documents)->toBe(['email.html' => 'x'])
        ->and(fn () => SmallEmailArchive::fromBytes(zipOf(['email.html' => str_repeat('x', 60), 'images/a.png' => str_repeat('y', 60)])))
        ->toThrow(CampaignsException::class, 'The zip unpacks to more than');
});

it('reads small files without reserving the size limit for each', function (): void {
    $images = array_fill_keys(array_map(fn (int $i): string => "images/{$i}.png", range(1, 30)), str_repeat('x', 100));
    $zip = zipOf(['email.html' => 'x', ...$images]);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $archive = EmailArchive::fromBytes($zip);

    expect(memory_get_peak_usage() - $before)->toBeLessThan(20 * 1024 * 1024)
        ->and($archive->images)->toHaveCount(30);
});

it('lists every problem at once', function (): void {
    try {
        EmailArchive::fromBytes(zipOf(['../a' => 'x', 'b.js' => 'x']));
    } catch (CampaignsException $exception) {
        expect($exception->getMessage())
            ->toContain('"../a" climbs out')
            ->toContain('"b.js" is not expected')
            ->toContain('The zip has no email.html.')
            ->and($exception->getCode())->toBe(422);

        return;
    }

    $this->fail('The zip was not refused.');
});
