<?php

declare(strict_types=1);

namespace Scout\Adapters\Mail;

/**
 * Reads `.eml` files from a directory. The offline half of the email-alert path.
 *
 * This is the direct equivalent of `FixtureSource` for HTTP, and it exists for the same reason:
 * `spec/PROJECT_BRIEF.md` §11 forbids network in CI, and a parser tested only against a live
 * mailbox is a parser nobody can change safely. It is also how a real alert gets turned into a
 * regression test — save the message, drop it in, and the shape is pinned forever.
 *
 * `docs/PHORJ-REQUIREMENTS.md` asks phorj's `Core.Imap` for exactly this: *"a file-backed test
 * transport mirroring `Mail.FileTransport` — without which Track 2 ships untested."* Same argument,
 * same shape, on this side.
 */
final readonly class FileMailbox implements Mailbox
{
    public function __construct(private string $directory) {}

    /**
     * Always `null` — a directory of frozen fixtures is not a feed.
     *
     * Deliberate, and asserted, rather than inherited. `MAILBOX_DIR=tests/fixtures/rent/<source> scout
     * doctor` is a documented workflow and `doctor` passes a real clock, so a `FileMailbox` that
     * reported its files' `Date` headers would make every fixture run drift into `FEED_SILENT` as
     * the calendar advances — a gate that goes red on a future date with no code change, which is
     * the shape of a test nobody can trust. The fixtures are dated 25–26 August and only get older.
     */
    public function newestMessageAt(): ?string
    {
        return null;
    }

    public function fetchRecent(int $limit = 50): array
    {
        if (!is_dir($this->directory)) {
            throw new MailboxError('no such mailbox directory: ' . $this->directory);
        }

        $files = glob(rtrim($this->directory, '/') . '/*.eml');
        if ($files === false) {
            throw new MailboxError('could not list ' . $this->directory);
        }

        // Newest first, and BY NAME rather than by mtime. A checked-out fixture directory has
        // whatever mtimes git happened to give it, so ordering on mtime would make the test's input
        // order depend on the clone — which is the kind of thing that passes locally and fails in CI.
        rsort($files, SORT_STRING);

        $out = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                throw new MailboxError('could not read ' . $file);
            }
            $out[] = $raw;
        }

        return $out;
    }

    public function describe(): string
    {
        return 'fichiers .eml dans ' . $this->directory;
    }
}
