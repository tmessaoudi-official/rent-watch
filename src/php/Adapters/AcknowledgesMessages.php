<?php

declare(strict_types=1);

namespace Scout\Adapters;

/**
 * A source that can tell its mailbox "the messages I claimed this pass have been processed".
 *
 * Developer request, 2026-09-04, verbatim: *"can you mark the emails in (rent|car)/portails as seen
 * when you process them ! so that way i know which email was processed and which not ??"* The
 * flag is for the HUMAN reading the label, not for the pipeline — the store's seen-set stays the
 * only dedup, and the IMAP query stays date-based (`SINCE` + `FROM`), never `UNSEEN`: the 7-day
 * re-read is what lets a misread card self-heal and what `FEED_SILENT` measures.
 *
 * Three rules, each a place the mark could quietly mean the wrong thing:
 *
 * - **Only a `run` pass acknowledges**, and only AFTER the store has recorded that source's
 *   listings. `doctor` and `tools/dump-eml.php` never do — both stay read-only at the protocol
 *   level, and a diagnostic that marked mail read would make one `doctor` look like a pass.
 * - **Only CLAIMED messages are marked** — the ones that passed this source's sender and subject
 *   filters. A message in the window that no source claims stays unread, which is exactly the one
 *   the developer wants to see: an unmatched sender or template, or a message the
 *   `IMAP_MAX_MESSAGES` cap cut. The flag makes truncation visible for the first time.
 * - **A failed acknowledgement is REPORTED, never swallowed and never fatal to the pass.** The
 *   listings are already recorded and notified; a flag that silently stopped being set would send
 *   the developer looking for a broken source that is fine, so it lands in `RunResult::$errors`.
 *
 * Gated on this interface at the call site — never on `instanceof EmailAlertSource` — for the same
 * reason `CountsPatternMisses` is: a class check is why that report lived on one adapter of five.
 * `PacedSource` forwards it, because under `--watch` every rent source is wrapped and a capability
 * the decorator drops is one production never has (the `FeedFreshness` scar).
 */
interface AcknowledgesMessages
{
    /**
     * Mark every message this source claimed during its last `fetch()` as processed.
     *
     * A no-op when nothing was claimed, or when every claimed message already carries the mark
     * (steady state opens no write session at all).
     *
     * @throws SourceError when the mailbox refused — the caller reports it and carries on
     */
    public function acknowledge(): void;
}
