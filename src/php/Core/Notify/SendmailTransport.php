<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/** PHP's `mail()`, handing off to the host MTA. The right default under Docker with an MTA service. */
final readonly class SendmailTransport implements MailTransport
{
    public function check(): ?string
    {
        return function_exists('mail') ? null : 'PHP mail() is unavailable in this build';
    }

    public function describe(): string
    {
        return 'mail() via le MTA de l\'hôte';
    }

    public function send(string $to, string $subject, string $body, array $headers): void
    {
        // Self-protect at the boundary, symmetric with SmtpTransport — `mail()` is the most
        // injection-prone sink of the three, so a CR/LF here must never reach it even though the
        // EmailChannel caller already sanitises.
        Headers::assertNoCrlf('recipient', $to);
        Headers::assertNoCrlf('subject', $subject);

        $lines = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            Headers::assertNoCrlf('a header name', $name);
            Headers::assertNoCrlf('header ' . $name, (string) $value);
            $lines[] = $name . ': ' . $value;
        }

        if (@mail($to, $subject, $body, implode("\r\n", $lines)) === false) {
            throw new ChannelError(
                'email',
                'mail() refused the message — no MTA configured, or it rejected the envelope',
            );
        }
    }
}
