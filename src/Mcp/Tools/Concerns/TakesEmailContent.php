<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Mozex\Campaigns\Content\EmailContent;

/**
 * The two ways a tool takes an email: an upload (one HTML file or a zip,
 * sent through an email upload link) with its subjects and preheaders, or
 * the content written inline, for a client without a shell.
 */
trait TakesEmailContent
{
    /**
     * @return array<string, Type>
     */
    protected static function contentInput(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->description('An email sent through an email upload link: one HTML file, or a zip of email.html, an optional email.txt, translations/<locale>.html and .txt, and an images folder whose relative paths become the asset library\'s addresses.'),
            'subjects' => $schema->object()->description('With upload_id: each language\'s subject, like {"en": "...", "fr": "..."}; one not given is the HTML\'s <title>.'),
            'preheaders' => $schema->object()->description('With upload_id: each language\'s preheader, the line mail apps show beside the subject.'),
            'contents' => $schema->array()->items($schema->object([
                'locale' => $schema->string()->required()->description('Like en, pt_BR. The default language is required.'),
                'subject' => $schema->string()->required(),
                'preheader' => $schema->string(),
                'html' => $schema->string()->required(),
                'text' => $schema->string()->description('The plain text; generated from the HTML when left out.'),
            ]))->description('Instead of upload_id: the email inline, one item per language.'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function contentRules(): array
    {
        return [
            'upload_id' => ['nullable', 'string', 'prohibits:contents'],
            'subjects' => ['nullable', 'array'],
            'subjects.*' => ['string'],
            'preheaders' => ['nullable', 'array'],
            'preheaders.*' => ['string'],
            'contents' => ['nullable', 'array', 'list', 'min:1'],
            'contents.*' => ['array'],
            'contents.*.locale' => ['required', 'string'],
            'contents.*.subject' => ['required', 'string'],
            'contents.*.preheader' => ['nullable', 'string'],
            'contents.*.html' => ['required', 'string'],
            'contents.*.text' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<EmailContent>
     */
    protected static function inlineContents(array $input): array
    {
        /** @var list<array{locale: string, subject: string, html: string, preheader?: ?string, text?: ?string}> $contents */
        $contents = $input['contents'] ?? [];

        return array_map(fn (array $content): EmailContent => new EmailContent(
            locale: $content['locale'],
            subject: $content['subject'],
            html: $content['html'],
            text: $content['text'] ?? null,
            preheader: $content['preheader'] ?? null,
        ), $contents);
    }

    /**
     * An upload's subjects or preheaders, by locale.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    protected static function byLocale(array $input, string $field): array
    {
        /** @var array<string, string> */
        return array_map('strval', (array) ($input[$field] ?? []));
    }
}
