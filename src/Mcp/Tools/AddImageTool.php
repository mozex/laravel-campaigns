<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\AddImageAction;
use Mozex\Campaigns\Actions\AddImageFromUrlAction;
use Mozex\Campaigns\Actions\ClaimUploadAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Reports\AssetSummary;

#[Name('add_image')]
#[Title('Add an image')]
#[Description('Adds one image to the asset library and answers its public https address, for an email\'s <img src>. Give one of: upload_id (a file sent through an image upload link), data (the image in base64, at most 4 MB, for a client without a shell) or url (an https address the server fetches). JPEG, PNG, GIF or WebP only, never SVG. The same image added twice is one image. An email uploaded as a zip with an images folder needs none of this: its images join the library on their own.')]
#[IsIdempotent]
#[IsOpenWorld]
#[IsDestructive(false)]
class AddImageTool extends CampaignsTool
{
    /** The largest image sent inline, decoded. */
    public const INLINE_MAX = 4 * 1024 * 1024;

    public function schema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string(),
            'data' => $schema->string()->description('The image\'s bytes in base64; a data: URL\'s prefix is allowed.'),
            'url' => $schema->string()->description('An https address.'),
            'name' => $schema->string()->description('A name to find it by. By default the uploaded or fetched file\'s.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'asset' => Schemas::asset($schema),
            'added' => $schema->boolean()->description('False when the library already held the same image.'),
        ];
    }

    public function perform(Request $request, AddImageAction $add, AddImageFromUrlAction $fetch, ClaimUploadAction $claim): ResponseFactory
    {
        $input = $request->validate([
            'upload_id' => ['nullable', 'string', 'required_without_all:data,url', 'prohibits:data,url'],
            'data' => ['nullable', 'string', 'prohibits:url'],
            'url' => ['nullable', 'string', 'max:2048'],
            'name' => ['nullable', 'string'],
        ]);

        $name = $input['name'] ?? null;

        $asset = match (true) {
            isset($input['upload_id']) => static::fromUpload($input['upload_id'], $name, $claim, $add),
            isset($input['data']) => $add->execute(static::decoded($input['data']), $name, static::author()),
            default => $fetch->execute((string) $input['url'], $name, static::author()),
        };

        return Response::structured([
            'asset' => AssetSummary::row($asset),
            'added' => $asset->wasRecentlyCreated,
        ]);
    }

    /**
     * @throws CampaignsException
     */
    protected static function fromUpload(string $uploadId, ?string $name, ClaimUploadAction $claim, AddImageAction $add): Asset
    {
        $upload = $claim->execute($uploadId, UploadPurpose::Image);

        try {
            return $add->execute($upload->contents(), $name, static::author());
        } finally {
            $upload->deleteFile();
        }
    }

    /**
     * @throws CampaignsException
     */
    protected static function decoded(string $data): string
    {
        // A data: URL's prefix (data:image/png;base64,), which chat clients
        // often hand over whole.
        $data = (string) preg_replace(['/\Adata:[^,]*;base64,/i', '/\s+/'], '', trim($data));
        $tooLarge = CampaignsException::invalidValue('data', 'is over the '.(static::INLINE_MAX / 1024 / 1024).' MB an image sent inline may be; send a larger one through an image upload link');

        // Base64 takes four characters for every three bytes: a longer text
        // is refused before it is decoded.
        if (strlen($data) > intdiv(static::INLINE_MAX + 2, 3) * 4) {
            throw $tooLarge;
        }

        $bytes = base64_decode($data, true);

        if ($bytes === false || $bytes === '') {
            throw CampaignsException::invalidValue('data', 'isn\'t an image in base64');
        }

        if (strlen($bytes) > static::INLINE_MAX) {
            throw $tooLarge;
        }

        return $bytes;
    }
}
