<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Mozex\Campaigns\Content\CreatedTemplate;
use Mozex\Campaigns\Support\ShownText;
use Mozex\Campaigns\Uploads\UploadedRevision;

/**
 * What saving an uploaded email adds to an answer: how many images it
 * stored, and the images folder's files no version uses (a zip holds up to
 * 200 files, so the list is cut to the first MAX_UNUSED).
 */
trait AnswersUploadedEmails
{
    protected const MAX_UNUSED = 50;

    /**
     * @return array<string, Type>
     */
    protected static function uploadOutput(JsonSchema $schema): array
    {
        return [
            'images' => $schema->integer()->description('For an upload: the images it uses, now in the asset library (one already there counts too).'),
            'unused_images' => $schema->array()->items($schema->string())->description('For an upload: the images folder\'s files no version uses, which weren\'t stored. At most '.self::MAX_UNUSED.'.'),
            'unused_images_total' => $schema->integer(),
        ];
    }

    /**
     * @param  CreatedTemplate|UploadedRevision|null  $uploaded  null when the email wasn't uploaded
     * @return array<string, mixed>
     */
    protected static function uploadAnswer(CreatedTemplate|UploadedRevision|null $uploaded): array
    {
        if ($uploaded === null) {
            return [];
        }

        return [
            'images' => count($uploaded->assets),
            'unused_images' => array_map(
                fn (string $path): string => (string) ShownText::of($path, 200),
                array_slice($uploaded->unusedImages, 0, self::MAX_UNUSED),
            ),
            'unused_images_total' => count($uploaded->unusedImages),
        ];
    }
}
