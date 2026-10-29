<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Assets\AssetLibrary;
use Mozex\Campaigns\Assets\ImageFile;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\Html;
use Mozex\Campaigns\Content\ImageReferences;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Uploads\EmailArchive;
use Mozex\Campaigns\Uploads\EmailUpload;

/**
 * Turns an uploaded email (one HTML file, or a zip with its translations
 * and images) into content a template or campaign can save. Its relative
 * image paths are pointed at the addresses the asset library will give
 * those images, and the whole email is checked against the revision rules
 * before anything is stored, so a refused upload leaves nothing behind.
 * Storing the images is the caller's next step (AddUploadedRevisionAction).
 *
 * Each version's subject is the one given for its locale, else the HTML's
 * <title>; its preheader is the one given, else none.
 */
class ReadEmailUploadAction
{
    public function __construct(
        protected ClaimUploadAction $claim,
        protected AssetLibrary $library,
        protected ContentRules $rules,
    ) {}

    /**
     * Claims the upload, once, and reads it. Its file is deleted once read.
     *
     * @param  array<string, string>  $subjects  by locale
     * @param  array<string, string>  $preheaders  by locale
     *
     * @throws CampaignsException listing every problem
     */
    public function execute(string $uploadId, array $subjects = [], array $preheaders = []): EmailUpload
    {
        static::checkGiven($subjects, $preheaders);

        $upload = $this->claim->execute($uploadId, UploadPurpose::Email);

        try {
            $bytes = $upload->contents();
        } finally {
            $upload->deleteFile();
        }

        return $this->read($bytes, $subjects, $preheaders);
    }

    /**
     * What can be refused in the subjects and preheaders given before the
     * upload is read, since reading uses it up: each keyed by a locale and
     * no longer than a subject may be. Whether the upload holds that locale
     * is known only once it is read.
     *
     * @param  array<array-key, mixed>  $subjects
     * @param  array<array-key, mixed>  $preheaders
     *
     * @throws CampaignsException listing every problem
     */
    public static function checkGiven(array $subjects, array $preheaders): void
    {
        $problems = [];

        foreach (['subject' => $subjects, 'preheader' => $preheaders] as $part => $given) {
            foreach ($given as $locale => $value) {
                $shown = mb_strimwidth((string) $locale, 0, 40, '...');

                if (! Locales::isValid((string) $locale)) {
                    $problems[] = "A {$part} was given for \"{$shown}\", which is not a locale (en, pt_BR, zh-Hant).";
                } elseif (! is_string($value) || mb_strlen($value) > ContentRules::MAX_LINE_LENGTH) {
                    $problems[] = "{$shown}: the {$part} must be text of at most ".ContentRules::MAX_LINE_LENGTH.' characters.';
                }
            }
        }

        if ($problems !== []) {
            throw CampaignsException::invalidUpload($problems);
        }
    }

