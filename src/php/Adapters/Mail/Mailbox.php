<?php

declare(strict_types=1);

namespace Scout\Adapters\Mail;

/**
 * A source of raw alert emails, and the ONE place a test replaces the network.
 *
 * `CLAUDE.md` hard rule 4 makes email-alert ingestion the PRIMARY path for private portals, not a
 * workaround: it is within ToS, it defeats anti-bot entirely because there is no bot, it is FASTER
 * than polling because alerts fire on publication, and it does not break on markup changes.
 *
 * An interface, because credentials and message shape are different problems and only one of them is
 * yours to supply. {@see FileMailbox} reads `.eml` files from a directory, so the whole parse →
 * classify → notify path is testable today; {@see ImapMailbox} speaks the protocol and takes its
 * credentials from `.env`. Swapping one for the other changes no other line.
 */
interface Mailbox
{
    /**
     * Raw RFC-822 messages, newest first.
     *
     * @param int $limit at most this many, so a first run against a full mailbox does not try to
     *                   parse ten thousand messages in one pass
     *
     * @return list<string>
     *
     * @throws MailboxError on ANY failure. Never an empty list to signal one — hard rule 3.
     */
    public function fetchRecent(int $limit = 50): array;

    /** Human-readable description of where this reads from, for `doctor`. Never a credential. */
    public function describe(): string;
}
