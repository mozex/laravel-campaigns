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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\CreateUploadAction;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Support\Links;

#[Name('create_upload_link')]
#[Title('Create an upload link')]
#[Description('A one-time address to send a local file to, for an agent that can run a shell: PUT the file\'s bytes there (the answer has the curl command), then pass the upload_id to the tool that takes it. purpose email: one HTML file, or a zip of email.html, an optional email.txt, translations/<locale>.html and .txt, and an images folder, for create_template or add_revision. image: one JPEG, PNG, GIF or WebP, for add_image. contacts: a CSV, for preview_contacts_import and run_contacts_import. The link takes one file within '.Upload::LINK_MINUTES.' minutes; the file then waits '.Upload::KEEP_HOURS.' hours. A file the link refuses before storing it (too large) leaves the link usable until it expires; once a tool has read the file it is used up, accepted or refused, so a refused file goes up again through a new link.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class CreateUploadLinkTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'purpose' => $schema->string()->enum(array_column(UploadPurpose::cases(), 'value'))->required(),
            'file_name' => $schema->string()->description('The local file\'s path, put into the curl command, without a quote. Leave it out to get FILE there.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string(),
            'purpose' => $schema->string()->enum(array_column(UploadPurpose::cases(), 'value')),
            'upload_url' => $schema->string(),
            'method' => $schema->string()->enum(['PUT']),
            'expires_at' => $schema->string(),
            'max_file_size' => $schema->integer()->description('Bytes: the largest file this link takes.'),
            'curl' => $schema->string()->description('The command that sends the file.'),
            'next' => $schema->string(),
        ];
    }

    public function perform(Request $request, CreateUploadAction $create): ResponseFactory
    {
        $input = $request->validate([
            'purpose' => ['required', 'string', 'in:'.implode(',', array_column(UploadPurpose::cases(), 'value'))],
            // No quote: no one way of escaping it works in both a POSIX shell
            // and PowerShell.
            'file_name' => ['nullable', 'string', 'max:1024', 'not_regex:/[\'\x00-\x1F]/'],
        ]);

        $upload = $create->execute(UploadPurpose::from($input['purpose']), static::author());
        $url = Links::upload($upload);

        // --fail-with-body, not --fail: both make curl exit non-zero on a
        // refusal, but only it prints the reason the link answers with.
        return Response::structured([
            'upload_id' => $upload->uuid,
            'purpose' => $upload->purpose->value,
            'upload_url' => $url,
            'method' => 'PUT',
            'expires_at' => $upload->expires_at->toIso8601String(),
            'max_file_size' => Upload::maxSize($upload->purpose),
            'curl' => 'curl --fail-with-body --upload-file '.static::quoted($input['file_name'] ?? 'FILE').' '.static::quoted($url),
            'next' => 'Send the file with the curl command (in Windows PowerShell, type curl.exe: a bare curl there is another command). A curl older than 7.76 stops at --fail-with-body before sending anything: use --fail there. When the file is refused, the answer says why.',
        ]);
    }

    /**
     * In single quotes, which a POSIX shell and PowerShell both read
     * literally, so the signed address's & and = survive. Neither the
     * address nor an accepted file name holds a quote.
     */
    protected static function quoted(string $value): string
    {
        return "'{$value}'";
    }
}
