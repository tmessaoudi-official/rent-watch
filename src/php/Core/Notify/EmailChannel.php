<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/**
 * Email delivery — the readable record, as asked for on 2026-08-06:
 * *"just filter and show me/email me the list"*.
 *
 * **The transport is injected**, which is the whole design. An earlier version called `mail()`
 * directly and argued that a hand-rolled SMTP client was not worth writing — that argument was
 * wrong on the developer's actual constraint, which is that credentials belong in `.env` and the
 * path has to be exercisable without them. Now: {@see SmtpTransport} speaks the protocol with
 * `.env` credentials, {@see SendmailTransport} hands off to a host MTA, and {@see FileTransport}
 * writes `.eml` files so the entire email path can be run and READ offline.
 *
 * `check()` reports a broken transport before any send, rather than letting the failure surface at
 * the moment a match finally arrives — days later, on the one listing that mattered (Q28).
 */
final readonly class EmailChannel implements Channel
{
    public function __construct(
        private string $to,
        private string $from = 'rent-watch@localhost',
        private string $subjectPrefix = '[rent-watch]',
        private MailTransport $transport = new SendmailTransport(),
    ) {}

    /** For `doctor`, so a misconfigured transport is visible before a match depends on it. */
    public function describe(): string
    {
        return $this->transport->describe();
    }

    /**
     * Delegated, because the answer is a property of the TRANSPORT and not of this channel.
     *
     * `email` reaches a human over SMTP and over a host MTA; over {@see FileTransport} it writes
     * an `.eml` and sends nothing. Round 8 found that difference deciding whether a listing was
     * marked notified for ever — `test-notify` returned 0 for a message that went to a file.
     */
    public function reachesRecipient(): bool
    {
        return $this->transport->reachesRecipient();
    }

    public function name(): string
    {
        return 'email';
    }

    public function check(): ?string
    {
        if (trim($this->to) === '') {
            return 'SMTP_TO is not set, so there is nobody to send to';
        }
        if (filter_var($this->to, FILTER_VALIDATE_EMAIL) === false) {
            return 'SMTP_TO is not a valid address: ' . var_export($this->to, true);
        }
        // The SENDER is checked more loosely than the recipient, deliberately. PHP's filter rejects
        // a dotless domain, so `rent-watch@localhost` — a perfectly legal SMTP sender and the
        // obvious default for a self-hosted tool — failed it, and the shipped default silently
        // DISABLED the email channel. Found by running `test-notify`, which is what that verb is
        // for. The recipient keeps the strict check: a typo there means mail goes nowhere.
        // The `D` modifier is not optional here, for the reason CurlHttpClient's own docblock gives:
        // PHP's `$` matches before a single trailing newline, so `"evil@x.com\n"` would pass this
        // loose check and then reach `MAIL FROM:<…>` as a bare LF on the SMTP command line. `\s`
        // rejects an interior newline, but only `D` rejects a trailing one.
        if (preg_match('~^[^@\s]+@[^@\s]+$~D', $this->from) !== 1) {
            return 'the sender address is not valid: ' . var_export($this->from, true);
        }
        return $this->transport->check();
    }

    public function send(Notification $n): void
    {
        $problem = $this->check();
        if ($problem !== null) {
            throw new ChannelError($this->name(), $problem);
        }

        $subject = self::headerSafe($this->subjectPrefix . ' ' . $n->title);

        $body = $n->title . "\n\n";
        foreach ($n->reasons as $reason) {
            $body .= '- ' . $reason . "\n";
        }
        if ($n->url !== null) {
            $body .= "\n" . $n->url . "\n";
        }
        if ($n->score !== null) {
            $body .= "\nScore : " . $n->score . "/100\n";
        }

        $this->transport->send($this->to, $subject, $body, [
            'From' => self::headerSafe($this->from),
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-RentWatch-Kind' => $n->kind->value,
            'X-RentWatch-Priority' => $n->priority->value,
        ]);
    }

    /**
     * Strip CR and LF from anything that becomes a header.
     *
     * A subject is built from listing text a landlord controls, and an unfiltered newline there is
     * header injection — the classic way an attacker adds a `Bcc:`.
     */
    private static function headerSafe(string $value): string
    {
        return trim(preg_replace('~[\r\n]+~', ' ', $value) ?? '');
    }
}
