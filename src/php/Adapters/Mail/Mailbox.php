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

    /**
     * The `Date` of the newest message the last {@see fetchRecent()} saw, or `null` if unknown.
     *
     * **This is the fact that separates "the portal stopped sending" from "the market is quiet",**
     * and nothing else in the system carries it. Measured 2026-08-28: `leboncoin` had reported a
     * healthy `item_count = 3` on 263 consecutive passes, all of them re-reading ONE message dated
     * 26 August that `SEARCH SINCE 7 days` kept matching. Listing novelty cannot tell those apart —
     * Logirep returns the same 113 listings every pass by design — but a message date can, because
     * it is a statement about what the portal SENT rather than an inference about the market.
     *
     * `null` before any fetch, and `null` from a mailbox that has no meaningful notion of feed
     * freshness. It must never be read as "old": {@see SourceStatus::FEED_SILENT} declines to
     * judge on `null` (hard rule 9).
     */
    public function newestMessageAt(): ?string;

    /**
     * Note that the message at `$position` in the last {@see fetchRecent()} result was CLAIMED by a
     * source — it passed that source's sender and subject filters and was processed as its own.
     *
     * A claim is intent, not a write: nothing reaches the server until {@see acknowledge()}, which
     * the pipeline calls only after the store has recorded the pass. Claims are per fetch and are
     * cleared by the next {@see fetchRecent()}, because the mailbox is built once per source and
     * the watch loop closes over it.
     *
     * @throws \InvalidArgumentException on a position the last fetch did not return — a programming
     *                                   error, and a loud one, because a silently ignored claim is a
     *                                   message that reads as unprocessed for ever
     */
    public function claim(int $position): void;

    /**
     * Mark every claimed message as processed, where the backend has such a notion.
     *
     * {@see ImapMailbox} sets `\Seen` on exactly the claimed UIDs that do not already carry it, in a
     * second read-write session — see its docblock for the invariant this loosens and the rules
     * that bound it. {@see FileMailbox} does nothing: a directory of frozen fixtures has no reader
     * to inform. A no-op when nothing was claimed.
     *
     * @throws MailboxError when the server refused — the caller reports it and carries on; the
     *                      listings are already recorded, so the pass is not failed for a flag
     */
    public function acknowledge(): void;
}
