<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Mcp\Principal;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * The rules every tool shares. A tool is a read tool when it carries
 * #[IsReadOnly], and every other tool needs full rights, so a tool that
 * forgets its hints is never shown to a read-only principal. A tool the
 * principal may not use isn't listed and can't be called.
 *
 * Its work runs in perform(), with the container's injection. A
 * CampaignsException or a ValidationException is a refusal, answered as a
 * tool error with the message, and with that message as `error` in the
 * structured content too: every output schema declares it and requires
 * nothing at the top level, so a refusal passes the same schema as an
 * answer. Anything else is left to laravel/mcp, which reports it and
 * answers with a generic error.
 *
 * A tool that sets $requiresApproval carries Claude Code's approval
 * meta, which asks the person before every call, even in bypass mode.
 *
 * @method ResponseFactory perform()
 */
abstract class CampaignsTool extends Tool
{
    /**
     * The longest answer: Claude Code saves a longer one to a file rather
     * than reading it, so anything longer goes out a page at a time or
     * through a download link.
     */
    public const RESULT_LIMIT = 50000;

    /**
     * The longest refusal, which a list of an email's problems stays under.
     */
    public const REFUSAL_LENGTH = 5000;

    /**
     * A page number's rules: an upper bound, since a huge one overflows the
     * offset it becomes and fails instead of answering an empty page.
     */
    public const PAGE_RULES = ['nullable', 'integer', 'min:1', 'max:100000'];

    public const APPROVAL_META = 'anthropic/requiresUserInteraction';

    /**
     * The source of the consent records an agent's changes leave.
     */
    public const CONSENT_SOURCE = 'agent';

    protected bool $requiresApproval = false;

    /**
     * The fields of this tool's answer.
     *
     * @return array<string, Type>
     */
    abstract protected function output(JsonSchema $schema): array;

    public function rights(): Rights
    {
        return ($this->annotations()['readOnlyHint'] ?? false) === true ? Rights::Read : Rights::Full;
    }

    public function requiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function shouldRegister(): bool
    {
        return Principal::current()?->allows($this->rights()) ?? false;
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->output($schema),
            'error' => $schema->string()->description('Why the call was refused. Only a refusal carries it.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->requiresApproval) {
            $this->setMeta(static::APPROVAL_META, true);
        }

        return parent::toArray();
    }

    public function handle(): ResponseFactory
    {
        if (! $this->shouldRegister()) {
            return static::refused('This needs '.$this->rights()->value.' rights on the campaigns server.');
        }

        // Some clients send "" for every optional argument they leave out,
        // and laravel/mcp reads the raw body, past Laravel's
        // ConvertEmptyStringsToNull: a blank counts as not given.
        if (app()->bound('mcp.request')) {
            /** @var Request $request */
            $request = app('mcp.request');
            $request->setArguments(array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $request->all()));
        }

        try {
            /** @var ResponseFactory $response */
            $response = app()->call([$this, 'perform']);
        } catch (CampaignsException $exception) {
            return static::refused($exception->getMessage());
        } catch (ValidationException $exception) {
            return static::refused(ValidationMessages::from($exception));
        }

        return static::withinLimit($response);
    }

    public static function refused(string $message): ResponseFactory
    {
        // A refusal may quote what the agent sent, which has no length limit.
        $message = mb_strimwidth($message, 0, static::REFUSAL_LENGTH, '...');

        return Response::make(Response::error($message))->withStructuredContent(['error' => $message]);
    }

    /**
     * The consent context of a change an agent makes for someone: never the
     * request's IP address or browser, which are the agent's, not the
     * person's; who made it goes into the records' meta.
     */
    protected static function agentContext(string $source = self::CONSENT_SOURCE, ?ConsentBasis $basis = null): ConsentContext
    {
        return new ConsentContext(source: $source, basis: $basis, meta: ['by' => Principal::current()?->name()]);
    }

    /**
     * Who made a revision, an asset or an upload link, for its author
     * column.
     */
    protected static function author(): ?string
    {
        return Principal::current()?->name();
    }

    /**
     * @throws CampaignsException when no subscriber has the address
     */
    protected static function subscriber(string $email): Subscriber
    {
        return Subscriber::findByEmail($email) ?? throw CampaignsException::unknownSubscriber();
    }

    /**
     * Every tool pages what can grow, so an answer past the limit is a bug
     * in the tool, refused rather than handed to an agent that can't read
     * it.
     */
    protected static function withinLimit(ResponseFactory $response): ResponseFactory
    {
        // Response::structured() mirrors the structured content as text, so
        // the longer of the two is what a client reads.
        $length = max(
            $response->responses()->sum(fn (Response $part): int => mb_strlen((string) $part->content())),
            mb_strlen((string) json_encode($response->getStructuredContent() ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );

        if ($length <= static::RESULT_LIMIT) {
            return $response;
        }

        report(new CampaignsException("An MCP tool's answer was {$length} characters long, over the limit of ".static::RESULT_LIMIT.'.'));

        return static::refused('The answer is too long to return. Ask for less at a time: a smaller page, or fewer items.');
    }
}
