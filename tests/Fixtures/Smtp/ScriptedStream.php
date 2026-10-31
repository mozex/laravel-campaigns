<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures\Smtp;

use LogicException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/**
 * An SMTP server's side of the conversation, scripted, for a real
 * EsmtpTransport to talk to without a socket. Each step names the command
 * it answers (CONNECT for the greeting, a command's first word, "." for the
 * end of the email's data) and the reply: a string of lines, or null for a
 * connection that breaks there (the read throws, as a dropped socket's
 * does). A CONNECT step's null is a server that can't be reached. NOOP,
 * RSET and QUIT are answered with 250 or 221 unless the next step names
 * them, since Symfony's own pings, resets and farewells come and go with its
 * timing. A command no step expects fails the test.
 *
 * Symfony's socket stream is final, so this one has the few of its methods
 * EsmtpTransport calls, and talks over memory streams: Symfony's own
 * writing and reading then run as over a socket, its debug log included.
 */
class ScriptedStream extends AbstractStream
{
    /** @var list<string> every command written, in order */
    public array $commands = [];

    /** The email's data as written between DATA and its end. */
    public string $data = '';

    public int $connections = 0;

    /** @var list<array{string, ?string}> */
    protected array $steps;

    /**
     * @param  list<array{string, ?string}>  $steps
     */
    public function __construct(array $steps)
    {
        $this->steps = $steps;
    }

    /**
     * A connection's start: the greeting and EHLO, each answered as given
     * or accepted, up to the first refusal.
     *
     * @param  array<string, ?string>  $replies  replies by command
     * @return list<array{string, ?string}>
     */
    public static function connecting(array $replies = []): array
    {
        return static::steps([
            'CONNECT' => '220 relay.example.com ESMTP',
            'EHLO' => "250-relay.example.com\r\n250 8BITMIME",
        ], $replies);
    }

    /**
     * One email's envelope and data, each answered as given or accepted, up
     * to the first refusal.
     *
     * @param  array<string, ?string>  $replies  replies by command
     * @return list<array{string, ?string}>
     */
    public static function email(array $replies = []): array
    {
        return static::steps([
            'MAIL' => '250 2.1.0 Ok',
            'RCPT' => '250 2.1.5 Ok',
            'DATA' => '354 End data with <CR><LF>.<CR><LF>',
            '.' => '250 2.0.0 Ok: queued as 4ABC123',
        ], $replies);
    }

    /**
     * A connection that sends one email.
     *
     * @param  array<string, ?string>  $replies  replies by command
     * @return list<array{string, ?string}>
     */
    public static function sending(array $replies = []): array
    {
        $connecting = static::connecting($replies);

        return static::refuses($connecting[count($connecting) - 1][1]) ? $connecting : [...$connecting, ...static::email($replies)];
    }

    /**
     * @param  array<string, string>  $accepting
     * @param  array<string, ?string>  $replies
     * @return list<array{string, ?string}>
     */
    protected static function steps(array $accepting, array $replies): array
    {
        $steps = [];

        foreach ($accepting as $command => $accepted) {
            $reply = array_key_exists($command, $replies) ? $replies[$command] : $accepted;
            $steps[] = [$command, $reply];

            if (static::refuses($reply)) {
                break;
            }
        }

        return $steps;
    }

    /**
     * Whether a reply ends the conversation: a refusal or a broken connection.
     */
    protected static function refuses(?string $reply): bool
    {
        return $reply === null || in_array($reply[0] ?? '', ['4', '5'], true);
    }

    public function initialize(): void
    {
        $this->connections++;
        [, $greeting] = $this->next('CONNECT');

        if ($greeting === null) {
            throw new TransportException('Connection could not be established with host "relay.example.com:25".');
        }

        $this->in = fopen('php://memory', 'w+');
        $this->out = fopen('php://memory', 'w+');
        $this->answer($greeting);
    }

    public function write(string $bytes, bool $debug = true): void
    {
        if (! is_resource($this->in)) {
            throw new TransportException('Unable to write bytes on the wire.');
        }

        parent::write($bytes, $debug);

        if (! $debug) {
            $this->data .= $bytes;

            return;
        }

        $command = $bytes === "\r\n.\r\n" ? '.' : strtoupper((string) strtok(trim($bytes), ' :'));
        $this->commands[] = $command;

        $expected = $this->steps[0][0] ?? null;

        if ($expected !== $command && in_array($command, ['NOOP', 'RSET', 'QUIT'], true)) {
            $this->answer($command === 'QUIT' ? '221 2.0.0 Bye' : '250 2.0.0 Ok');

            return;
        }

        [, $reply] = $this->next($command);
        $this->answer($reply);
    }

    public function disableTls(): static
    {
        return $this;
    }

    public function isTLS(): bool
    {
        return false;
    }

    public function startTLS(): bool
    {
        return true;
    }

    public function setHost(string $host): static
    {
        return $this;
    }

    public function setPort(int $port): static
    {
        return $this;
    }

    protected function getReadConnectionDescription(): string
    {
        return 'relay.example.com:25';
    }

    /**
     * The steps no command reached.
     *
     * @return list<string>
     */
    public function remaining(): array
    {
        return array_column($this->steps, 0);
    }

    /**
     * @return array{string, ?string}
     */
    protected function next(string $command): array
    {
        $step = array_shift($this->steps);

        if ($step === null || $step[0] !== $command) {
            throw new LogicException('The script expected '.($step[0] ?? 'nothing')." but got {$command}.");
        }

        return $step;
    }

    /**
     * Puts the reply after whatever is still unread; a null one leaves
     * nothing more to read, so the next read finds the connection closed.
     */
    protected function answer(?string $reply): void
    {
        $unread = is_resource($this->out) ? (string) stream_get_contents($this->out) : '';
        $this->out = fopen('php://memory', 'w+');

        if ($this->out === false) {
            throw new LogicException('No memory stream could be opened.');
        }

        fwrite($this->out, $unread.($reply === null ? '' : $reply."\r\n"));
        rewind($this->out);
    }
}
