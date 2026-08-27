<?php

declare(strict_types=1);

namespace Scout\Adapters\Mail;

use Scout\Core\Redact;

/**
 * A mailbox could not be read.
 *
 * Masked at construction. IMAP is the worst offender for credential leakage in error text: the
 * protocol's own failure responses echo the command that failed, and `A001 LOGIN user hunter2` is a
 * command. `Redact` carries dedicated `LOGIN` / `PASS` / `AUTHENTICATE` rules for exactly this, and
 * they fail CLOSED — they mask unless the following token is recognised prose.
 */
final class MailboxError extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct((string) Redact::text($message), 0, $previous);
    }
}
