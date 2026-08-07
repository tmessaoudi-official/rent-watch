<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/**
 * Email delivery — the readable record, as asked for on 2026-08-06:
 * *"just filter and show me/email me the list"*.
 *
 * Uses PHP's `mail()`, which hands off to the host MTA. That is a deliberate limit rather than an
 * oversight: speaking SMTP directly means auth, STARTTLS and a socket state machine, and this
 * project has ZERO Composer dependencies by necessity (the egress policy blocks Composer's dist
 * source), so a hand-rolled SMTP client would be a few hundred lines of security-relevant code
 * written to send one message a day. Under Q8's Docker deployment the MTA is a compose service.
 *
 * `check()` says so plainly when no MTA is reachable, rather than letting `mail()` return false at
 * the moment a match arrives — which is the failure Q28 is about.
 */
final readonly class EmailChannel implements Channel
{
    public function __construct(
        private string $to,
        private string $from = 'rent-watch@localhost',
        private string $subjectPrefix = '[rent-watch]',
    ) {}

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
        if (filter_var($this->from, FILTER_VALIDATE_EMAIL) === false) {
            return 'the sender address is not valid: ' . var_export($this->from, true);
        }
        if (!function_exists('mail')) {
            return 'PHP mail() is unavailable in this build';
        }

        return null;
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

        $headers = [
            'From: ' . self::headerSafe($this->from),
            'Content-Type: text/plain; charset=utf-8',
            'X-RentWatch-Kind: ' . $n->kind->value,
            'X-RentWatch-Priority: ' . $n->priority->value,
        ];

        if (@mail($this->to, $subject, $body, implode("\r\n", $headers)) === false) {
            throw new ChannelError(
                $this->name(),
                'mail() refused the message — no MTA configured, or it rejected the envelope',
            );
        }
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
