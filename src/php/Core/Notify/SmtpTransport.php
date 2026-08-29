<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

use Scout\Core\Offline;

/**
 * SMTP over TLS or STARTTLS, on stream sockets. Credentials come from `.env`.
 *
 * Hand-rolled for the same reason {@see \Scout\Adapters\Mail\ImapMailbox} is: Composer cannot
 * install anything here (the egress policy 403s its dist source), so a library is not an option.
 * The surface is the minimum that delivers one message — `EHLO`, optional `STARTTLS`, `AUTH LOGIN`
 * or `PLAIN`, `MAIL FROM`, `RCPT TO`, `DATA` — because every line of a hand-written protocol client
 * is a line nobody else has reviewed.
 *
 * **THE PASSWORD IS THE HAZARD HERE, and it is handled in three places.** `AUTH LOGIN` sends it
 * base64-encoded, which is not encryption; the server echoes failing commands back; and this class's
 * errors are logged and can themselves be notified through another channel. So: TLS is mandatory
 * before AUTH (never a plaintext credential on the wire), the password is passed to
 * {@see ChannelError} as a literal so it is masked before any pattern rule runs, and the base64 form
 * is masked too — `Redact` carries a whole-line base64 rule for exactly this shape.
 */
final readonly class SmtpTransport implements MailTransport
{
    public function __construct(
        private string $host,
        private int $port = 587,
        private string $user = '',
        private string $password = '',
        private string $from = 'scout@localhost',
        /** `tls` = implicit TLS (port 465), `starttls` = upgrade after EHLO (587), `none` = refused unless local. */
        private string $security = 'starttls',
        private int $timeoutSeconds = 20,
    ) {}

    public function check(): ?string
    {
        if (trim($this->host) === '') {
            return 'SMTP_HOST is not set';
        }
        if (!in_array($this->security, ['tls', 'starttls', 'none'], true)) {
            return 'SMTP_SECURITY must be tls, starttls or none, got ' . var_export($this->security, true);
        }
        if ($this->security === 'none' && $this->user !== '' && !Offline::isLoopbackHost($this->host)) {
            // Refused rather than warned. A credential on a plaintext connection to a remote host is
            // a credential on the wire, and "the user chose it" is not a reason to help.
            //
            // Two things about this condition were wrong until round 7. It called a PRIVATE copy of
            // `isLoopback` that had drifted from `Offline`'s — the copy also admitted `mailhog` and
            // `mailpit`, undocumented strings appearing nowhere else in the repo, so
            // `SMTP_HOST=mailhog SMTP_SECURITY=none` put `AUTH LOGIN` and `SMTP_PASSWORD` on a
            // compose network in the clear, past the guard whose whole subject is that. And it
            // refused on the HOST alone: a local mail catcher needs no AUTH, and with no user there
            // is no credential to expose, so what it was really guarding is the pair.
            return 'SMTP_SECURITY=none is only permitted for a loopback host, or with no SMTP_USER '
                . '— refusing to send credentials in the clear to ' . $this->host;
        }
        if ($this->user !== '' && $this->password === '') {
            return 'SMTP_USER is set but SMTP_PASSWORD is empty';
        }
        if (!function_exists('stream_socket_client')) {
            return 'stream_socket_client is unavailable in this build';
        }

        return null;
    }

    /** YES — it opens a socket to a mail server. */
    public function reachesRecipient(): bool
    {
        return true;
    }

    /**
     * `doctor` reads this, and it must SAY when the mail is leaving in the clear.
     *
     * Round 7 narrowed the `SMTP_SECURITY=none` guard from "refuse for any non-loopback host" to
     * "refuse only when a credential would go with it" — correctly reasoned about credentials, and
     * it replaced a refusal with NOTHING: no warning, no `disabledReport()` entry, no line here.
     * The message body — every notified listing plus the recipient — crosses the internet in clear
     * and the only surface that could say so was this method, which nothing called. Both halves
     * are fixed in the same round: `describe()` is on the `Channel` interface now and `doctor`
     * prints it.
     */
    public function describe(): string
    {
        $line = 'SMTP ' . $this->host . ':' . $this->port . ' (' . $this->security . ')';

        if ($this->security === 'none' && !Offline::isLoopbackHost($this->host)) {
            $line .= ' ⚠ EN CLAIR vers un hôte distant';
        }

        return $line;
    }

    public function send(string $to, string $subject, string $body, array $headers): void
    {
        $problem = $this->check();
        if ($problem !== null) {
            throw new ChannelError('email', $problem, null, $this->secrets());
        }

        // In-transport CR/LF refusal, symmetric with ImapMailbox::quote() and shared with the other
        // transports via Headers. The caller (EmailChannel) sanitises what it builds, but a
        // transport must not depend on that — a CR or LF in the envelope (`MAIL FROM`/`RCPT TO`) or
        // a header line would inject a second SMTP command or header (Bcc, an extra RCPT).
        Headers::assertNoCrlf('recipient', $to);
        Headers::assertNoCrlf('sender', $this->from);
        Headers::assertNoCrlf('subject', $subject);
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            // The NAME is checked with a fixed label — a CRLF-bearing name must not be echoed back
            // into the error — and only then reused as the label for its own value, by which point
            // it is known clean.
            Headers::assertNoCrlf('a header name', $name);
            Headers::assertNoCrlf('header ' . $name, (string) $value);
        }

        $socket = $this->connect();

        try {
            $this->expect($socket, 220);
            $this->say($socket, 'EHLO scout');
            $capabilities = $this->expect($socket, 250);

            if ($this->security === 'starttls') {
                if (stripos($capabilities, 'STARTTLS') === false) {
                    // FAIL CLOSED. A server that does not offer STARTTLS while we are configured for
                    // it is either misconfigured or being impersonated, and continuing would send
                    // the password in the clear — the exact thing the setting asked to prevent.
                    throw new ChannelError('email', $this->host . ' does not offer STARTTLS', null, $this->secrets());
                }

                $this->say($socket, 'STARTTLS');
                $this->expect($socket, 220);

                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new ChannelError('email', 'the TLS handshake with ' . $this->host . ' failed', null, $this->secrets());
                }

                // Re-issued after the upgrade, per RFC 3207: capabilities before and after TLS may
                // differ, and AUTH is commonly advertised only afterwards.
                $this->say($socket, 'EHLO scout');
                $this->expect($socket, 250);
            }

            if ($this->user !== '') {
                $this->authenticate($socket);
            }

            $this->say($socket, 'MAIL FROM:<' . $this->from . '>');
            $this->expect($socket, 250);
            $this->say($socket, 'RCPT TO:<' . $to . '>');
            $this->expect($socket, 250);
            $this->say($socket, 'DATA');
            $this->expect($socket, 354);

            $message = 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n";
            foreach ($headers as $name => $value) {
                $message .= $name . ': ' . $value . "\r\n";
            }
            $message .= "\r\n" . self::dotStuff($body) . "\r\n.";

            $this->say($socket, $message);
            $this->expect($socket, 250);

            $this->say($socket, 'QUIT');
        } finally {
            @fclose($socket);
        }
    }

    /** @return resource */
    private function connect(): mixed
    {
        $errno = 0;
        $errstr = '';
        $scheme = $this->security === 'tls' ? 'tls://' : 'tcp://';

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]]);

        // The offline tripwire, on the second of five egress points. This one opens a raw socket, so
        // it never passed `CurlHttpClient`'s funnel — and it sends `AUTH LOGIN` with `SMTP_PASSWORD`
        // to a host read from `.env`. See `Core\Offline`, which claimed to cover "every outbound
        // request" while covering two of the five.
        $refusal = Offline::refusalForHost($this->host . ':' . $this->port, 'the SMTP server');
        if ($refusal !== null) {
            throw new ChannelError('email', $refusal);
        }

        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new ChannelError(
                'email',
                sprintf('could not connect to %s:%d — %s', $this->host, $this->port, $errstr),
                null,
                $this->secrets(),
            );
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        return $socket;
    }

    /** @param resource $socket */
    private function authenticate(mixed $socket): void
    {
        $this->say($socket, 'AUTH LOGIN');
        $this->expect($socket, 334);
        $this->say($socket, base64_encode($this->user));
        $this->expect($socket, 334);
        $this->say($socket, base64_encode($this->password));
        // 235 = authenticated. Anything else and the server will echo the command it rejected, which
        // is why `$this->secrets()` carries the base64 form as well as the plaintext.
        $this->expect($socket, 235);
    }

    /** @param resource $socket */
    private function say(mixed $socket, string $line): void
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new ChannelError('email', 'could not write to the SMTP connection', null, $this->secrets());
        }
    }

    /**
     * Read a full response and require the expected code.
     *
     * @param resource $socket
     */
    private function expect(mixed $socket, int $code): string
    {
        $full = '';

        while (true) {
            $line = @fgets($socket, 8192);
            $meta = stream_get_meta_data($socket);

            if ($meta['timed_out']) {
                throw new ChannelError('email', 'the SMTP connection timed out', null, $this->secrets());
            }
            if ($line === false) {
                throw new ChannelError('email', 'the SMTP connection closed unexpectedly', null, $this->secrets());
            }

            $full .= $line;

            // A multi-line reply has a `-` after the code; the last line has a space.
            if (preg_match('~^(\d{3})([ -])~', $line, $m) === 1 && $m[2] === ' ') {
                if ((int) $m[1] !== $code) {
                    throw new ChannelError(
                        'email',
                        sprintf('SMTP expected %d, got: %s', $code, trim($full)),
                        null,
                        $this->secrets(),
                    );
                }

                return $full;
            }
        }
    }

    /**
     * Every secret value this class could leak, in every form it travels in.
     *
     * The base64 forms are included because that is literally what goes on the wire and what a
     * server echoes back in a rejection — masking only the plaintext would leave the actual
     * transmitted credential in the error text.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        $out = [];

        foreach ([$this->password, $this->user] as $value) {
            if (trim($value) !== '') {
                $out[] = $value;
                $out[] = base64_encode($value);
            }
        }

        return $out;
    }

    /**
     * RFC 5321 transparency: a line that begins with `.` gets a second one.
     *
     * Without it, a listing description whose line happens to start with a full stop ENDS THE
     * MESSAGE — the rest is interpreted as SMTP commands. Rare, entirely attacker-influenceable
     * (the text comes from a landlord's ad), and it truncates the notification silently.
     */
    private static function dotStuff(string $body): string
    {
        return preg_replace('~^\.~m', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $body)) ?? $body;
    }

}