    /**
     * Reads an email's bytes: an HTML file, or a zip.
     *
     * @param  array<string, string>  $subjects  by locale
     * @param  array<string, string>  $preheaders  by locale
     *
     * @throws CampaignsException listing every problem
     */
    public function read(string $bytes, array $subjects = [], array $preheaders = []): EmailUpload
    {
        $archive = EmailArchive::fromBytes($bytes);
        $locales = $archive->locales();
        $problems = [];
        $used = [];
        // Each version's relative image references, and the images folder's
        // file each one names.
        $references = [];

        $spelled = [];

        foreach (array_keys($locales) as $locale) {
            $spelled[Locales::normalize($locale)] = $locale;
        }

        $subjects = $this->byArchiveLocale($subjects, $spelled, $problems);
        $preheaders = $this->byArchiveLocale($preheaders, $spelled, $problems);

        foreach ($locales as $locale => $files) {
            $directory = dirname($files['html']) === '.' ? '' : dirname($files['html']);

            foreach (ImageReferences::relative($archive->documents[$files['html']]) as $reference) {
                $image = $this->resolve($reference, $directory, $archive->images);

                if ($image === null) {
                    $problems[] = "{$locale}: the image \"".mb_strimwidth($reference, 0, 120, '...').'" is not in the upload: put it in the zip\'s images folder, or use its full https address.';

                    continue;
                }

                $references[$locale][$reference] = $image;
                $used[$image] = true;
            }
        }

        $images = [];
        $urls = [];

        foreach (array_keys($used) as $path) {
            try {
                $images[$path] = ImageFile::fromBytes($archive->images[$path], basename($path));
                $urls[$path] = $this->library->url($images[$path]);
            } catch (CampaignsException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        $contents = [];

        foreach ($locales as $locale => $files) {
            $resolved = $references[$locale] ?? [];

            $html = ImageReferences::rewrite($archive->documents[$files['html']], function (string $reference) use ($resolved, $urls): ?string {
                $image = $resolved[$reference] ?? null;

                return $image === null ? null : $urls[$image] ?? null;
            });

            $subject = $subjects[$locale] ?? $this->title($html);

            if ($subject === null) {
                $problems[] = "{$locale}: there is no subject: give one, or write it in the HTML's <title>.";
            }

            $contents[] = new EmailContent(
                locale: $locale,
                subject: (string) $subject,
                html: $html,
                text: $files['text'] === null ? null : $archive->documents[$files['text']],
                preheader: $preheaders[$locale] ?? null,
            );
        }

        if ($problems !== []) {
            throw CampaignsException::invalidUpload($problems);
        }

        // The rules every revision meets, checked now, before the caller
        // stores a single image.
        $this->rules->prepare($contents);

        return new EmailUpload(
            contents: $contents,
            images: array_values($images),
            unusedImages: array_values(array_diff(array_keys($archive->images), array_keys($used))),
        );
    }

    /**
     * The images folder's file a reference names: relative to the HTML
     * file's own folder, else to the zip's root, a leading slash meaning
     * the root, and ".." never climbing above it. A query or fragment is
     * ignored, and %20 and the like decoded.
     *
     * @param  array<string, string>  $images
     */
    protected function resolve(string $reference, string $directory, array $images): ?string
    {
        $path = rawurldecode((string) preg_replace('/[?#].*\z/s', '', $reference));

        $candidates = str_starts_with($path, '/')
            ? [$path]
            : array_filter([$directory === '' ? null : "{$directory}/{$path}", $path]);

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);

            if (isset($images[$normalized])) {
                return $normalized;
            }

            // A reference whose case differs still finds its file, as the
            // author's own preview on Windows or macOS did; the archive
            // refuses two names that differ only by case, so it is one file.
            foreach (array_keys($images) as $image) {
                if (strcasecmp($image, $normalized) === 0) {
                    return $image;
                }
            }
        }

        return null;
    }

    /**
     * Subjects or preheaders keyed by the archive's own spelling of each
     * locale, however they were given (EN, pt-br), as every other locale
     * lookup compares them; one naming a version the upload lacks is a
     * problem.
     *
     * @param  array<string, string>  $given
     * @param  array<string, string>  $spelled  the archive's locales by their normalized form
     * @param  list<string>  $problems
     * @return array<string, string>
     */
    protected function byArchiveLocale(array $given, array $spelled, array &$problems): array
    {
        $keyed = [];

        foreach ($given as $locale => $value) {
            $archived = $spelled[Locales::normalize((string) $locale)] ?? null;

            if ($archived === null) {
                $problems[] = "A subject or preheader was given for {$locale}, which the upload has no version of.";

                continue;
            }

            $keyed[$archived] = $value;
        }

        return $keyed;
    }

    protected function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    protected function title(string $html): ?string
    {
        $title = Html::parse($html)->getElementsByTagName('title')->item(0)?->textContent;
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $title));

        return $title === '' ? null : $title;
    }
}
