<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/**
 * How an email actually leaves. The seam that lets `.env` swap a real server for a fake one.
 *
 * `EmailChannel` used PHP's `mail()` and nothing else, which meant it could only work where a host
 * MTA was configured — and could only be TESTED where one was. An interface costs one small class
 * and makes the whole email path exercisable offline, which is the same trade `Mailbox` and
 * `HttpClient` make on the receiving side.
 */
interface MailTransport
{
    /**
     * @param array<string,string> $headers
     *
     * @throws ChannelError on any delivery failure. NEVER silently — an unsent notification is the
     *                      one failure this project cannot tolerate quietly.
     */
    public function send(string $to, string $subject, string $body, array $headers): void;

    /** Why this transport cannot be used, or `null`. Checked before any send. */
    public function check(): ?string;

    /** For `doctor`. Never a credential. */
    public function describe(): string;
}
