<?php

declare(strict_types=1);

namespace RentWatch\Store;

use RentWatch\Core\RawListing;
use RentWatch\Core\Redact;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceStatus;
use RentWatch\Core\Text;

/**
 * The seen-set, the price history and the run log — everything that must survive between runs.
 *
 * This class carries two of the three data sets `CLAUDE.md` § "Credentials & stateful data" says
 * must not be casually deleted, and both fail silently in the direction that hurts:
 *
 * - Lose the **seen-set** and the next run re-notifies the entire market at once. The user does not
 *   get an error; they get two hundred notifications about flats they already rejected, and they
 *   turn the tool off.
 * - Lose the **price history** and it cannot be reconstructed, because a listing only ever shows
 *   its current rent. A rent drop is a notification-worthy event (spec §7); the evidence that one
 *   happened is exactly the thing that was lost.
 *
 * The **run log** is the third: it is what makes `CLAUDE.md` hard rule 2 enforceable. Without a
 * persisted baseline there is no way to tell a broken selector from a quiet market, and the broken
 * selector is the one that reports success forever.
 *
 * Timestamps are passed in as ISO-8601 strings rather than read from the clock. That is not
 * ceremony: health is a function of run history over time, and a store that reads `now()` internally
 * can only be tested by waiting. The cost of that choice is that a caller can supply nonsense, so
 * every timestamp entering this class is parsed strictly — and {@see health()} takes an optional
 * clock, which is the only thing that can tell a skewed timestamp from a correct one. The run log
 * itself refuses nothing: an earlier version kept it monotonic, and that deleted the runs it
 * rejected rather than fixing anything.
 */
