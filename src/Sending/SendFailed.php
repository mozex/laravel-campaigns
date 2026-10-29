<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Mozex\Campaigns\Enums\SendFailure;
use RuntimeException;

/**
 * A send the provider didn't confirm. Its message never holds a recipient's
 * address and never chains the transport's own exception, whose message
 * quotes the provider's answer: an error tracker sends both.
 */
class SendFailed extends RuntimeException
{
    public function __construct(
        public readonly SendFailure $failure,
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Keeps addresses out of a text that may quote one (a provider's error
     * message), and cuts it to fit a column.
     */
    public static function scrub(string $text, int $max = 200): string
    {
        $text = (string) preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/u', '[address]', $text);
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return mb_strimwidth($text, 0, $max, '...');
    }
}
