<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Uploads;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Locales;
use ZipArchive;

/**
 * An uploaded email's files: one HTML file, or a zip holding email.html, an
 * optional email.txt, translations/<locale>.html (and .txt) and an images
 * folder. A zip is read in memory and never unpacked to disk, and it may
 * hold only those names: no path that climbs out (..), starts at the root
 * or is a link, no encrypted entry, and limits on its entries and unpacked
 * size, counted on the bytes read rather than on the sizes it declares.
 * Every problem is reported at once.
 */
readonly class EmailArchive
{
    public const MAX_ENTRIES = 200;

    public const MAX_UNPACKED = 50 * 1024 * 1024;

    /** An HTML or text file, far above the 90 KB an email may hold. */
    public const MAX_DOCUMENT = 1024 * 1024;

    /** What zip tools add on their own, skipped rather than refused. */
    protected const JUNK = '#(^|/)(__MACOSX/|\.DS_Store\z|Thumbs\.db\z)#';

    /**
     * @param  array<string, string>  $documents  the HTML and text files by their path in the zip
     * @param  array<string, string>  $images  the images folder's files by their path in the zip
     */
    final public function __construct(
        public array $documents,
        public array $images = [],
    ) {}

    /**
     * @throws CampaignsException listing every problem
     */
    public static function fromBytes(string $bytes): static
    {
        if (str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06")) {
            return static::fromZip($bytes);
        }

        if (strlen($bytes) > static::MAX_DOCUMENT) {
            throw CampaignsException::invalidUpload(['The HTML is over 1 MB.']);
        }

        return new static(['email.html' => $bytes]);
    }

    /**
     * Each locale's HTML file and its text file, if any, the default locale
     * from email.html.
     *
     * @return array<string, array{html: string, text: ?string}>
     */
    public function locales(): array
    {
        $locales = [];

        foreach ($this->documents as $path => $content) {
            if (! str_ends_with($path, '.html')) {
                continue;
            }

            $locale = $path === 'email.html' ? Locales::default() : substr($path, strlen('translations/'), -strlen('.html'));
            $text = substr($path, 0, -strlen('.html')).'.txt';

            $locales[$locale] = ['html' => $path, 'text' => isset($this->documents[$text]) ? $text : null];
        }

        return $locales;
    }

    /**
     * @throws CampaignsException
     */
    protected static function fromZip(string $bytes): static
    {
        $file = tempnam(sys_get_temp_dir(), 'campaigns-zip-');

        if ($file === false || file_put_contents($file, $bytes) === false) {
            throw CampaignsException::uploadNotStored();
        }

        $zip = new ZipArchive;

        try {
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw CampaignsException::invalidUpload(['The file is neither HTML nor a zip that can be read.']);
            }

            try {
                return static::read($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($file);
        }
    }

    /**
     * @throws CampaignsException
     */
    protected static function read(ZipArchive $zip): static
    {
        // The files zip tools add don't count against the limit, so a raw
        // count only stops a zip far past it before each entry is read.
        if ($zip->numFiles > static::MAX_ENTRIES * 5) {
            throw CampaignsException::invalidUpload(['The zip holds more than '.static::MAX_ENTRIES.' files.']);
        }

        [$entries, $problems] = static::entries($zip);

        if (count($entries) > static::MAX_ENTRIES) {
            throw CampaignsException::invalidUpload(['The zip holds more than '.static::MAX_ENTRIES.' files.']);
        }

        $prefix = static::commonFolder(array_keys($entries));
        $documents = [];
        $images = [];
        $seen = [];
        $unpacked = 0;

        foreach ($entries as $name => $index) {
            $path = substr($name, strlen($prefix));

            if (isset($seen[strtolower($path)])) {
                $problems[] = "{$path} is in the zip twice (names are compared without case).";

                continue;
            }

            $seen[strtolower($path)] = true;
            $kind = static::kind($path);

            if ($kind === null) {
                $problems[] = static::shown($path).' is not expected: a zip holds email.html, an optional email.txt, translations/<locale>.html and .txt, and an images folder.';

                continue;
            }

            $limit = $kind === 'image' ? (int) config('campaigns.assets.max_size') : static::MAX_DOCUMENT;
            $declared = (int) ($zip->statIndex($index)['size'] ?? 0);

            if ($declared > $limit) {
                $problems[] = static::shown($path).' is over '.round($limit / 1024 / 1024, 1).' MB.';

                continue;
            }

            $unpacked += $declared;

            if ($unpacked > static::MAX_UNPACKED) {
                throw CampaignsException::invalidUpload([...$problems, 'The zip unpacks to more than '.(static::MAX_UNPACKED / 1024 / 1024).' MB.']);
            }

            // Read to the declared size, never to the limit: PHP reserves the
            // whole length asked for, so twenty small images read with a 5 MB
            // length each would hold 100 MB. A file whose bytes don't match
            // its declared size is refused, so the sizes above are the real
            // ones.
            $content = $zip->getFromIndex($index, $declared + 1);

            if ($content === false || strlen($content) !== $declared) {
                $problems[] = static::shown($path).' can\'t be read.';

                continue;
            }

            if ($kind === 'image') {
                $images[$path] = $content;
            } else {
                $documents[$path] = $content;
            }
        }

        if (! isset($seen['email.html'])) {
            $problems[] = 'The zip has no email.html.';
        }

        $default = strtolower(str_replace('-', '_', Locales::default()));

        foreach (array_keys($documents) as $path) {
            if (strtolower(str_replace('-', '_', $path)) === "translations/{$default}.html") {
                $problems[] = "{$path} is in ".Locales::default().', the language email.html is in: leave one of them out.';
            }

            // A text file goes with the HTML file of its name; alone, it
            // would be dropped without a word.
            if (str_ends_with($path, '.txt') && ! isset($documents[substr($path, 0, -strlen('.txt')).'.html'])) {
                $problems[] = static::shown($path).' has no HTML file beside it of the same name.';
            }
        }

        if ($problems !== []) {
            throw CampaignsException::invalidUpload($problems);
        }

        return new static($documents, $images);
    }

    /**
     * The zip's files by name, every unsafe one reported instead: a name
     * that climbs out with .., starts at the root or a drive, holds a
     * backslash or a control character; a link; an encrypted file.
     * Folders and the files zip tools add on their own are skipped.
     *
     * @return array{0: array<string, int>, 1: list<string>} the entries' indexes by name, and the problems
     */
    protected static function entries(ZipArchive $zip): array
    {
        $problems = [];
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                $problems[] = "File {$index} of the zip can't be read.";

                continue;
            }

            $name = $stat['name'];
            $shown = static::shown($name);

            if (preg_match('/[\x00-\x1F\x7F\\\\]/', $name) === 1 || ! mb_check_encoding($name, 'UTF-8')) {
                $problems[] = "{$shown} has a name with a backslash or a control character.";

                continue;
            }

            if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
                $problems[] = "{$shown} is an absolute path.";

                continue;
            }

            if (in_array('..', explode('/', $name), true)) {
                $problems[] = "{$shown} climbs out of the zip with \"..\".";

                continue;
            }

            if ($zip->getExternalAttributesIndex($index, $system, $attributes)
                && $system === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0xF000) === 0xA000) {
                $problems[] = "{$shown} is a link.";

                continue;
            }

            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                $problems[] = "{$shown} is encrypted.";

                continue;
            }

            if (str_ends_with($name, '/') || preg_match(static::JUNK, $name) === 1) {
                continue;
            }

            if (isset($entries[$name])) {
                $problems[] = "{$shown} is in the zip twice.";

                continue;
            }

            $entries[$name] = $index;
        }

        return [$entries, $problems];
    }

    /**
     * The one folder every file sits in, when the zip was made from a
     * folder rather than from its contents and email.html is inside it.
     *
     * @param  list<string>  $names
     */
    protected static function commonFolder(array $names): string
    {
        if ($names === [] || in_array('email.html', $names, true)) {
            return '';
        }

        $folder = strstr($names[0], '/', true);

        if ($folder === false || ! in_array("{$folder}/email.html", $names, true)) {
            return '';
        }

        foreach ($names as $name) {
            if (! str_starts_with($name, "{$folder}/")) {
                return '';
            }
        }

        return "{$folder}/";
    }

    /**
     * @return 'document'|'image'|null
     */
    protected static function kind(string $path): ?string
    {
        if ($path === 'email.html' || $path === 'email.txt') {
            return 'document';
        }

        if (preg_match('#^translations/([^/]+)\.(html|txt)\z#', $path, $match) === 1) {
            return Locales::isValid($match[1]) ? 'document' : null;
        }

        if (preg_match('#^images/(?:[^/]+/)*[^/]+\z#', $path) === 1) {
            return 'image';
        }

        return null;
    }

    protected static function shown(string $name): string
    {
        return '"'.mb_strimwidth(mb_check_encoding($name, 'UTF-8') ? $name : mb_convert_encoding($name, 'UTF-8', 'UTF-8'), 0, 120, '...').'"';
    }
}