final readonly class Store
{
    /**
     * Bumped whenever the schema changes; {@see migrate()} upgrades from older and refuses newer.
     *
     * Version 2 added `listings.seen_epoch`. It was added at version 1 by mistake, in the very
     * commit that introduced the mismatch refusal — so a database written the day before opened
     * without complaint and then threw a raw `no such column` at the first sighting. That is the
     * whole argument for this constant existing, demonstrated against itself.
     */
    public const int SCHEMA_VERSION = 6;

    /** Spec §8: the mean is rolling over seven days, not over a fixed number of runs. */
    public const int ROLLING_WINDOW_DAYS = 7;

    /** Spec §8: three consecutive empty runs against a non-zero baseline means broken. */
    public const int EMPTY_RUNS_BEFORE_BROKEN = 3;

    /** Spec §8: a drop of more than 70% below the rolling mean warrants a warning. */
    public const float DROP_WARNING_RATIO = 0.3;

    /**
     * Share of failed runs in the window above which a source is flagged flaky.
     *
     * Chosen, not derived — spec §8 does not name a number, and there is no run history to fit one
     * to. Recorded as an open question so it can be tuned against real data rather than defended
     * as if it had been measured.
     */
    public const float FLAKY_FAILURE_RATIO = 0.3;

    /** A window needs at least this many runs before a failure RATE means anything. */
    public const int MIN_RUNS_FOR_FLAKY = 3;

    /**
     * How long a second writer waits before giving up, in milliseconds.
     *
     * `scout run --watch` alongside a manual `scout doctor` is the spec's own target usage, and in
     * the default rollback-journal mode with no timeout the second process fails INSTANTLY with
     * `SQLSTATE[HY000]: General error: 5 database is locked` rather than waiting — reproduced by a
     * reviewer on 2026-08-07, and pinned by {@see StoreTest::testASecondWriterWaitsRatherThanFailing}.
     * The root cause is not "SQLite is flaky": it is that two processes are a designed part of the
     * product, so waiting is the correct behaviour rather than a retry papered over a race.
     */
    public const int BUSY_TIMEOUT_MS = 5000;

    /**
     * A source must have been trying for at least this long before "never produced" means anything.
     *
     * Without it, three successful empty polls at a 15-minute interval told a source onboarded
     * forty-five minutes ago that its field map was probably wrong. In'li LLI stock in one commune
     * is legitimately empty for days.
     *
     * RAISED FROM ONE DAY TO SEVEN on 2026-08-07, after a review demonstrated the one-day value
     * false-accusing a source doing nothing wrong: a new source answering HTTP 200 with zero items,
     * polled every 15 minutes, flipped to `NEVER_PRODUCED` at the 24-hour mark and stayed there. The
     * question that adopted one day (`docs/OPEN-QUESTIONS.md` Q23) had already written down that
     * *"In'li LLI stock in one commune is legitimately empty for days, so the honest floor could be
     * a week"* — and then kept the day anyway. Seven days now matches {@see ROLLING_WINDOW_DAYS},
     * which is the same judgement about how long this market takes to say anything.
     *
     * The trade is stated rather than hidden: a genuinely broken field map on a new source now goes
     * unremarked for a week. That is acceptable only because it is not the ONLY detector — a source
     * that used to produce items and stops is caught in three runs by {@see EMPTY_RUNS_BEFORE_BROKEN},
     * which is the far commoner shape. This one covers the source that never worked at all, where a
     * week of patience costs nothing and a false accusation costs the alert's credibility.
     */
    public const int MIN_SPAN_FOR_NEVER_PRODUCED = 604800;

    private function __construct(
        private \PDO $pdo,
        private string $journalModeInUse,
    ) {}

    /**
     * The journal mode SQLite actually gave us — `wal` on a normal filesystem, `memory` for
     * `:memory:`, `delete` where WAL was refused. Anything but `wal` on a file database means two
     * processes will contend rather than share, so `scout doctor` prints it.
     */
    public function journalMode(): string
    {
        return $this->journalModeInUse;
    }

    /**
     * Open (and create, if needed) the database at `$path`. Pass `:memory:` for a throwaway one.
     *
     * @throws \RuntimeException if the containing directory cannot be created
     */
    public static function open(string $path): self
    {
        if ($path !== ':memory:') {
            $directory = \dirname($path);

            if (!is_dir($directory)) {
                // Checked before `mkdir` rather than after, so the common misconfiguration — a
                // database path whose parent is a regular file — raises this class's own message
                // instead of a PHP warning followed by a confusing PDO error.
                if (file_exists($directory)) {
                    throw new \RuntimeException(sprintf(
                        'le chemin de la base traverse un fichier : %s',
                        $directory,
                    ));
                }

                if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('impossible de créer le répertoire de la base : %s', $directory));
                }
            }
        }

        $pdo = new \PDO('sqlite:' . $path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        // `journal_mode` is a QUERY pragma: it returns the mode that actually took effect, and
        // SQLite does not raise when it refuses. A network mount without shared-memory support
        // silently stays in rollback mode, and `exec()` discards the answer — so the mode is read
        // back and kept, rather than assumed. It is not fatal (`:memory:` legitimately answers
        // `memory`), but it must be visible: `scout doctor` reports it.
        $mode = $pdo->query('PRAGMA journal_mode = WAL');
        $journalMode = (string) $mode->fetchColumn();
        // An unconsumed result set counts as a statement in progress and makes the FIRST COMMIT of
        // the migration below fail with "cannot commit transaction - SQL statements in progress".
        $mode?->closeCursor();

        $store = new self($pdo, $journalMode);
        $store->migrate();

        return $store;
    }

    /**
     * Has this store ever recorded a listing? The fact the Q36 flood guard reads before `scout run`
     * is allowed to notify anything.
     *
     * Q8 rules out GitHub Actions *because* no persistent disk means re-notifying everything, then
     * adopts Docker-on-a-VPS, which has the identical failure mode: a typo in `-v`, or a volume
     * that fails to reattach on reboot, produces a valid, empty, migrated database indistinguishable
     * from a healthy one — and with nothing batched, every historic listing pushes at once.
     *
     * The question used to be "did {@see open()} CREATE this file?", which is a DIFFERENT fact and
     * a fragile one: any earlier command that merely opened the database answered it for good.
     * `scout doctor` opens it, so typing the one command a new machine invites you to type disarmed
     * the guard for the following run. Rows survive that; a per-process flag does not.
     *
     * `:memory:` is deliberately NOT exempt, though it was under the old fact. An in-memory store
     * starts empty on every invocation, so it would re-notify the whole market every pass — that is
     * the flood itself, not an edge case around it.
     */
    public function isSeenSetEmpty(): bool
    {
        // EXISTS rather than COUNT: SQLite stops at the first row instead of walking the table, and
        // the answer is a yes/no anyway.
        return (int) $this->pdo->query('SELECT EXISTS (SELECT 1 FROM listings)')->fetchColumn() === 0;
    }

    /**
     * Create the schema if it is absent. Idempotent — running it twice is a no-op, not an error.
     *
     * An OLDER database is upgraded by {@see upgradeFrom()}; a NEWER one is refused, because this
     * code cannot know what a future version means. Both directions used to refuse, and the older
     * half of that was wrong: `CREATE TABLE IF NOT EXISTS` adds no columns to an existing table and
     * nothing re-stamped `schema_meta`, so refusing was the only signal a user would ever get and
     * they would get it forever, with no path forward.
     *
     * A database with no recorded version at all is treated as version 1 and upgraded, not stamped
     * current — that is the state a crash between the DDL and the version INSERT leaves, and it is
     * indistinguishable from a legacy database.
     */
    public function migrate(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_meta (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS listings (
                dedup_key     TEXT PRIMARY KEY,
                source        TEXT NOT NULL,
                external_id   TEXT NOT NULL,
                url           TEXT,
                title         TEXT NOT NULL,
                rent_cc       INTEGER,
                first_seen_at TEXT NOT NULL,
                last_seen_at  TEXT NOT NULL,
                seen_epoch    INTEGER NOT NULL,
                notified_at   TEXT,
                -- Schema v3 (Q24). A listing stored under an old classifier cannot be re-evaluated
                -- or explained without re-fetching it, and by then the source may have removed the
                -- ad. Combined with the seen-set's "new exactly once" guarantee, a listing digested
                -- as UNKNOWN under a classifier that is later improved is a PERMANENT silent miss —
                -- and Q18 (PLI) and Q21 (shouted PLUS) both route there deliberately, so that bin
                -- will not be small. `scout reclassify` is what consumes these.
                tenure          TEXT,
                confidence_bp   INTEGER,
                signals_json    TEXT,
                -- Schema v4, ruled 2026-08-19. Ties the members of a dedup cluster together so a
                -- flat listed on two portals has ONE readable price history. It is a HISTORY
                -- concept and nothing else: `dedup_key` stays per-source, `price_history` rows stay
                -- attached to the source that observed them, and `notified_at` is never consulted
                -- through the group. A group-scoped notification gate would permanently suppress
                -- the second listing once two members stop clustering — under-merge notifies twice,
                -- which is visible and self-correcting; over-merge hides a flat, which is silent.
                --
                -- NULL means "never clustered under a version that recorded it" and is NOT
                -- backfilled, for the same reason `tenure` is not: a backfilled self-group would be
                -- indistinguishable from a real one, and that is the distinction the queries need.
                group_key       TEXT
            );

            -- `listings_group` is deliberately NOT here, and this is not an oversight. On an
            -- existing v3 database `CREATE TABLE IF NOT EXISTS` skips the table, so `group_key` does
            -- not exist yet and indexing it fails the whole DDL before `upgradeFrom()` can add the
            -- column. It is created in the v4 step instead, after the ALTER — which a brand-new
            -- database also runs, because a fresh store is stamped 1 and upgraded rather than
            -- stamped current.
            CREATE INDEX IF NOT EXISTS listings_source ON listings (source);

            CREATE TABLE IF NOT EXISTS price_history (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                dedup_key TEXT NOT NULL REFERENCES listings (dedup_key),
                rent_cc   INTEGER NOT NULL,
                at        TEXT NOT NULL,
                at_epoch  INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS price_history_key ON price_history (dedup_key, at_epoch, id);

            CREATE TABLE IF NOT EXISTS source_runs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                source     TEXT NOT NULL,
                item_count INTEGER NOT NULL,
                ok         INTEGER NOT NULL,
                error      TEXT,
                at         TEXT NOT NULL,
                at_epoch   INTEGER NOT NULL,
                -- Schema v3 (Q25). Spec §8 specifies `scout doctor` reports status, TIMING and item
                -- counts; three of the four were implemented and the fourth was aspirational.
                duration_ms INTEGER
            );

            CREATE INDEX IF NOT EXISTS source_runs_source ON source_runs (source, at_epoch, id);

            -- Schema v3, ruled 2026-08-07 (Q29). WITHOUT THIS TABLE THE ALERT COOLDOWN HAS NOWHERE
            -- DURABLE TO LIVE, and that is not a storage nicety: in process memory a crash-looping
            -- container re-alerts on every restart, and a manual `scout doctor` shares no state with
            -- the running `--watch`. It cannot be derived from `source_runs` either — that table
            -- records RUNS, not ALERTS, and cannot tell "was broken" from "was told about".
            --
            -- Keyed on (source, status), not on source alone: an escalation from WARN_DROP to BROKEN
            -- must not be swallowed by the earlier, quieter alert.
            CREATE TABLE IF NOT EXISTS source_alerts (
                source   TEXT NOT NULL,
                status   TEXT NOT NULL,
                at       TEXT NOT NULL,
                at_epoch INTEGER NOT NULL,
                PRIMARY KEY (source, status)
            );

            -- Schema v5. What a listing's own detail page said, so it is read ONCE.
            --
            -- Keyed on (source, external_id) and NOT on dedup_key: cache what was OBSERVED, never
            -- what was CONCLUDED. `dedupKey()` normalises, normalisation evolves, and a row keyed
            -- on it would silently orphan the entire cache the day it changes -- which presents as
            -- every listing being re-fetched on every pass, i.e. the crawl this table exists to
            -- prevent, with no symptom but somebody else's access log.
            --
            -- A row appears on the first ATTEMPT, not the first success, because the failure has to
            -- be expressible: fields_json NULL with attempts > 0 is "tried and could not read it",
            -- which is a different fact from "read it and it said nothing" (fields_json '{}'). If
            -- those collapse, a detail page that 404s becomes a listing that merely has no floor,
            -- for ever, while the source's health stays green.
            CREATE TABLE IF NOT EXISTS listing_detail (
                source          TEXT NOT NULL,
                external_id     TEXT NOT NULL,
                url_fetched     TEXT,
                -- The RAW extracted strings, so a ListingMapper improvement reaches rows captured
                -- before it existed. Same reasoning as the stored tenure verdict.
                fields_json     TEXT,
                fetched_at      TEXT,
                attempts        INTEGER NOT NULL DEFAULT 0,
                last_attempt_at TEXT,
                -- Redact::text() applied before it is written: a detail-fetch failure naturally
                -- carries the URL it failed on.
                last_error      TEXT,
                -- Which detail_map produced `fields_json`. A row whose fingerprint no longer
                -- matches the live map reads as ABSENT, so it is refetched through the ordinary
                -- budget and priority path. Without it, adding a field to a map leaves every
                -- already-hydrated row serving the OLD fields for ever -- no refetch, no error, no
                -- signal, and a config that claims to collect what it never will.
                --
                -- NOT part of the primary key, deliberately: keying on it would orphan the whole
                -- cache on every map edit and grow the table one row per map version, where what is
                -- wanted is to refresh the row that exists.
                map_fingerprint TEXT,
                PRIMARY KEY (source, external_id)
            );
            SQL
        );

        $recorded = $this->schemaVersionOrNull();

        if ($recorded === null) {
            $statement = $this->pdo->prepare('INSERT INTO schema_meta (key, value) VALUES (:key, :value)');
            $statement->execute(['key' => 'schema_version', 'value' => (string) '1']);

            // Deliberately stamped 1 and then upgraded, rather than stamped 2 and trusted. A
            // database whose `schema_meta` exists but carries no version row is the state a crash
            // between the DDL and the INSERT leaves — those are two separate autocommit statements
            // — and it is indistinguishable from a legacy v1 database. Stamping it 2 and returning
            // meant `CREATE TABLE IF NOT EXISTS` skipped the existing table, `schemaVersion()`
            // answered 2, and the first sighting threw a raw `no such column`. `upgradeFrom()` is
            // re-runnable by construction, so running it on a genuinely new database costs nothing.
            $recorded = 1;
        }

        if ($recorded === self::SCHEMA_VERSION) {
            return;
        }

        if ($recorded > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'base en version %d, ce code ne connaît que la version %d — mettez le code à jour',
                $recorded,
                self::SCHEMA_VERSION,
            ));
        }

        $this->upgradeFrom($recorded);
    }

    /**
     * Bring an older database up to `SCHEMA_VERSION`, in one transaction.
     *
     * Each step is written so that running it on a database that already has the change is not an
     * error, because a migration that half-applied and then died must be re-runnable — and the data
     * it operates on is the seen-set, which cannot be rebuilt from anywhere.
     */
    private function upgradeFrom(int $recorded): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            if ($recorded < 2) {
                $columns = $this->pdo->query('PRAGMA table_info(listings)');
                $names = array_column($columns->fetchAll(), 'name');

                if (!\in_array('seen_epoch', $names, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN seen_epoch INTEGER NOT NULL DEFAULT 0');
                }

                // Backfill from the timestamp that was already there, so an upgraded database does
                // not treat every stored listing as older than every incoming sighting.
                $rows = $this->pdo->query('SELECT dedup_key, last_seen_at FROM listings WHERE seen_epoch = 0');
                $update = $this->pdo->prepare('UPDATE listings SET seen_epoch = :epoch WHERE dedup_key = :key');

                foreach ($rows->fetchAll() as $row) {
                    try {
                        $epoch = self::epoch((string) $row['last_seen_at']);
                    } catch (\InvalidArgumentException) {
                        // Treat an undateable row as the OLDEST thing on record rather than
                        // refusing to open the database at all. This is not a swallowed error: the
                        // failure mode was demonstrated — one hand-edited or merged row made
                        // `Store::open()` throw permanently, with no repair path, on the one data
                        // set `CLAUDE.md` says cannot be rebuilt. Epoch 0 costs one redundant
                        // overwrite on the next sighting; the alternative costs the seen-set.
                        $epoch = 0;
                    }

                    $update->execute(['epoch' => $epoch, 'key' => (string) $row['dedup_key']]);
                }
            }

            if ($recorded < 3) {
                // Additive only, and every step re-runnable — a migration that half-applied and
                // then died must be safe to run again, because the data underneath is the seen-set
                // and it cannot be rebuilt from anywhere.
                foreach ([
                    'listings' => ['tenure' => 'TEXT', 'confidence_bp' => 'INTEGER', 'signals_json' => 'TEXT'],
                    'source_runs' => ['duration_ms' => 'INTEGER'],
                ] as $table => $columns) {
                    $existing = array_column($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');

                    foreach ($columns as $column => $type) {
                        if (!\in_array($column, $existing, true)) {
                            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
                        }
                    }
                }

                // NOT backfilled, deliberately. There is no honest value for the tenure of a listing
                // classified before the column existed: writing UNKNOWN would be indistinguishable
                // from a real UNKNOWN verdict, and `scout reclassify` selects on exactly that
                // distinction. NULL means "never classified under a version that recorded it", which
                // is the truth and is what makes those rows selectable.
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS source_alerts ('
                    . ' source TEXT NOT NULL, status TEXT NOT NULL, at TEXT NOT NULL,'
                    . ' at_epoch INTEGER NOT NULL, PRIMARY KEY (source, status))',
                );
            }

            if ($recorded < 4) {
                // Additive and re-runnable, like every step above it. NOT backfilled: see the DDL
                // comment on the column — a self-group written here would be indistinguishable from
                // a cluster that genuinely has one member, and `groupPriceHistory()` tells those
                // apart by exactly this NULL.
                $existing = array_column($this->pdo->query('PRAGMA table_info(listings)')->fetchAll(), 'name');

                if (!\in_array('group_key', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN group_key TEXT');
                }

                $this->pdo->exec('CREATE INDEX IF NOT EXISTS listings_group ON listings (group_key)');
            }

            if ($recorded < 5) {
                // Additive and re-runnable, like every step above. Nothing to backfill and nothing
                // that COULD be: a listing already in the seen-set was never hydrated, and an empty
                // row would claim it was read and found bare. Absent is the truth, and it is what
                // makes those listings eligible for the backlog.
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS listing_detail ('
                    . ' source TEXT NOT NULL, external_id TEXT NOT NULL, url_fetched TEXT,'
                    . ' fields_json TEXT, fetched_at TEXT, attempts INTEGER NOT NULL DEFAULT 0,'
                    . ' last_attempt_at TEXT, last_error TEXT,'
                    . ' PRIMARY KEY (source, external_id))',
                );
            }

            if ($recorded < 6) {
                // Additive and re-runnable. SQLite has no `ADD COLUMN IF NOT EXISTS`, so the column
                // list is read first -- a bare ALTER would throw on the second run and turn a
                // re-entrant migration into a fatal one.
                //
                // Existing rows get NULL, which no live fingerprint can equal, so every row
                // captured before this column existed is refetched exactly once. That is the
                // correct default: those rows were captured under an unknown map.
                $columns = $this->pdo->query('PRAGMA table_info(listing_detail)');
                $names = $columns === false ? [] : array_column($columns->fetchAll(\PDO::FETCH_ASSOC), 'name');

                if (!in_array('map_fingerprint', $names, true)) {
                    $this->pdo->exec('ALTER TABLE listing_detail ADD COLUMN map_fingerprint TEXT');
                }
            }

            $stamp = $this->pdo->prepare("UPDATE schema_meta SET value = :value WHERE key = 'schema_version'");
            $stamp->execute(['value' => (string) self::SCHEMA_VERSION]);

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            self::rollBackQuietly($this->pdo);

            throw $failure;
        }
    }

    public function schemaVersion(): int
    {
        $version = $this->schemaVersionOrNull();

        if ($version === null) {
            throw new \RuntimeException('la base n\'a pas de version de schéma — migrate() n\'a jamais tourné');
        }

        return $version;
    }

    // ── Identity ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The stable identity of a listing WITHIN its source (spec §7).
     *
     * `(source, externalId)` when the source publishes an id, otherwise a hash of the normalised
     * `(url, title)`. The fallback is not a nicety: several institutional feeds expose no stable id,
     * and a key that changes between runs makes every listing look new on every run — the
     * notification storm again, arrived at from the other direction.
     *
     * Cross-portal deduplication (the same flat on two sites) is a DIFFERENT problem with a
     * different failure profile and does not belong here.
     *
     * @throws \InvalidArgumentException when the listing carries nothing identifying at all
     */
    public function dedupKey(RawListing $listing): string
    {
        // rawurlencode so neither component can contain the separator and forge another key.
        $source = rawurlencode(self::trimUnicode($listing->sourceName));
        $externalId = self::trimUnicode($listing->externalId);

        if ($externalId !== '') {
            return $source . ':id:' . rawurlencode($externalId);
        }

        $url = self::normaliseUrl($listing->url ?? '');
        $title = self::normaliseText($listing->title);

        // No id, no URL, no title. Every other failure in this class is loud, and this one is in
        // the worse direction: `sha256("\n")` is a perfectly plausible-looking key that EVERY such
        // listing would share, so the second one silently vanishes and the price history of the
        // first interleaves two flats' rents. A listing with nothing identifying is an adapter bug
        // (hard rule 3), and an adapter bug must not be absorbed here.
        if ($url === '' && $title === '') {
            throw new \InvalidArgumentException(sprintf(
                'annonce sans identifiant, sans URL et sans titre chez « %s » — l\'adaptateur n\'a rien extrait',
                $listing->sourceName,
            ));
        }

        return $source . ':h:' . hash('sha256', $url . "\n" . $title);
    }

    // ── Seen-set and price history ────────────────────────────────────────────────────────────────

    /**
     * Record a sighting and report what changed since the last one.
     *
     * `$rentCc` is passed separately from `$listing->rentCc` because the charges-comprises figure is
     * often DERIVED (`rentHc + charges`) by a layer above this one, and the store must compare like
     * with like — `CLAUDE.md` hard rule 9.
     *
     * **Out-of-order sightings are expected, not exceptional.** Email-alert ingestion is the primary
     * path (hard rule 4) and delivers out of publication order routinely — an initial backfill, a
     * provider-delayed alert. A stale sighting therefore never overwrites the current state, and it
     * is not a price drop, whatever the arithmetic says.
     *
     * Two baselines, and they are not interchangeable — see the comment in the body. The DELTA is
     * measured against what we currently believe; the changes-only HISTORY is measured against the
     * chronological neighbour. An earlier version of this docblock argued for using the
     * chronological one for both, and doing that swallowed a real drop; using the stored one for
     * both put a duplicate into a history that cannot be cleaned up. Neither collapse survives.
     *
     * @param string $atIso ISO-8601 timestamp of this sighting
     */
    public function record(RawListing $listing, ?int $rentCc, string $atIso): Sighting
    {
        $key = $this->dedupKey($listing);
        $epoch = self::epoch($atIso);

        // BEGIN IMMEDIATE, not PDO's deferred `beginTransaction()`. A deferred transaction takes
        // the write lock lazily, on its first write — and SQLite deliberately SKIPS the busy handler
        // there, because retrying inside an already-open transaction can only deadlock. So
        // `busy_timeout` did nothing for this method: a second writer failed in 0.2 ms rather than
        // waiting the configured five seconds. The constant claimed a behaviour that did not happen
        // until the test written to demonstrate it went red.
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $existing = $this->pdo->prepare('SELECT seen_epoch, rent_cc FROM listings WHERE dedup_key = :key');
            $existing->execute(['key' => $key]);
            $row = $existing->fetch();

            $isNew = $row === false;
            $isCurrent = $isNew || $epoch >= (int) $row['seen_epoch'];

            // TWO DIFFERENT QUESTIONS, and conflating them swallowed a real drop.
            //
            // "Has the rent changed since what we believe now?" is answered by the stored current
            // rent. "Where does this observation sit in the timeline?" is answered by the
            // chronological neighbours in `price_history` — which is CHANGES-ONLY, so it is not a
            // record of observations and cannot answer the first question. Reading the history for
            // a current sighting made 1000 → (late 900) → 900 report no drop at all, because the
            // backfilled 900 had become the predecessor of the real one.
            // THREE values, and collapsing any two of them has already caused a defect:
            //   $chronoBefore  — the rent recorded immediately BEFORE this instant. Governs the
            //                    changes-only invariant, and nothing else.
            //   $previousRentCc — what we believed the rent was. Governs the delta and the drop.
            // They differ exactly when a sighting arrives out of order, which the email path makes
            // routine. Using the delta's baseline for the append guard put a duplicate 900 into a
            // changes-only history that cannot be cleaned up afterwards.
            $chronoBefore = $this->rentBefore($key, $epoch);
            $previousRentCc = $isCurrent
                ? ($isNew || $row['rent_cc'] === null ? null : (int) $row['rent_cc'])
                : $chronoBefore;

            if ($isNew) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO listings (dedup_key, source, external_id, url, title, rent_cc,
                                           first_seen_at, last_seen_at, seen_epoch)
                     VALUES (:key, :source, :external_id, :url, :title, :rent, :at, :at, :epoch)',
                );
                $insert->execute([
                    'key' => $key,
                    'source' => $listing->sourceName,
                    'external_id' => $listing->externalId,
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'rent' => $rentCc,
                    'at' => $atIso,
                    'epoch' => $epoch,
                ]);
            } elseif (!$isCurrent) {
                // A stale sighting may FILL a hole, never overwrite. A run whose link selector broke
                // followed by a delayed alert that carries the link would otherwise leave the store
                // permanently without one, because the whole UPDATE was skipped — and the notify
                // layer needs the URL and the title to produce anything actionable.
                //
                // `first_seen_at` deliberately does NOT move backwards: it records when WE first saw
                // the listing, which is what a seen-set is for, not when it was published.
                $fill = $this->pdo->prepare(
                    'UPDATE listings
                        SET url     = COALESCE(NULLIF(url, \'\'), NULLIF(:url, \'\'), url),
                            title   = CASE WHEN title = \'\' THEN :title ELSE title END,
                            rent_cc = COALESCE(rent_cc, :rent)
                      WHERE dedup_key = :key',
                );
                $fill->execute([
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'rent' => $rentCc,
                    'key' => $key,
                ]);
            } else {
                // COALESCE on all three, so a run whose field map partially broke does not erase
                // what we already knew. The rent case is the documented one; the url and title
                // cases were the same bug, unnoticed — a single sighting with a missed link
                // selector left the seen-set holding a listing with no URL and no title, which is
                // precisely the pair a notification needs to be actionable.
                $update = $this->pdo->prepare(
                    'UPDATE listings
                        SET last_seen_at = :at,
                            seen_epoch   = :epoch,
                            url          = COALESCE(NULLIF(:url, \'\'), url),
                            title        = COALESCE(NULLIF(:title, \'\'), title),
                            rent_cc      = COALESCE(:rent, rent_cc)
                      WHERE dedup_key = :key',
                );
                $update->execute([
                    'at' => $atIso,
                    'epoch' => $epoch,
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'rent' => $rentCc,
                    'key' => $key,
                ]);
            }

            // Append only when this observation is a CHANGE — different from the rent recorded
            // before it, and not made redundant by the one recorded after it. The second half only
            // matters for out-of-order inserts, and without it the changes-only invariant breaks
            // silently: `priceHistory()` starts returning consecutive identical values.
            if ($rentCc !== null && $rentCc !== $chronoBefore && $rentCc !== $this->rentAfter($key, $epoch)) {
                $history = $this->pdo->prepare(
                    'INSERT INTO price_history (dedup_key, rent_cc, at, at_epoch) VALUES (:key, :rent, :at, :epoch)',
                );
                $history->execute(['key' => $key, 'rent' => $rentCc, 'at' => $atIso, 'epoch' => $epoch]);
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            // The two writes must agree. Half of this — a `listings` row asserting a rent with no
            // matching history row — leaves the one data set that cannot be reconstructed in a
            // state that reads as complete.
            self::rollBackQuietly($this->pdo);

            throw $failure;
        }

        // Null is not zero. An unknown rent on either side yields an unknown delta rather than a
        // reduction — treating `1000 → null` as a 1000-euro cut would fire the loudest notification
        // the system has on the least information it has ever had.
        $delta = ($rentCc !== null && $previousRentCc !== null) ? $rentCc - $previousRentCc : null;

        return new Sighting(
            dedupKey: $key,
            isNew: $isNew,
            isCurrent: $isCurrent,
            rentCc: $rentCc,
            previousRentCc: $previousRentCc,
            rentDeltaCc: $delta,
            // A SUPERSEDED observation is not a price drop, whatever its arithmetic says. A
            // delayed alert carrying an older, intermediate price made the store answer "yes,
            // dropped to 900" for a flat whose current rent it correctly believed to be 1000 — the
            // row was hardened against this and the verdict object was left exposed.
            isPriceDrop: $isCurrent && $delta !== null && $delta < 0,
        );
    }

    /**
     * Every rent this listing has been seen at, oldest first — changes only, append-only.
     *
     * @return list<int>
     */
    public function priceHistory(string $dedupKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT rent_cc FROM price_history WHERE dedup_key = :key ORDER BY at_epoch ASC, id ASC',
        );
        $statement->execute(['key' => $dedupKey]);

        return array_map(static fn (array $row): int => (int) $row['rent_cc'], $statement->fetchAll());
    }

    // ── The cross-portal group (schema v4, ruled 2026-08-19) ──────────────────────────────────────

    /** The group a listing belongs to, or null if it has never clustered with anything. */
    public function groupKey(string $dedupKey): ?string
    {
        $statement = $this->pdo->prepare('SELECT group_key FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        return $row === false || $row['group_key'] === null ? null : (string) $row['group_key'];
    }

    /**
     * Tie the members of a dedup cluster together, and report the group they now share.
     *
     * **The key is STICKY, and that is the whole design.** `Dedup::cluster()` keeps the FIRST item
     * as survivor, ordering is the caller's, and `Core/Pacer` shuffles source order on every pass —
     * so survivorship flips routinely. Minting the key from whoever survived THIS pass would rename
     * the group each time the shuffle changed its mind, and a member that delisted in between would
     * keep a key nobody else carries. That orphaning is exactly the failure the ruling rejected a
     * read-only join for: a flat whose surviving portal changes loses its history at the one seam
     * worth notifying on. So an existing group is adopted, and a new one minted only when no member
     * has one.
     *
     * Two groups that meet are MERGED rather than left to disagree — reachable whenever a third
     * listing corroborates two that never clustered with each other, because `Dedup`'s tolerances
     * are pairwise and transitivity is not guaranteed.
     *
     * A group is not unmade once formed. A persisted union is permanent, which is ruled and accepted: the
     * blast radius is one presentation view, because per-source rows, per-source `priceHistory()`
     * and per-source drop detection are all untouched by it.
     *
     * @param list<string> $memberKeys cluster members, survivor first
     *
     * @throws \InvalidArgumentException if a key was never recorded — grouping a listing the store
     *                                   has never seen would silently create a group of one
     */
    public function assignGroup(array $memberKeys): ?string
    {
        $members = array_values(array_unique($memberKeys));

        // Checked BEFORE the singleton short-circuit, so an unknown key is refused whether or not it
        // happens to have company. A missing row here means the caller recorded nothing, and a group
        // quietly assembled from listings that were never stored is unobservable.
        $existing = [];

        foreach ($members as $key) {
            $statement = $this->pdo->prepare('SELECT group_key FROM listings WHERE dedup_key = :key');
            $statement->execute(['key' => $key]);
            $row = $statement->fetch();

            if ($row === false) {
                throw new \InvalidArgumentException(sprintf(
                    'annonce inconnue, impossible de la regrouper : %s',
                    $key,
                ));
            }

            $existing[$key] = $row['group_key'] === null ? null : (string) $row['group_key'];
        }

        // A cluster of one is not a group. NULL keeps "never clustered" distinguishable from
        // "clustered, and the others have gone" — `groupPriceHistory()` branches on precisely that.
        if (count($members) < 2) {
            return null;
        }

        $adopted = null;

        foreach ($members as $key) {
            if ($existing[$key] !== null) {
                $adopted = $existing[$key];
                break;
            }
        }

        // Minted from the survivor only when nothing to adopt. Any member's key would do; the
        // survivor's is the one the caller already treats as the cluster's representative.
        $adopted ??= $members[0];

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $absorb = $this->pdo->prepare('UPDATE listings SET group_key = :into WHERE group_key = :from');

            foreach (array_unique(array_filter($existing)) as $other) {
                if ($other !== $adopted) {
                    // Every row of the losing group, not just the members in hand — a group that was
                    // only half-merged would report two histories for one flat, and the half left
                    // behind is the half whose portal has already delisted.
                    $absorb->execute(['into' => $adopted, 'from' => $other]);
                }
            }

            $join = $this->pdo->prepare('UPDATE listings SET group_key = :group WHERE dedup_key = :key');

            foreach ($members as $key) {
                $join->execute(['group' => $adopted, 'key' => $key]);
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            self::rollBackQuietly($this->pdo);

            throw $failure;
        }

        return $adopted;
    }

    /**
     * The price history of a listing's whole group, oldest first, each entry naming the source.
     *
     * **A listing with no group reports its OWN history, not an empty one.** Deriving the group with
     * `WHERE group_key = (SELECT group_key FROM listings WHERE dedup_key = :key)` reads correctly
     * and is wrong: SQL's NULL is never equal to NULL, so an ungrouped listing — which is most of
     * them — would match no rows at all and report "no price history" silently.
     *
     * The group is derived by JOIN rather than copied onto `price_history`, so a later merge cannot
     * leave a second copy of the column stale.
     *
     * @return list<array{source: string, rent_cc: int, at: string}>
     */
    public function groupPriceHistory(string $dedupKey): array
    {
        $group = $this->groupKey($dedupKey);

        if ($group === null) {
            $statement = $this->pdo->prepare(
                'SELECT l.source AS source, h.rent_cc AS rent_cc, h.at AS at
                   FROM price_history h
                   JOIN listings l ON l.dedup_key = h.dedup_key
                  WHERE h.dedup_key = :key
                  ORDER BY h.at_epoch ASC, h.id ASC',
            );
            $statement->execute(['key' => $dedupKey]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT l.source AS source, h.rent_cc AS rent_cc, h.at AS at
                   FROM price_history h
                   JOIN listings l ON l.dedup_key = h.dedup_key
                  WHERE l.group_key = :group
                  ORDER BY h.at_epoch ASC, h.id ASC',
            );
            $statement->execute(['group' => $group]);
        }

        return array_map(
            static fn (array $row): array => [
                'source' => (string) $row['source'],
                'rent_cc' => (int) $row['rent_cc'],
                'at' => (string) $row['at'],
            ],
            $statement->fetchAll(),
        );
    }

    /** What the store currently believes about a listing, or null if it has never been seen. */
    public function snapshot(string $dedupKey): ?StoredListing
    {
        $statement = $this->pdo->prepare('SELECT * FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new StoredListing(
            dedupKey: (string) $row['dedup_key'],
            sourceName: (string) $row['source'],
            externalId: (string) $row['external_id'],
            url: $row['url'] === null ? null : (string) $row['url'],
            title: (string) $row['title'],
            rentCc: $row['rent_cc'] === null ? null : (int) $row['rent_cc'],
            firstSeenAt: (string) $row['first_seen_at'],
            lastSeenAt: (string) $row['last_seen_at'],
            notifiedAt: $row['notified_at'] === null ? null : (string) $row['notified_at'],
        );
    }

    /**
     * Whether this listing has ALREADY been pushed to the user.
     *
     * Seen and notified are deliberately different facts: a listing that went to the low-priority
     * *"à vérifier"* digest was seen and not notified, and one whose rent later drops must be able
     * to reach the user even though it is no longer new.
     */
    public function wasNotified(string $dedupKey): bool
    {
        $statement = $this->pdo->prepare('SELECT notified_at FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        return $row !== false && $row['notified_at'] !== null;
    }

    /** @throws \InvalidArgumentException if the key was never recorded — a silent no-op here would re-notify forever */
    public function markNotified(string $dedupKey, string $atIso): void
    {
        self::epoch($atIso);

        $statement = $this->pdo->prepare('UPDATE listings SET notified_at = :at WHERE dedup_key = :key');
        $statement->execute(['at' => $atIso, 'key' => $dedupKey]);

        if ($statement->rowCount() === 0) {
            throw new \InvalidArgumentException(sprintf('annonce inconnue, impossible de la marquer notifiée : %s', $dedupKey));
        }
    }

    // ── Source health (spec §8, `CLAUDE.md` hard rule 2) ───────────────────────────────────────────

    /**
     * Log the outcome of one fetch.
     *
     * A failed fetch is recorded AS a failure, with its error. `CLAUDE.md` hard rule 3 exists
     * because the alternative — catching the exception and recording zero items — turns a loud
     * breakage into a quiet one, and quiet is indistinguishable from a calm rental market.
     *
     * **Every run is logged, whatever its timestamp says.** An earlier version refused a run stamped
     * before one already recorded, on the theory that a run has no legitimate out-of-order case. It
     * made things worse: nothing checks a timestamp against a clock (by design — health can only be
     * tested if time is an argument), so the FIRST forward-skewed run was still accepted, and the
     * refusal then discarded the real runs that followed. Three outright failures went unrecorded
     * and `health()` reported `OK` with `lastFailureAt` null — the freeze survived, and the evidence
     * of it did not. A log that drops entries is not a log.
     *
     * What actually defends against a poisoned timestamp is downstream, in {@see health()}: recency
     * is read from the log's own insertion order, and the rolling MEAN is bounded at both ends so a
     * run dated 2036 cannot inflate it forever. The COUNTS are bounded differently — see
     * {@see windowCounts()}, which has to answer a different question.
     *
     * @throws \InvalidArgumentException on an unreadable timestamp
     */
    public function recordRun(
        string $sourceName,
        int $itemCount,
        bool $ok,
        ?string $error,
        string $atIso,
        ?int $durationMs = null,
    ): void {
        $epoch = self::epoch($atIso);

        $statement = $this->pdo->prepare(
            'INSERT INTO source_runs (source, item_count, ok, error, at, at_epoch, duration_ms)
             VALUES (:source, :count, :ok, :error, :at, :epoch, :duration)',
        );
        $statement->execute([
            'source' => $sourceName,
            'count' => $itemCount,
            'ok' => $ok ? 1 : 0,
            // Schema v3 (Q25). Nullable, because only the CLI can measure a fetch and a caller that
            // does not measure must record `null` rather than a fabricated 0 — a zero-millisecond
            // fetch would read as a suspiciously fast source rather than as an unmeasured one.
            'duration' => $durationMs,
            // An adapter exception naturally carries the request URL or the mailbox it failed on,
            // and this value reaches both the database and the user-facing detail below.
            'error' => Redact::text($error),
            'at' => $atIso,
            'epoch' => $epoch,
        ]);
    }

    /**
     * Derive the current health of a source from its whole run history.
     *
     * `$nowIso` is optional because this class does not read the clock — health must be testable
     * without waiting. Supplying it unlocks the one verdict that cannot be derived from the log
     * alone: `STALE`, a source whose SCHEDULE has stopped. Without it, a source that last ran three
     * hundred days ago is indistinguishable from one that ran this morning.
     */
    // ── Alert cooldown (schema v3, Q29) ───────────────────────────────────────────────────────────

    /**
     * Should this source/status alert be sent, given the cooldown?
     *
     * KEYED ON `(source, status)`, never on source alone. An escalation from `WARN_DROP` to `BROKEN`
     * is a different fact from the warning that preceded it, and keying on the source would let the
     * quieter alert swallow the louder one for a whole day.
     *
     * Persisted rather than held in memory, and that is the point of the table: a crash-looping
     * container re-alerts on every restart, and a manual `scout doctor` shares no state with a
     * running `--watch`. It also cannot be derived from `source_runs`, which records RUNS and cannot
     * distinguish "was broken" from "was told about".
     */
    public function shouldAlert(string $sourceName, string $status, string $nowIso, int $cooldownHours): bool
    {
        $now = self::epoch($nowIso);

        $statement = $this->pdo->prepare(
            'SELECT at_epoch FROM source_alerts WHERE source = :source AND status = :status',
        );
        $statement->execute(['source' => $sourceName, 'status' => $status]);
        $row = $statement->fetch();

        if ($row === false) {
            return true;
        }

        $last = (int) $row['at_epoch'];

        // A future-stamped row means a clock moved backwards, or a hand-edited database. Alerting
        // is the safe direction: the alternative is a source that is silently un-alertable until the
        // clock catches up, which could be indefinitely.
        if ($last > $now) {
            return true;
        }

        return ($now - $last) >= $cooldownHours * 3600;
    }

    /** Record that an alert for this `(source, status)` has just been sent. */
    public function markAlerted(string $sourceName, string $status, string $atIso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO source_alerts (source, status, at, at_epoch)
             VALUES (:source, :status, :at, :epoch)
             ON CONFLICT (source, status) DO UPDATE SET at = :at, at_epoch = :epoch',
        );
        $statement->execute([
            'source' => $sourceName,
            'status' => $status,
            'at' => $atIso,
            'epoch' => self::epoch($atIso),
        ]);
    }

    /**
     * A source is `OK` again: forget every alert we sent about it.
     *
     * Returns whether anything was cleared, which is exactly the condition for sending the ONE
     * recovery notice. Without it a developer who fixes a field map sees nothing and has no
     * confirmation the fix took — and the next, different breakage that day would also be silent,
     * because the cooldown from the old alert would still be running.
     */
    public function clearAlerts(string $sourceName): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM source_alerts WHERE source = :source');
        $statement->execute(['source' => $sourceName]);

        return $statement->rowCount() > 0;
    }

    // ── Detail hydration (schema v5) ──────────────────────────────────────────────────────────────

    /**
     * What is on record about this listing's detail page, or `null` if it was never attempted.
     *
     * `null` and a row with `attempts > 0` are deliberately different answers. The first means the
     * listing is owed a request; the second means one was spent and failed, and the caller decides
     * about a retry from `attempts` and `lastAttemptAt` rather than trying again on every pass for
     * ever.
     */
    public function detail(string $sourceName, string $externalId, ?string $mapFingerprint = null): ?StoredDetail
    {
        $statement = $this->pdo->prepare(
            'SELECT source, external_id, url_fetched, fields_json, fetched_at, attempts,
                    last_attempt_at, last_error, map_fingerprint
             FROM listing_detail WHERE source = :source AND external_id = :id',
        );
        $statement->execute(['source' => $sourceName, 'id' => $externalId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        // A HYDRATED row captured under a different detail_map reads as absent, so the caller
        // refetches it through the ordinary budget and priority path.
        //
        // Scoped to hydrated rows on purpose. A FAILURE row's fingerprint says nothing — no map
        // produced it — and hiding it would discard the attempt count and the backoff with it,
        // turning one permanently-404ing page into a fresh request every single pass. That is the
        // crawl the backoff exists to prevent, and it would arrive disguised as a cache miss.
        if ($mapFingerprint !== null
            && $row['fields_json'] !== null
            && (string) ($row['map_fingerprint'] ?? '') !== $mapFingerprint) {
            return null;
        }

        $raw = $row['fields_json'];
        $fields = null;

        if (\is_string($raw)) {
            /** @var array<string,string> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $fields = $decoded;
        }

        return new StoredDetail(
            sourceName: (string) $row['source'],
            externalId: (string) $row['external_id'],
            urlFetched: $row['url_fetched'] === null ? null : (string) $row['url_fetched'],
            fields: $fields,
            fetchedAt: $row['fetched_at'] === null ? null : (string) $row['fetched_at'],
            attempts: (int) $row['attempts'],
            lastAttemptAt: $row['last_attempt_at'] === null ? null : (string) $row['last_attempt_at'],
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
        );
    }

    /**
     * A detail page was read. Store what it said, verbatim.
     *
     * An EMPTY map is a legitimate success and is stored as `{}`, never as SQL NULL: "the page was
     * read and matched no selector" is a finding, and the difference from "never read" is what
     * stops the listing being re-fetched on every pass for ever.
     *
     * `attempts` still increments, so a page that needed three tries says so.
     *
     * @param array<string,string> $fields the RAW extracted strings
     */
    public function recordDetail(
        string $sourceName,
        string $externalId,
        ?string $urlFetched,
        array $fields,
        string $atIso,
        ?string $mapFingerprint = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO listing_detail
                 (source, external_id, url_fetched, fields_json, fetched_at, attempts, last_attempt_at, last_error, map_fingerprint)
             VALUES (:source, :id, :url, :fields, :at, 1, :at, NULL, :fp)
             ON CONFLICT (source, external_id) DO UPDATE SET
                 url_fetched     = excluded.url_fetched,
                 fields_json     = excluded.fields_json,
                 fetched_at      = excluded.fetched_at,
                 attempts        = listing_detail.attempts + 1,
                 last_attempt_at = excluded.last_attempt_at,
                 last_error      = NULL,
                 map_fingerprint = excluded.map_fingerprint',
        );
        $statement->execute([
            'source' => $sourceName,
            'id' => $externalId,
            'url' => $urlFetched,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'at' => $atIso,
            'fp' => $mapFingerprint,
        ]);
    }

    /**
     * A detail fetch failed. Record the attempt, and do NOT touch any hydration already on record.
     *
     * A source that starts 500ing must not erase what it told us last week — the stored fields stay
     * usable and the failure is recorded beside them. `Redact` is applied by the caller before the
     * text arrives here, because a detail-fetch failure carries the URL it failed on.
     */
    public function recordDetailFailure(
        string $sourceName,
        string $externalId,
        string $error,
        string $atIso,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO listing_detail
                 (source, external_id, url_fetched, fields_json, fetched_at, attempts, last_attempt_at, last_error)
             VALUES (:source, :id, NULL, NULL, NULL, 1, :at, :error)
             ON CONFLICT (source, external_id) DO UPDATE SET
                 attempts        = listing_detail.attempts + 1,
                 last_attempt_at = excluded.last_attempt_at,
                 last_error      = excluded.last_error',
        );
        $statement->execute([
            'source' => $sourceName,
            'id' => $externalId,
            'at' => $atIso,
            'error' => $error,
        ]);
    }

    /**
     * How many listings on a source have failed hydration at least `$minAttempts` times and have
     * never yet succeeded.
     *
     * This is a HEALTH input, not a diagnostic. One detail page that will not load is noise; fifty
     * of a hundred and seventy-four means the landlord changed their detail markup, which is the
     * broken-selector-forever scenario hard rule 2 exists for — and it is invisible in every other
     * signal, because the search page still parses and the count still looks right.
     */
    public function detailFailureCount(string $sourceName, int $minAttempts = 1): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM listing_detail
             WHERE source = :source AND fields_json IS NULL AND attempts >= :min',
        );
        $statement->execute(['source' => $sourceName, 'min' => $minAttempts]);

        return (int) $statement->fetchColumn();
    }

    // ── Tenure verdicts (schema v3, Q24) ──────────────────────────────────────────────────────────

    /**
     * Persist the classifier's verdict alongside the listing, so a past decision can be audited —
     * and, more importantly, REVISED.
     *
     * Q24 asked for auditability and answered only the storage half. The reason this matters more
     * than auditing: the seen-set guarantees a listing is new exactly once, so a listing digested as
     * `UNKNOWN` under a classifier that is later improved is a permanent silent miss. `scout
     * reclassify` re-runs the classifier over these rows, and it can only select the stale ones
     * because {@see staleVerdicts()} knows which were never classified.
     *
     * @param list<string> $signals the `reasons[]`, stored verbatim so the verdict can be explained
     *                              later without re-fetching an ad the source may have removed
     */
    public function recordVerdict(string $dedupKey, string $tenure, int $confidenceBp, array $signals): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE listings SET tenure = :tenure, confidence_bp = :bp, signals_json = :signals
             WHERE dedup_key = :key',
        );
        $statement->execute([
            'tenure' => $tenure,
            'bp' => $confidenceBp,
            'signals' => json_encode($signals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'key' => $dedupKey,
        ]);
    }

    /**
     * Listings whose stored verdict predates a given classifier state, for `scout reclassify`.
     *
     * `tenure IS NULL` is deliberately included: those are rows stored before schema v3, and the
     * migration does NOT backfill them. There is no honest value to backfill with — writing
     * `UNKNOWN` would be indistinguishable from a real UNKNOWN verdict, and this query is exactly
     * the place that distinction has to survive.
     *
     * @param list<string> $tenures which stored verdicts to re-examine, e.g. `['UNKNOWN']`
     *
     * @return list<array{dedup_key: string, source: string, external_id: string, title: string, tenure: ?string}>
     */
    public function staleVerdicts(array $tenures = ['UNKNOWN']): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($tenures) as $i => $tenure) {
            $placeholders[] = ':t' . $i;
            $params['t' . $i] = $tenure;
        }

        $sql = 'SELECT dedup_key, source, external_id, title, tenure FROM listings WHERE tenure IS NULL';
        if ($placeholders !== []) {
            $sql .= ' OR tenure IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY seen_epoch DESC, dedup_key ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array{dedup_key: string, source: string, external_id: string, title: string, tenure: ?string}> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    public function health(string $sourceName, ?string $nowIso = null): SourceHealth
    {
        $statement = $this->pdo->prepare(
            // Insertion order. See below for why it is not the final word.
            'SELECT id, item_count, ok, error, at, at_epoch FROM source_runs
              WHERE source = :source ORDER BY id ASC',
        );
        $statement->execute(['source' => $sourceName]);

        /** @var list<array{item_count:int|string, ok:int|string, error:?string, at:string, at_epoch:int|string}> $runs */
        $runs = $statement->fetchAll();

        if ($runs === []) {
            // Never run is not the same as healthy. A source configured months ago that has never
            // once fired is a configuration bug, and it is invisible because it never fails.
            return new SourceHealth(
                sourceName: $sourceName,
                status: SourceStatus::NEVER_RUN,
                detail: 'aucun run enregistré pour cette source',
            );
        }

        // WHICH RUN IS "THE LAST ONE" — settled, after three rounds of getting it wrong.
        //
        // INSERTION ORDER, always. Not the timestamp, and not the timestamp filtered by a clock.
        // The three candidates fail for different LENGTHS of time, and that is the whole argument:
        //
        //   timestamp, no clock — one future-stamped row sorts last FOREVER and hides every later
        //     run. Twenty consecutive failures reported OK, permanently.
        //   timestamp + clock — a clock only disqualifies rows stamped after `now`. A row skewed by
        //     an hour is fully credible once that hour has passed, and then it hides every real run
        //     logged after it for the duration of the skew. It is also worse than useless when the
        //     CLOCK is the thing that is wrong: an hour-slow clock discarded three real failures and
        //     reported OK, with `totalRuns` counting only the survivors — a silent discard.
        //   insertion order — a run committed late but stamped earlier wins, so one success logged
        //     after three failures reads OK. Two writers make that reachable. A SINGLE such row
        //     self-corrects on the next run; a writer that stamps stale SYSTEMATICALLY keeps the
        //     last-run rule quiet indefinitely, and what catches that is WARN_FLAKY — which is why
        //     `windowCounts()` counts failures whatever their clock says. Both are alerting.
        //
        // Bounded-by-one-run beats bounded-by-the-skew beats unbounded. So the log's own order wins,
        // and `$nowIso` is used for exactly one thing below: STALE, which genuinely cannot be
        // derived without a clock. It never filters, reorders or discards a run.

        $last = $runs[array_key_last($runs)];
        $lastCount = (int) $last['item_count'];
        $lastOk = (int) $last['ok'] === 1;

        $lastSuccessAt = null;
        $lastFailureAt = null;
        $everProduced = false;
        $successfulRuns = 0;

        foreach ($runs as $run) {
            if ((int) $run['ok'] === 1) {
                $lastSuccessAt = (string) $run['at'];
                ++$successfulRuns;
                $everProduced = $everProduced || (int) $run['item_count'] > 0;
            } else {
                $lastFailureAt = (string) $run['at'];
            }
        }

        $epochs = array_map(static fn (array $run): int => (int) $run['at_epoch'], $runs);
        $emptyStreak = self::trailingEmptyRuns($runs);
        $rollingMean = self::rollingMeanBefore($runs, \count($runs) - 1);
        [$runsInWindow, $failedInWindow] = self::windowCounts($runs, $nowIso === null ? null : self::epoch($nowIso));

        $health = static fn (SourceStatus $status, string $detail): SourceHealth => new SourceHealth(
            sourceName: $sourceName,
            status: $status,
            detail: $detail,
            consecutiveEmptyRuns: $emptyStreak,
            lastSuccessAt: $lastSuccessAt,
            lastFailureAt: $lastFailureAt,
            lastCount: $lastCount,
            rollingMean: $rollingMean,
            runsInWindow: $runsInWindow,
            failedRunsInWindow: $failedInWindow,
            totalRuns: \count($runs),
        );

        if (!$lastOk) {
            return $health(SourceStatus::BROKEN, sprintf(
                'dernier run en échec (%s) : %s',
                (string) $last['at'],
                $last['error'] ?? 'erreur non renseignée',
            ));
        }

        if ($emptyStreak >= self::EMPTY_RUNS_BEFORE_BROKEN) {
            $streakStart = \count($runs) - $emptyStreak;
            $baseline = self::rollingMeanBefore($runs, $streakStart);

            if ($baseline === null) {
                // The rolling window before the streak is EMPTY — the machine was off, or the
                // source was disabled, for longer than the window. That is "I do not know what
                // normal looks like", NOT "normal is zero", and letting the two share a branch made
                // a source that broke after any gap longer than the window report OK forever: ten
                // consecutive empty runs against a documented 25-listing history, status OK. So
                // fall back to the last successful run of ANY age.
                $baseline = self::lastProductiveCount($runs, $streakStart);
            }

            if ($baseline > 0.0) {
                return $health(SourceStatus::BROKEN, sprintf(
                    '%d runs consécutifs à vide alors que la référence précédente était de %.1f annonces',
                    $emptyStreak,
                    $baseline,
                ));
            }
        }

        if ($nowIso !== null) {
            // The ONLY use of the clock, and the only verdict that needs one. Silence is measured
            // from the newest run that is not stamped in the future, because a future-stamped row
            // would otherwise make this negative and report a stopped schedule as healthy. Nothing
            // is discarded from `$runs` — the other verdicts still see the whole log.
            $now = self::epoch($nowIso);
            $credible = array_filter($epochs, static fn (int $at): bool => $at <= $now);

            if ($credible === []) {
                return $health(SourceStatus::STALE, 'tous les runs sont horodatés dans le futur — vérifiez l\'horloge de la machine');
            }

            $silentFor = $now - max($credible);

            if ($silentFor > self::ROLLING_WINDOW_DAYS * 86400) {
                return $health(SourceStatus::STALE, sprintf(
                    'aucun run depuis %d jours — la planification a-t-elle cessé ?',
                    intdiv($silentFor, 86400),
                ));
            }
        }

        // max − min over the WHOLE log, not last − first. The rows are in insertion order and
        // `recordRun()` accepts any timestamp, so `last − first` goes NEGATIVE when the first row is
        // forward-skewed — and a negative span can never satisfy the floor below, disabling the
        // wrong-field-map detector permanently. One poisoned row hiding an unbounded number of later
        // ones is the exact shape the rest of this class was rewritten to remove.
        $span = max($epochs) - min($epochs);

        if (!$everProduced && $successfulRuns >= self::EMPTY_RUNS_BEFORE_BROKEN
            && $span >= self::MIN_SPAN_FOR_NEVER_PRODUCED) {
            // Succeeded repeatedly, never returned a single item. The empty-run rule correctly
            // declines to fire (the baseline really is zero) and nothing ever fails, so this hid
            // behind OK. It is the shape a wrong field map takes: HTTP 200, zero parsed items.
            return $health(SourceStatus::NEVER_PRODUCED, sprintf(
                '%d runs réussis sur %d jours, aucune annonce produite — mapping de champs à vérifier',
                $successfulRuns,
                max(1, intdiv($span, 86400)),
            ));
        }

        if ($runsInWindow >= self::MIN_RUNS_FOR_FLAKY && $failedInWindow / $runsInWindow > self::FLAKY_FAILURE_RATIO) {
            return $health(SourceStatus::WARN_FLAKY, sprintf(
                '%d échecs sur %d runs en %d jours',
                $failedInWindow,
                $runsInWindow,
                self::ROLLING_WINDOW_DAYS,
            ));
        }

        if ($rollingMean !== null && $rollingMean > 0.0 && $lastCount < $rollingMean * self::DROP_WARNING_RATIO) {
            return $health(SourceStatus::WARN_DROP, sprintf(
                '%d annonces contre une moyenne de %.1f sur %d jours',
                $lastCount,
                $rollingMean,
                self::ROLLING_WINDOW_DAYS,
            ));
        }

        return $health(SourceStatus::OK, sprintf('%d annonces au dernier run', $lastCount));
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────────

    private function schemaVersionOrNull(): ?int
    {
        // No `$statement === false` guard: `ERRMODE_EXCEPTION` is set at construction, so `query()`
        // throws rather than returning false. Four such branches existed and all four were dead —
        // each fabricating a benign default for a condition that cannot occur, which reads as
        // protection and provides none. The `$row === false` checks after `fetch()` are LIVE and
        // stay: an empty result set is an ordinary outcome.
        $statement = $this->pdo->query("SELECT value FROM schema_meta WHERE key = 'schema_version'");
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['value'];
    }

    /** The last rent recorded at or before `$epoch` — the chronological predecessor, not the last write. */
    private function rentBefore(string $key, int $epoch): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT rent_cc FROM price_history
              WHERE dedup_key = :key AND at_epoch <= :epoch
              ORDER BY at_epoch DESC, id DESC LIMIT 1',
        );
        $statement->execute(['key' => $key, 'epoch' => $epoch]);
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['rent_cc'];
    }

    /** The first rent recorded strictly after `$epoch`, if a later sighting has already landed. */
    private function rentAfter(string $key, int $epoch): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT rent_cc FROM price_history
              WHERE dedup_key = :key AND at_epoch > :epoch
              ORDER BY at_epoch ASC, id ASC LIMIT 1',
        );
        $statement->execute(['key' => $key, 'epoch' => $epoch]);
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['rent_cc'];
    }

    /**
     * How many of the most recent runs succeeded and returned nothing.
     *
     * A FAILED run terminates the streak rather than extending it. An empty run and a failed run are
     * different diagnoses — the source answered and had nothing, versus the source did not answer —
     * and the failure has its own, louder rule above.
     *
     * @param list<array{item_count:int|string, ok:int|string, ...}> $runs
     */
    private static function trailingEmptyRuns(array $runs): int
    {
        $streak = 0;

        foreach (array_reverse($runs) as $run) {
            if ((int) $run['ok'] !== 1 || (int) $run['item_count'] !== 0) {
                break;
            }

            ++$streak;
        }

        return $streak;
    }

    /**
     * Mean item count over the successful runs in the `ROLLING_WINDOW_DAYS` window ending at (and
     * excluding) `$index`. Null when there is nothing in the window to average — which is a
     * different fact from a mean of zero, and callers must treat it as such.
     *
     * @param list<array{item_count:int|string, ok:int|string, at_epoch:int|string, ...}> $runs
     */
    private static function rollingMeanBefore(array $runs, int $index): ?float
    {
        if ($index <= 0 || $index >= \count($runs)) {
            return null;
        }

        $reference = (int) $runs[$index]['at_epoch'];
        $cutoff = $reference - self::ROLLING_WINDOW_DAYS * 86400;
        $counts = [];

        // SKIP rather than stop, and bound the window at BOTH ends. The rows are in insertion order,
        // not timestamp order, so a single out-of-range timestamp would otherwise end the scan early
        // (`break`) or sit inside the window forever (no upper bound) — a run dated 2036 inflating
        // the mean of every later verdict.
        for ($i = $index - 1; $i >= 0; --$i) {
            $at = (int) $runs[$i]['at_epoch'];

            if ($at < $cutoff || $at > $reference) {
                continue;
            }

            if ((int) $runs[$i]['ok'] === 1) {
                $counts[] = (int) $runs[$i]['item_count'];
            }
        }

        return $counts === [] ? null : array_sum($counts) / \count($counts);
    }

    /**
     * Item count of the most recent run before `$index` that actually PRODUCED something, of any
     * age. Zero when the source has never produced anything — used only as the fallback baseline
     * when the rolling window is empty.
     *
     * "Most recent SUCCESSFUL run" was the first attempt and it was one step too shallow: a single
     * successful-but-empty run sitting between the productive history and the streak set the
     * baseline to zero, so a source with a 25-listing history went silent for three runs and
     * reported `OK`. A quiet run is not evidence that nothing is normal here; a productive one is
     * evidence that something was.
     *
     * @param list<array{item_count:int|string, ok:int|string, ...}> $runs
     */
    private static function lastProductiveCount(array $runs, int $index): float
    {
        for ($i = min($index, \count($runs)) - 1; $i >= 0; --$i) {
            if ((int) $runs[$i]['ok'] === 1 && (int) $runs[$i]['item_count'] > 0) {
                return (float) (int) $runs[$i]['item_count'];
            }
        }

        return 0.0;
    }

    /**
     * Total and failed runs within the rolling window ending at the most recent run, inclusive.
     *
     * @param  list<array{ok:int|string, at_epoch:int|string, ...}> $runs
     * @return array{int, int}
     */
    private static function windowCounts(array $runs, ?int $now): array
    {
        // Anchored on the LAST-INSERTED row, deliberately, and NOT on `max(at_epoch)`.
        //
        // Both choices lose the MEAN when a row is forward-skewed, and they lose it for different
        // lengths of time. Anchoring on the last row loses it until the next real run; anchoring on
        // the maximum loses it FOREVER, because the skewed row stays the maximum and every real row
        // sits outside its window. A bounded degradation beats a permanent one, so this reads the
        // log's own order for the same reason `health()` does.
        // THE UPPER EDGE OF THE WINDOW IS "NOW", AND ONLY A CLOCK KNOWS IT.
        //
        // Three attempts, each failing differently, so all three are recorded:
        //   bounded by the last-INSERTED row's stamp — a writer stamping from a lagging `Date:`
        //     header hid eleven consecutive real failures, because every newer-stamped row fell
        //     outside the window and `failedRunsInWindow` read 0 of 11.
        //   unbounded above — a future-stamped row never leaves the window. Twenty successes
        //     stamped a year ahead diluted a genuinely flaky source back to OK; ten failures
        //     stamped a year ahead alerted permanently through ninety healthy days, with a detail
        //     line reading "10 échecs sur 18 runs en 7 jours" when the last seven days held none.
        //   bounded by `$now` — correct in both, because a row stamped after the current time has
        //     not happened yet. That is the whole reason `health()` takes a clock.
        //
        // Without a clock we fall back to the last-inserted stamp: the first failure above, bounded
        // and self-correcting, rather than the second, unbounded in both directions. `CLAUDE.md`
        // requires `scout doctor` to pass one.
        $edge = $now ?? (int) $runs[array_key_last($runs)]['at_epoch'];
        $cutoff = $edge - self::ROLLING_WINDOW_DAYS * 86400;
        $total = 0;
        $failed = 0;

        for ($i = \count($runs) - 1; $i >= 0; --$i) {
            $at = (int) $runs[$i]['at_epoch'];

            if ($at < $cutoff || $at > $edge) {
                continue;
            }

            ++$total;

            if ((int) $runs[$i]['ok'] !== 1) {
                ++$failed;
            }
        }

        return [$total, $failed];
    }

    /**
     * Roll back without letting the rollback's own failure replace the real error.
     *
     * SQLite auto-rolls-back on `SQLITE_FULL`, so `PDO::rollBack()` then throws "There is no active
     * transaction" — and an unguarded call propagates THAT instead of "database or disk is full".
     * Disk-full is precisely the failure where the seen-set stops growing and the next run
     * re-notifies the market, and the operator was being handed a message about transactions.
     */
    private static function rollBackQuietly(\PDO $pdo): void
    {
        try {
            // `exec('ROLLBACK')` to match the `BEGIN IMMEDIATE` above — symmetry, not necessity.
            // An earlier version of this comment claimed `PDO::rollBack()` would throw because PDO
            // tracks only its own transactions. That is FALSE for PDO_SQLITE, which reads the
            // engine's autocommit state: `inTransaction()` is true after `exec('BEGIN IMMEDIATE')`
            // and `rollBack()` works. The correction is recorded rather than silently applied
            // because the previous commit's message claimed to have made it and had not.
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
            // The engine already rolled back — verified in BOTH journal modes: SQLITE_FULL leaves
            // `inTransaction()` false and `rollBack()` throws. (An earlier version of this comment
            // said "under WAL", a claim conditioned on a pragma nothing verified had taken effect.)
            // The caller's exception is the one that matters, and this catch is what lets it survive.
            //
            // There is no `if ($pdo->inTransaction())` fast path here on purpose: it was written,
            // and it turned out to be unreachable-as-a-guarantee — the catch already covers every
            // case it covered, so removing it changed no behaviour and no test. Dead safety code
            // reads as protection and provides none.
        }
    }

    /**
     * Parse an ISO-8601 timestamp, strictly, and always as the instant it names.
     *
     * `new DateTimeImmutable($s)` accepts an empty string as "now" and shrugs at a good deal of
     * nonsense besides. Here the timestamp orders the price history and defines the health window,
     * so a silently-reinterpreted one would corrupt both without any visible symptom.
     *
     * A trailing `Z` is rewritten to `+00:00` rather than matched as a format literal. `\Z` in a
     * `createFromFormat` pattern is decoration: the instant is built in the DEFAULT timezone and
     * the Z is discarded, so on a `Europe/Paris` host — the natural deployment for an
     * Île-de-France tool — `…T10:30:00Z` stored 08:30 UTC. Mixed with a `+00:00` sibling that
     * inverts the price history's order and turns a rise into a drop, and a valid UTC instant
     * inside the spring DST gap was rejected outright, one hour a year.
     *
     * @throws \InvalidArgumentException on anything that is not a full ISO-8601 instant
     */
    private static function epoch(string $iso): int
    {
        $normalised = str_ends_with($iso, 'Z') ? substr($iso, 0, -1) . '+00:00' : $iso;

        // RFC 3339 §4.3 permits `-00:00`; PHP renders the same instant as `+00:00`, so the
        // round-trip below would reject it on spelling alone.
        if (str_ends_with($normalised, '-00:00')) {
            $normalised = substr($normalised, 0, -6) . '+00:00';
        }

        // RFC 3339 `time-secfrac` is `"." 1*DIGIT` — ANY number of digits — and Go's RFC3339Nano
        // trims trailing zeros, so a Go-backed JSON feed emits `.1Z` for `.100`. Padding to the six
        // digits PHP round-trips accepts every width instead of the two that happened to be tried.
        $normalised = preg_replace_callback(
            '~\.(\d+)(?=[+\-]\d{2}:\d{2}$)~',
            // Pad up to six and TRUNCATE beyond it. `{1,6}` accepted widths 1–6 and refused 7–9 —
            // precisely .NET's `o` format (7) and Go's RFC3339Nano at full precision (9), the
            // producer the comment above names. Sub-microsecond precision is not information this
            // store can use, so discarding it is correct rather than lossy.
            static fn (array $m): string => '.' . substr(str_pad($m[1], 6, '0'), 0, 6),
            $normalised,
        ) ?? $normalised;

        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\TH:i:s.uP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $normalised);

            // The round-trip is what makes this strict rather than merely parseable:
            // `createFromFormat` silently normalises `2026-02-30T09:00:00+00:00` to 2 March.
            if ($parsed !== false && $parsed->format($format) === $normalised) {
                return $parsed->getTimestamp();
            }
        }

        throw new \InvalidArgumentException(sprintf('horodatage ISO-8601 illisible : %s', $iso));
    }

    /**
     * Strip leading and trailing whitespace, including the Unicode kind.
     *
     * `trim()` strips seven ASCII bytes and nothing else. U+00A0 — which `Text` itself calls a real
     * adapter artefact, and which a decoded `&nbsp;` produces — survives it, so an `externalId` of
     * one no-break space passed the "does this source publish an id?" test and every listing in the
     * run collapsed onto the SAME key. Over-merging hides a flat entirely, and that flat is then
     * indistinguishable from a quiet market.
     */
    private static function trimUnicode(string $value): string
    {
        $trimmed = preg_replace('/^[\p{Z}\p{C}\s]+|[\p{Z}\p{C}\s]+$/u', '', $value);

        // preg_replace returns null on a PCRE failure, and with the `u` flag the demonstrated cause
        // is invalid UTF-8 — the same input `Text::fold()` refuses with MalformedText.
        //
        // The fallback strips the LATIN-1 spaces as well as the ASCII ones, and that is the whole
        // point of it. A Windows-1252 page is the likeliest encoding accident in this domain, and
        // its `&nbsp;` is the single byte `\xA0`: with a plain `trim()` an id of one such byte was
        // non-empty, so it passed the "does this source publish an id?" test and collapsed every
        // listing in the run onto `:id:%A0` — the exact over-merge the docblock above describes.
        // `\x85` (NEL) and `\xAD` (soft hyphen) are the other two that appear in scraped text.
        return $trimmed ?? trim($value, " \t\n\r\0\x0B\x85\xA0\xAD");
    }

    /** Fold for comparison, falling back to the raw bytes rather than collapsing unreadable input to one key. */
    private static function normaliseText(string $value): string
    {
        $folded = Text::foldTolerant($value);

        if ($folded === null) {
            return $value === '' ? '' : 'brut:' . bin2hex($value);
        }

        return self::trimUnicode($folded);
    }

    /**
     * Normalise a URL for identity: drop the fragment, lowercase the scheme and host.
     *
     * The path and query keep their case, because RFC 3986 makes them case-SENSITIVE and folding
     * them risks merging two distinct listings — and over-merging hides a flat entirely, which is
     * the worse direction of the two. Tracking parameters are deliberately NOT stripped: no source
     * has yet been observed to emit one, and guessing at a strip-list would be inventing a rule
     * against a failure nobody has seen.
     */
    private static function normaliseUrl(string $url): string
    {
        $url = self::trimUnicode($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return self::normaliseText($url);
        }

        $rebuilt = isset($parts['scheme']) ? strtolower($parts['scheme']) . '://' : '//';

        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }

        $rebuilt .= strtolower($parts['host']);
        $rebuilt .= isset($parts['port']) ? ':' . $parts['port'] : '';
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= isset($parts['query']) ? '?' . $parts['query'] : '';

        return $rebuilt;
    }
}
