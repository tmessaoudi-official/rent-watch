<?php

declare(strict_types=1);

namespace RentWatch\Adapters\Mail;

use RentWatch\Core\MutableByDesign;

/**
 * IMAP over TLS, hand-rolled on stream sockets.
 *
 * **Hand-rolled because there is no choice, not because it is a good idea.** `ext-imap` is absent
 * from this container and PHP unbundled it in 8.4; Composer cannot install a library here at all
 * (the egress policy 403s Composer's dist source, which is why this project has zero dependencies).
 * `stream_socket_client` with `tls://` is what is left. The surface is kept as small as the job
 * allows — enough to log in, select a mailbox, list recent messages and read them — because every
 * line of a hand-written protocol client is a line nobody else has reviewed.
 *
 * **READ-ONLY, and enforced rather than intended.** `EXAMINE`, not `SELECT`, so the server itself
 * refuses any modification; nothing here can flag, move or delete a message. The mailbox is the
 * developer's, it is where the alerts land, and a parser bug must not be able to cost them.
 *
 * `docs/PHORJ-REQUIREMENTS.md` asks phorj for a `Core.Imap` with the same shape. This is the PHP
 * half of that, and the two will read the same `.eml` fixtures.
 */
final class ImapMailbox implements Mailbox, MutableByDesign
{
    // MutableByDesign, and it clears that interface's bar rather than borrowing it. A protocol
    // client IS its connection state: an open socket and a monotonically increasing command tag,
    // both of which change as it works and neither of which can be a `readonly` property. It is a
    // collaborator constructed and handed a job, never a value returned from a computation — so
    // nothing downstream can hold it and rewrite a verdict, which is what that rule protects.
    //
    // The loophole is deliberately NOT taken: wrapping the socket in a `readonly` property holding
    // a mutable object would satisfy reflection and defeat the check.

    /** A response line longer than this is not a mailbox we understand. */
    private const int MAX_LINE_BYTES = 65536;

    /** A single message larger than this is not a listing alert. */
    private const int MAX_MESSAGE_BYTES = 4 * 1024 * 1024;

    /** @var resource|null */
    private mixed $socket = null;

    private int $tag = 0;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
        private readonly string $folder = 'INBOX',
        private readonly int $port = 993,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function describe(): string
    {
        // The user is shown because `doctor` has to be able to say WHICH mailbox is misconfigured,
        // and it is not a secret. The password is never here, and never reaches a message.
        return 'IMAP ' . $this->user . '@' . $this->host . ':' . $this->port . ' (' . $this->folder . ')';
    }

    public function fetchRecent(int $limit = 50): array
    {
        $this->connect();

        try {
            $this->command('LOGIN ' . self::quote($this->user) . ' ' . self::quote($this->password));

            // EXAMINE, not SELECT. Read-only at the PROTOCOL level, so no bug on this side can
            // modify the developer's mailbox.
            $select = $this->command('EXAMINE ' . self::quote($this->folder));

            $total = 0;
            foreach ($select as $line) {
                if (preg_match('~^\*\s+(\d+)\s+EXISTS~i', $line, $m) === 1) {
                    $total = (int) $m[1];
                }
            }

            if ($total === 0) {
                // An EMPTY mailbox is a legitimate answer, not a failure — nothing has arrived. It
                // is the one case where returning [] is right, and it is distinguishable from a
                // failure because every failure path above and below throws.
                return [];
            }

            $first = max(1, $total - $limit + 1);
            $messages = [];

            for ($sequence = $total; $sequence >= $first; --$sequence) {
                $messages[] = $this->fetchMessage($sequence);
            }

            return $messages;
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';

        $context = stream_context_create(['ssl' => [
            // Non-negotiable. An IMAP session carries the password in cleartext inside the TLS
            // tunnel, so a tunnel nobody verified is no tunnel at all.
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]]);

        $socket = @stream_socket_client(
            'tls://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new MailboxError(sprintf('could not connect to %s:%d — %s', $this->host, $this->port, $errstr));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        $this->socket = $socket;

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new MailboxError('unexpected IMAP greeting: ' . $greeting);
        }
    }

    private function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }

        // Best effort — the connection is being torn down either way, and a failure to say LOGOUT
        // politely must not mask the real error that brought us here.
        @fwrite($this->socket, 'ZZZZ LOGOUT' . "\r\n");
        @fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Send a tagged command and read until its completion line.
     *
     * @return list<string> every untagged line, in order
     */
    private function command(string $command): array
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $tag = sprintf('A%04d', ++$this->tag);

        if (@fwrite($this->socket, $tag . ' ' . $command . "\r\n") === false) {
            throw new MailboxError('could not write to the IMAP connection');
        }

        $lines = [];

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                $rest = substr($line, strlen($tag) + 1);
                if (!str_starts_with(strtoupper($rest), 'OK')) {
                    // The server echoes the failing command in its response, which for LOGIN means
                    // the password. `MailboxError` masks at construction, and `Redact`'s LOGIN rule
                    // fails CLOSED — it masks unless what follows is recognised prose.
                    throw new MailboxError('IMAP command failed: ' . $rest);
                }

                return $lines;
            }

            $lines[] = $line;
        }
    }

    private function fetchMessage(int $sequence): string
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $tag = sprintf('A%04d', ++$this->tag);
        if (@fwrite($this->socket, $tag . ' FETCH ' . $sequence . ' BODY.PEEK[]' . "\r\n") === false) {
            throw new MailboxError('could not write to the IMAP connection');
        }

        $body = '';

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with(strtoupper(substr($line, strlen($tag) + 1)), 'OK')) {
                    throw new MailboxError('IMAP FETCH failed: ' . substr($line, strlen($tag) + 1));
                }

                return $body;
            }

            // A literal: `* 12 FETCH (BODY[] {2048}` means exactly 2048 bytes follow.
            if (preg_match('~\{(\d+)\}$~', $line, $m) === 1) {
                $length = (int) $m[1];

                if ($length > self::MAX_MESSAGE_BYTES) {
                    throw new MailboxError(sprintf(
                        'message %d is %d bytes, over the %d-byte limit — refusing to read it',
                        $sequence,
                        $length,
                        self::MAX_MESSAGE_BYTES,
                    ));
                }

                $body = $this->readExactly($length);
            }
        }
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $line = @fgets($this->socket, self::MAX_LINE_BYTES);
        $meta = stream_get_meta_data($this->socket);

        if ($meta['timed_out']) {
            throw new MailboxError('the IMAP connection timed out');
        }
        if ($line === false) {
            throw new MailboxError('the IMAP connection closed unexpectedly');
        }

        return rtrim($line, "\r\n");
    }

    private function readExactly(int $length): string
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $body = '';

        while (strlen($body) < $length) {
            $chunk = @fread($this->socket, $length - strlen($body));

            if ($chunk === false || $chunk === '') {
                throw new MailboxError('the IMAP connection closed while reading a message body');
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * IMAP quoted-string, with the two characters the grammar requires escaping.
     *
     * A password containing `"` or `\` is otherwise a protocol-level injection: the server would
     * read the rest of the password as further command arguments. Perfectly ordinary in a
     * generated app password.
     */
    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
