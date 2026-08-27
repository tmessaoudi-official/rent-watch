<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

use Scout\Core\Redact;

/**
 * A transport failure — not a non-2xx status, which is an answer.
 *
 * Masked at construction for the same reason every other error type in this project is: the message
 * naturally carries the request URL, and the IDFM key travels as a query parameter. This text
 * reaches `source_runs.error` and from there a user-facing health detail.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct((string) Redact::text($message), 0, $previous);
    }
}
