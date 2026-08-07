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
 * every timestamp entering this class is parsed strictly and the run log is kept monotonic.
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
    public const int SCHEMA_VERSION = 2;

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
     * A source must have been trying for at least this long before "never produced" means anything.
     *
     * Without it, three successful empty polls at a 15-minute interval told a source onboarded
     * forty-five minutes ago that its field map was probably wrong. In'li LLI stock in one commune
     * is legitimately empty for days.
     */
    public const int MIN_SPAN_FOR_NEVER_PRODUCED = 86400;

    private function __construct(private \PDO $pdo) {}

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
        // `scout run --watch` alongside a manual `scout doctor` is the spec's own target usage, and
        // in the default rollback-journal mode with no timeout the second process fails instantly
        // with "database is locked" rather than waiting. Demonstrated, not assumed.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $store = new self($pdo);
        $store->migrate();

        return $store;
    }

    /**
     * Create the schema if it is absent. Idempotent — running it twice is a no-op, not an error.
     *
     * A database whose recorded version is not exactly `SCHEMA_VERSION` is REFUSED, in both
     * directions. The newer direction is obvious. The older one was the gap: `CREATE TABLE IF NOT
     * EXISTS` adds no columns to a table that already exists, and nothing re-stamped `schema_meta`,
     * so the day `SCHEMA_VERSION` becomes 2 every existing database would have opened as v1
     * forever, with `schemaVersion()` reporting 1 and nothing downstream able to detect it.
     *
     * There is no upgrade path here because there is nothing to upgrade from. Writing version 2
     * means writing that path in this method, and the refusal below is what makes forgetting loud.
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
                notified_at   TEXT
            );

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
                at_epoch   INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS source_runs_source ON source_runs (source, at_epoch, id);
            SQL
        );

        $recorded = $this->schemaVersionOrNull();

        if ($recorded === null) {
            $statement = $this->pdo->prepare('INSERT INTO schema_meta (key, value) VALUES (:key, :value)');
            $statement->execute(['key' => 'schema_version', 'value' => (string) self::SCHEMA_VERSION]);

            return;
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
        $this->pdo->beginTransaction();

        try {
            if ($recorded < 2) {
                $columns = $this->pdo->query('PRAGMA table_info(listings)');
                $names = $columns === false ? [] : array_column($columns->fetchAll(), 'name');

                if (!\in_array('seen_epoch', $names, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN seen_epoch INTEGER NOT NULL DEFAULT 0');
                }

                // Backfill from the timestamp that was already there, so an upgraded database does
                // not treat every stored listing as older than every incoming sighting.
                $rows = $this->pdo->query('SELECT dedup_key, last_seen_at FROM listings WHERE seen_epoch = 0');
                $update = $this->pdo->prepare('UPDATE listings SET seen_epoch = :epoch WHERE dedup_key = :key');

                foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
                    $update->execute([
                        'epoch' => self::epoch((string) $row['last_seen_at']),
                        'key' => (string) $row['dedup_key'],
                    ]);
                }
            }

            $stamp = $this->pdo->prepare("UPDATE schema_meta SET value = :value WHERE key = 'schema_version'");
            $stamp->execute(['value' => (string) self::SCHEMA_VERSION]);

            $this->pdo->commit();
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
     * provider-delayed alert. So the comparison is against the chronologically PRECEDING recorded
     * rent, not against whatever was written last, and a stale sighting never overwrites the
     * current state. Reading `listings.rent_cc` instead manufactured a price-drop notification for
     * a rent that had never moved.
     *
     * @param string $atIso ISO-8601 timestamp of this sighting
     */
    public function record(RawListing $listing, ?int $rentCc, string $atIso): Sighting
    {
        $key = $this->dedupKey($listing);
        $epoch = self::epoch($atIso);

        $this->pdo->beginTransaction();

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
            $previousRentCc = $isCurrent
                ? ($isNew || $row['rent_cc'] === null ? null : (int) $row['rent_cc'])
                : $this->rentBefore($key, $epoch);

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
                        SET url     = COALESCE(url, :url),
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
                            url          = COALESCE(:url, url),
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
            if ($rentCc !== null && $rentCc !== $previousRentCc && $rentCc !== $this->rentAfter($key, $epoch)) {
                $history = $this->pdo->prepare(
                    'INSERT INTO price_history (dedup_key, rent_cc, at, at_epoch) VALUES (:key, :rent, :at, :epoch)',
                );
                $history->execute(['key' => $key, 'rent' => $rentCc, 'at' => $atIso, 'epoch' => $epoch]);
            }

            $this->pdo->commit();
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
            // A SUPERSEDED observation is never a price drop, whatever its arithmetic says. A
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
     * is read from the log's own insertion order, and the rolling window is bounded at both ends so
     * a run dated 2036 cannot sit in it forever.
     *
     * @throws \InvalidArgumentException on an unreadable timestamp
     */
    public function recordRun(string $sourceName, int $itemCount, bool $ok, ?string $error, string $atIso): void
    {
        $epoch = self::epoch($atIso);

        $statement = $this->pdo->prepare(
            'INSERT INTO source_runs (source, item_count, ok, error, at, at_epoch)
             VALUES (:source, :count, :ok, :error, :at, :epoch)',
        );
        $statement->execute([
            'source' => $sourceName,
            'count' => $itemCount,
            'ok' => $ok ? 1 : 0,
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
    public function health(string $sourceName, ?string $nowIso = null): SourceHealth
    {
        $statement = $this->pdo->prepare(
            // ORDER BY id — the log's own order, which is the order the runs actually happened in.
            // Ordering by the caller-supplied timestamp let ONE forward-skewed row (NTP not yet
            // synced after a resume, a VM restored from a snapshot) sort last forever and hide every
            // later run: twenty consecutive failures reported as OK. `at_epoch` still governs the
            // rolling WINDOW below, which is a different question from "what happened most recently".
            'SELECT item_count, ok, error, at, at_epoch FROM source_runs
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

        $emptyStreak = self::trailingEmptyRuns($runs);
        $rollingMean = self::rollingMeanBefore($runs, \count($runs) - 1);
        [$runsInWindow, $failedInWindow] = self::windowCounts($runs);

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
            $silentFor = self::epoch($nowIso) - (int) $last['at_epoch'];

            if ($silentFor > self::ROLLING_WINDOW_DAYS * 86400) {
                return $health(SourceStatus::STALE, sprintf(
                    'aucun run depuis %d jours — la planification a-t-elle cessé ?',
                    intdiv($silentFor, 86400),
                ));
            }
        }

        $span = (int) $last['at_epoch'] - (int) $runs[0]['at_epoch'];

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
        $statement = $this->pdo->query("SELECT value FROM schema_meta WHERE key = 'schema_version'");
        $row = $statement === false ? false : $statement->fetch();

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
    private static function windowCounts(array $runs): array
    {
        $reference = (int) $runs[array_key_last($runs)]['at_epoch'];
        $cutoff = $reference - self::ROLLING_WINDOW_DAYS * 86400;
        $total = 0;
        $failed = 0;

        // Same two rules as rollingMeanBefore(): skip, and bound both ends.
        for ($i = \count($runs) - 1; $i >= 0; --$i) {
            $at = (int) $runs[$i]['at_epoch'];

            if ($at < $cutoff || $at > $reference) {
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
            $pdo->rollBack();
        } catch (\Throwable) {
            // The engine already rolled back — verified: under WAL, SQLITE_FULL leaves
            // `inTransaction()` false and `rollBack()` throws. The caller's exception is the one
            // that matters, and this catch is what lets it survive.
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

        // `.v` is three-digit milliseconds — what every JSON API and `Date.toISOString()` emits, and
        // what the six-digit `.u` round-trip rejected.
        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s.uP'] as $format) {
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
        // is invalid UTF-8 — the same input `Text::fold()` refuses with MalformedText. Falling back
        // to the ASCII trim keeps the bytes, so the id still DISTINGUISHES listings; discarding
        // them would merge every malformed one onto a single key, which is the failure this method
        // exists to prevent.
        return $trimmed ?? trim($value);
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
