<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

/**
 * Shared header/protocol-line hygiene for the mail transports.
 *
 * Every {@see MailTransport} builds header lines as `name: value` and, for SMTP, envelope commands,
 * from caller-supplied strings. A CR or LF in any of them injects a second header or command — a
 * `Bcc`, an extra `RCPT`. {@see EmailChannel}, the sole in-app caller, already sanitises the subject
 * and From and validates the recipient; but a transport must not depend on its caller, so each one
 * applies this guard at its own boundary. Keeping the check here rather than copied into three
 * transports is what makes the discipline symmetric — the asymmetry a review flagged when only
 * SmtpTransport carried its own copy.
 */
final readonly class Headers
{
    public static function assertNoCrlf(string $label, string $value): void
    {
        if (preg_match('~[\r\n]~', $value) === 1) {
            // $label is a caller-fixed field name (or a header name already verified clean by the
            // caller), NEVER the offending value — so nothing raw is echoed into the error, which a
            // review found the earlier per-transport copy doing for a CRLF-bearing header name.
            throw new ChannelError(
                'email',
                'a CR or LF in the ' . $label . ' would inject a header or command — refusing to send',
            );
        }
    }
}
