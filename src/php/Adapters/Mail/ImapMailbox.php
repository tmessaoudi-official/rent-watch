<?php

declare(strict_types=1);

namespace Scout\Adapters\Mail;

use Scout\Core\MutableByDesign;

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
 * **"RECENT" IS A QUERY, NOT THE END OF THE FOLDER — and getting that wrong cost a live source.**
 * `fetchRecent()` used to read the highest `$limit` sequence numbers. On 2026-08-25 the developer
 * widened their Gmail filter to catch five portals and re-labelled a year of archived alert mail
 * into the same label; SeLoger immediately went from **9 listings to 0**, and the only thing that
 * said so was `SourceHealth` (`warn_drop`, 0 against a 7-day mean of 9). Measured on that mailbox:
 * 1436 messages in the folder, of which `SEARCH SINCE` matched 124 — **at sequence numbers 6, 7, 8,
 * 9, 10 and up**, so the tail of the folder contained none of them.
 *
 * Two consequences are baked in below and neither is optional:
 *
 * - the window is chosen by `SEARCH SINCE`, so what counts as recent is the SERVER's answer about
 *   dates rather than this client's assumption about ordering;
 * - the query carries the source's own `FROM`, because one mailbox now serves many portals and a
 *   shared window is a shared budget — without it a busy portal starves a quiet one silently, and
 *   the more sources are added the worse it gets. See {@see searchCommand()}.
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

    /**
     * How many messages one pass reads per source, absent `IMAP_MAX_MESSAGES`.
     *
     * A cost ceiling, not a correctness one: it is also the number of bodies fetched every pass, and
     * `--watch` runs about 96 passes a day. Raising it is not free — see {@see truncationNotice()},
     * which is what makes the trade-off visible instead of leaving it to be discovered.
     */
    public const int DEFAULT_MAX_MESSAGES = 50;

    /** @var resource|null */
    private mixed $socket = null;

    private int $tag = 0;

    /**
     * The newest credible `Date` seen by the last fetch, as an ISO-8601 UTC instant.
     *
     * Per-fetch state on a class that is already {@see MutableByDesign} for exactly this reason — it
     * IS its connection, and this is one more thing the connection learned. See
     * {@see Mailbox::newestMessageAt()} for why the value exists at all.
     */
    private ?string $newestMessageAt = null;

    /**
     * @param string|null $fromFilter the source's own `params.from`, pushed INTO the IMAP query.
     *                                See {@see searchCommand()} for why it is not merely a
     *                                post-fetch filter.
     * @param int         $sinceDays  how far back to look. Seven, matching the source-health rolling
     *                                mean, so the two speak the same unit.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
        private readonly string $folder = 'INBOX',
        private readonly int $port = 993,
        private readonly int $timeoutSeconds = 20,
        private readonly ?string $fromFilter = null,
        private readonly int $sinceDays = 7,
        private readonly ?\DateTimeImmutable $now = null,
        /**
         * Where a non-fatal remark about this fetch goes, or `null` to say nothing.
         *
         * A closure rather than a return value because {@see Mailbox::fetchRecent()} returns the
         * messages and nothing else, and widening that contract for one diagnostic would make every
         * implementation carry it. `Scout` passes its own `warn()`, so the line lands wherever the
         * operator is already looking — `doctor`'s output, or the run banner.
         */
        private readonly ?\Closure $warn = null,
    ) {}

    public function newestMessageAt(): ?string
    {
        return $this->newestMessageAt;
    }

    /**
     * Record a message's `Date`, keeping the newest.
     *
     * **An UNPARSEABLE date is skipped, never treated as now.** A portal that emits a malformed
     * header would otherwise refresh the feed's apparent age on every pass and permanently suppress
     * {@see SourceStatus::FEED_SILENT} — the suppressed direction, which `Core/Heartbeat` rules
     * against. Skipping degrades to `null`, which yields no verdict rather than a false one.
     *
     * Normalised to UTC on the way in so the comparison in `Store::health()` is between instants
     * rather than between strings: portals stamp their own offsets, and `+0200` sorts before `Z`
     * on the same instant.
     */
    private function noteMessageDate(string $raw): void
    {
        $header = EmailMessage::parse($raw)->header('Date');

        if ($header === null || trim($header) === '') {
            return;
        }

        try {
            $at = new \DateTimeImmutable($header);
        } catch (\Exception) {
            return;
        }

        $iso = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        if ($this->newestMessageAt === null || $iso > $this->newestMessageAt) {
            $this->newestMessageAt = $iso;
        }
    }

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

            // ASK THE SERVER WHICH MESSAGES ARE RECENT. Reading the tail of the folder instead was
            // the defect of 2026-08-25 — see the class docblock.
            $matched = self::allSequencesIn(
                $this->command(self::searchCommand($this->fromFilter, $this->sinceDays, $this->now ?? new \DateTimeImmutable())),
            );

            $sequences = $this->capWithNotice($matched, $limit);

            if ($sequences === []) {
                // Nothing in the window. Legitimate for the same reason an empty folder is, and
                // reachable only because SEARCH SUCCEEDED — a failing SEARCH throws in `command()`.
                return [];
            }

            $messages = [];
            $this->newestMessageAt = null;

            foreach ($sequences as $sequence) {
                $raw = $this->fetchMessage($sequence);
                $messages[] = $raw;
                $this->noteMessageDate($raw);
            }

            return $messages;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * The IMAP query that decides which messages this pass even looks at.
     *
     * **`SINCE` is here because the tail of a folder is not "recent".** Measured on the live mailbox
     * 2026-08-25: the folder held 1436 messages, `SEARCH SINCE` two days back matched 124 of them,
     * and their sequence numbers began at **6, 7, 8, 9, 10** — the low end. Fetching the last 50 by
     * sequence therefore returned **zero** of the day's SeLoger alerts, against a seven-day mean of
     * nine, and `SourceHealth` reported `warn_drop` on a source that was publishing normally. The
     * mechanism by which a Gmail label orders its messages is NOT recorded here, because it was not
     * measured — what was measured is that ordering by sequence disagrees with ordering by date, and
     * that INTERNALDATE survives whatever re-labelling does (124 ≠ 1436 proves the server is
     * filtering on something the relabelling did not touch).
     *
     * **`FROM` is here because one mailbox now serves many portals**, and a shared window is a
     * shared budget. The `SINCE` window alone held 124 messages against a limit of 50 — five
     * portals' alerts plus the watcher's own notification emails, which land in the same inbox. So
     * a busy portal starves a quiet one, silently, and it gets worse with every source added. Push
     * the source's own `params.from` into the QUERY and each source gets its own window instead of a
     * slice of one. The post-fetch `from` check in {@see \Scout\Adapters\EmailAlertSource} stays
     * as it was: this makes the fetch cheap and correct, it is not the security boundary.
     *
     * The date is formatted with `date()`'s English month abbreviations, which RFC 3501 requires
     * (`d-Mon-yyyy`) and which are locale-independent by construction. A locale-aware formatter
     * would emit `Aoû` here and the server would either reject the command or, worse, match nothing.
     */
    public static function searchCommand(?string $fromFilter, int $sinceDays, \DateTimeImmutable $now): string
    {
        // At least one day. A zero or negative window would match nothing and read as a quiet
        // market for ever — the failure this whole class of guard exists to refuse.
        $days = max(1, $sinceDays);
        $since = $now->sub(new \DateInterval('P' . $days . 'D'))->format('d-M-Y');

        $command = 'SEARCH SINCE ' . $since;

        if ($fromFilter !== null && $fromFilter !== '') {
            // Through `quote()`, so the CRLF refusal applies: this value reaches the command line.
            $command .= ' FROM ' . self::quote($fromFilter);
        }

        return $command;
    }

    /**
     * The sequence numbers in a `SEARCH` response, highest first, capped at `$limit`.
     *
     * Highest first because the caller's contract is *newest first*, and within a window already
     * narrowed by date and sender that is the best available ordering — the alternative is fetching
     * every match to read its `Date`, which is the cost this query exists to avoid.
     *
     * An untagged `* SEARCH` reply MAY be split across several lines, and a server is free to send
     * one with no numbers at all; both shapes are read here rather than assumed away.
     *
     * @param list<string> $lines the response lines from {@see command()}
     *
     * @return list<int>
     */
    public static function sequencesIn(array $lines, int $limit): array
    {
        return array_slice(self::allSequencesIn($lines), 0, max(0, $limit));
    }

    /**
     * The same sequences, UNCAPPED — the count the window actually matched.
     *
     * Split out from {@see sequencesIn()} so the cap can be counted against the window rather than
     * silently applied to it. `SEARCH SINCE` decides what is recent and the cap decides how much of
     * that is read; the two disagree the moment a portal gets busy, and until this existed nothing
     * anywhere could observe the disagreement.
     *
     * @param list<string> $lines the response lines from {@see command()}
     *
     * @return list<int>
     */
    public static function allSequencesIn(array $lines): array
    {
        $sequences = [];

        foreach ($lines as $line) {
            if (preg_match('~^\*\s+SEARCH\b(.*)$~i', $line, $m) !== 1) {
                continue;
            }

            foreach (preg_split('~\s+~', trim($m[1])) ?: [] as $token) {
                if ($token !== '' && ctype_digit($token)) {
                    $sequences[] = (int) $token;
                }
            }
        }

        rsort($sequences);

        return array_values(array_unique($sequences));
    }

    /**
     * Apply the cap, saying so when it bites.
     *
     * **Extracted from {@see fetchRecent()} so that it can be reached without a socket.** Inline it
     * was three lines behind a live IMAP login, which no test in this tree can perform — and this
     * repo has twice shipped a guarantee whose branch no test entered, in exactly that shape. The
     * arithmetic is pure and covered by {@see truncationNotice()}; what this adds is proof that the
     * notice is actually emitted and that the returned slice is the capped one.
     *
     * @param list<int> $matched every sequence the window held, uncapped
     *
     * @return list<int>
     */
    private function capWithNotice(array $matched, int $limit): array
    {
        $notice = self::truncationNotice(\count($matched), $limit, $this->fromFilter);

        if ($notice !== null && $this->warn !== null) {
            ($this->warn)($notice);
        }

        return \array_slice($matched, 0, max(0, $limit));
    }

    /**
     * How many messages a pass may read, from `IMAP_MAX_MESSAGES` or the default.
     *
     * CLAMPED, never refused, and clamped the same way `IMAP_SINCE_DAYS` is — the two bound the same
     * query, one by date and one by count, so a reader who has learnt that a nonsense value there
     * means "at least one day" would be entitled to assume the same here. Zero is the case worth
     * naming: it would read no messages at all, which is a source reporting a quiet market for ever.
     *
     * @param string|null $configured the raw env value, or `null` when it is unset
     */
    public static function maxMessages(?string $configured): int
    {
        if ($configured === null || trim($configured) === '' || !is_numeric(trim($configured))) {
            return self::DEFAULT_MAX_MESSAGES;
        }

        return max(1, (int) trim($configured));
    }

    /**
     * What to say when the window held more than the cap will read — or `null` when it fits.
     *
     * **This is not a day-to-day loss, which is exactly why it needed saying out loud.** Inside a
     * date-filtered window the highest sequence numbers are the newest messages, so a busy source
     * still reads today's alerts and reports a healthy count; what silently shrinks is the CATCH-UP
     * window, the thing `IMAP_SINCE_DAYS` is set for. Measured on the live mailbox 2026-08-26:
     * SeLoger matched 107 messages in a seven-day window against a cap of 50, so seven days of
     * stated resilience was really about three and a quarter — and it gets worse as the portal gets
     * busier, in a direction nothing reports.
     *
     * Silent at exactly the cap: 50 read out of 50 loses nothing, and a notice that fires on every
     * busy-but-fine source is how a real one stops being read.
     *
     * @param string|null $from the source's own `params.from`, so the reader knows WHOSE window
     *                          this is — one mailbox serves every portal and each has its own
     */
    public static function truncationNotice(int $matched, int $limit, ?string $from): ?string
    {
        if ($matched <= $limit) {
            return null;
        }

        return sprintf(
            'fenêtre IMAP tronquée : %d message(s) de %s dans la fenêtre, %d lus (les plus récents). '
                . 'Les alertes du jour sont lues, mais le rattrapage promis par IMAP_SINCE_DAYS est '
                . 'réduit d\'autant — augmenter IMAP_MAX_MESSAGES (défaut %d) ou réduire IMAP_SINCE_DAYS',
            $matched,
            $from ?? 'cette source',
            $limit,
            self::DEFAULT_MAX_MESSAGES,
        );
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

        // The offline tripwire, on the egress point that matters most: hard rule 4 makes email-alert
        // ingestion the PRIMARY path for private portals, and this sends a cleartext password to a
        // host read from `.env`. A raw socket, so `CurlHttpClient`'s guard never saw it.
        $refusal = \Scout\Core\Offline::refusalForHost($this->host . ':' . $this->port, 'the IMAP server');
        if ($refusal !== null) {
            throw new MailboxError($refusal);
        }

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
        if (preg_match('~[\r\n]~', $value) === 1) {
            // A CR or LF cannot be represented in an IMAP quoted string at all (RFC 3501: a
            // quoted-string excludes CR and LF), so one here would break the command line and
            // could inject a second protocol command. These values are operator-controlled `.env`
            // config, not attacker input — so this is defense-in-depth — but the quoted-string
            // grammar forbids it regardless, and refusing loudly beats emitting a malformed command.
            throw new MailboxError('a CR or LF in an IMAP argument cannot be quoted — refusing to send it');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
