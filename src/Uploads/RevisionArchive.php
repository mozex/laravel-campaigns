<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Uploads;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use ZipArchive;

/**
 * A revision's email as files, laid out the way an upload takes them, so an
 * agent downloads it, edits it and sends the same zip back: email.html in
 * the default language, translations/<locale>.html for each other one, and
 * beside each a .txt when its plain text was written rather than generated
 * (a generated one is generated again on upload). Its images are already
 * the asset library's addresses. Subjects and preheaders aren't files: the
 * tool that hands out the link names them.
 */
class RevisionArchive
{
    /**
     * @return array<string, string> the files by name
     */
    public static function files(Revision $revision): array
    {
        $default = $revision->contentFor(null);
        $files = static::pair('email', $default);

        foreach ($revision->contents->sortBy('locale') as $content) {
            if ($content->id !== $default->id) {
                $files = [...$files, ...static::pair("translations/{$content->locale}", $content)];
            }
        }

        return $files;
    }

    /**
     * The default language's HTML alone.
     */
    public static function html(Revision $revision): string
    {
        return $revision->contentFor(null)->html;
    }

    /**
     * @throws CampaignsException when the zip can't be written
     */
    public static function zip(Revision $revision): string
    {
        $path = tempnam(sys_get_temp_dir(), 'campaigns-download-');

        if ($path === false) {
            throw CampaignsException::downloadNotBuilt();
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw CampaignsException::downloadNotBuilt();
            }

            foreach (static::files($revision) as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            if (! $zip->close()) {
                throw CampaignsException::downloadNotBuilt();
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, string>
     */
    protected static function pair(string $name, RevisionContent $content): array
    {
        return $content->text_generated
            ? ["{$name}.html" => $content->html]
            : ["{$name}.html" => $content->html, "{$name}.txt" => $content->text];
    }
}
