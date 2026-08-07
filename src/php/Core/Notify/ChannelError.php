<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

use RentWatch\Core\Redact;

/**
 * A channel could not deliver.
 *
 * Masked at construction, for the same reason {@see \RentWatch\Adapters\SourceError} is: the message
 * naturally carries the endpoint it failed to reach, and an ntfy topic is a secret that travels as a
 * URL path segment. This text is logged and can itself be notified through another channel, so an
 * unmasked variant would leak on the one path guaranteed to be read.
 */
final class ChannelError extends \RuntimeException
{
    /**
     * @param list<string> $literals exact secret values this channel knows about — an ntfy topic
     *                               travels as a URL PATH SEGMENT, so nothing can mask it by name
     */
    public function __construct(
        public readonly string $channelName,
        string $message,
        ?\Throwable $previous = null,
        array $literals = [],
    ) {
        parent::__construct($channelName . ': ' . Redact::text($message, $literals), 0, $previous);
    }
}
