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
        $lines = [];
        foreach ($headers as $name => $value) {
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
