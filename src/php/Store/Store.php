<?php

declare(strict_types=1);

namespace RentWatch\Store;

use RentWatch\Core\RawListing;
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
 * can only be tested by waiting.
 */
final readonly class Store
{
    /** Bumped whenever the schema changes; {@see migrate()} refuses to open a NEWER database. */
    public const int SCHEMA_VERSION = 1;

    /** Spec §8: the mean is rolling over seven days, not over a fixed number of runs. */
    public const int ROLLING_WINDOW_DAYS = 7;

    /** Spec §8: three consecutive empty runs against a non-zero baseline means broken. */
    public const int EMPTY_RUNS_BEFORE_BROKEN = 3;

    /** Spec §8: a drop of more than 70% below the rolling mean warrants a warning. */
    public const float DROP_WARNING_RATIO = 0.3;

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

            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('impossible de créer le répertoire de la base : %s', $directory));
            }
        }

        $pdo = new \PDO('sqlite:' . $path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');

        $store = new self($pdo);
        $store->migrate();

        return $store;
    }

    /**
     * Create the schema if it is absent. Idempotent — running it twice is a no-op, not an error.
     *
     * A database written by a NEWER version of the code is refused rather than used. Silently
     * operating on a schema you do not understand is how a seen-set gets corrupted, and the cost of
     * that is the notification storm described in this class's docblock.
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

        if ($recorded > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'base en version %d, ce code ne connaît que la version %d — mise à jour du code requise',
                $recorded,
                self::SCHEMA_VERSION,
            ));
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
     */
    public function dedupKey(RawListing $listing): string
    {
        // rawurlencode so neither component can contain the separator and forge another key.
        $source = rawurlencode(trim($listing->sourceName));
        $externalId = trim($listing->externalId);

        if ($externalId !== '') {
            return $source . ':id:' . rawurlencode($externalId);
        }

        $fingerprint = self::normaliseUrl($listing->url ?? '') . "\n" . self::normaliseText($listing->title);

        return $source . ':h:' . hash('sha256', $fingerprint);
    }

    // ── Seen-set and price history ────────────────────────────────────────────────────────────────

    /**
     * Record a sighting and report what changed since the last one.
     *
     * `$rentCc` is passed separately from `$listing->rentCc` because the charges-comprises figure is
     * often DERIVED (`rentHc + charges`) by a layer above this one, and the store must compare like
     * with like — `CLAUDE.md` hard rule 9.
     *
     * @param string $atIso ISO-8601 timestamp of this sighting
     */
    public function record(RawListing $listing, ?int $rentCc, string $atIso): Sighting
    {
        $key = $this->dedupKey($listing);
        $epoch = self::epoch($atIso);

        $existing = $this->pdo->prepare('SELECT rent_cc FROM listings WHERE dedup_key = :key');
        $existing->execute(['key' => $key]);
        $row = $existing->fetch();

        $isNew = $row === false;
        $previousRentCc = ($isNew || $row['rent_cc'] === null) ? null : (int) $row['rent_cc'];

        if ($isNew) {
            $insert = $this->pdo->prepare(
                'INSERT INTO listings (dedup_key, source, external_id, url, title, rent_cc, first_seen_at, last_seen_at)
                 VALUES (:key, :source, :external_id, :url, :title, :rent, :at, :at)',
            );
            $insert->execute([
                'key' => $key,
                'source' => $listing->sourceName,
                'external_id' => $listing->externalId,
                'url' => $listing->url,
                'title' => $listing->title,
                'rent' => $rentCc,
                'at' => $atIso,
            ]);
        } else {
            // COALESCE, so a source that stops publishing the rent does not erase what we knew. If
            // it later republishes a lower figure, that is still a real drop and must still fire.
            $update = $this->pdo->prepare(
                'UPDATE listings
                    SET last_seen_at = :at,
                        url          = :url,
                        title        = :title,
                        rent_cc      = COALESCE(:rent, rent_cc)
                  WHERE dedup_key = :key',
            );
            $update->execute([
                'at' => $atIso,
                'url' => $listing->url,
                'title' => $listing->title,
                'rent' => $rentCc,
                'key' => $key,
            ]);
        }

        if ($rentCc !== null && $rentCc !== $previousRentCc) {
            $history = $this->pdo->prepare(
                'INSERT INTO price_history (dedup_key, rent_cc, at, at_epoch) VALUES (:key, :rent, :at, :epoch)',
            );
            $history->execute(['key' => $key, 'rent' => $rentCc, 'at' => $atIso, 'epoch' => $epoch]);
        }

        // Null is not zero. An unknown rent on either side yields an unknown delta rather than a
        // reduction — treating `1000 → null` as a 1000-euro cut would fire the loudest notification
        // the system has on the least information it has ever had.
        $delta = ($rentCc !== null && $previousRentCc !== null) ? $rentCc - $previousRentCc : null;

        return new Sighting(
            dedupKey: $key,
            isNew: $isNew,
            rentCc: $rentCc,
            previousRentCc: $previousRentCc,
            rentDeltaCc: $delta,
            isPriceDrop: $delta !== null && $delta < 0,
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
     */
    public function recordRun(string $sourceName, int $itemCount, bool $ok, ?string $error, string $atIso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO source_runs (source, item_count, ok, error, at, at_epoch)
             VALUES (:source, :count, :ok, :error, :at, :epoch)',
        );
        $statement->execute([
            'source' => $sourceName,
            'count' => $itemCount,
            'ok' => $ok ? 1 : 0,
            'error' => $error,
            'at' => $atIso,
            'epoch' => self::epoch($atIso),
        ]);
    }

    /** Derive the current health of a source from its whole run history. */
    public function health(string $sourceName): SourceHealth
    {
        $statement = $this->pdo->prepare(
            'SELECT item_count, ok, error, at, at_epoch FROM source_runs
              WHERE source = :source ORDER BY at_epoch ASC, id ASC',
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

        foreach (array_reverse($runs) as $run) {
            if ((int) $run['ok'] === 1) {
                $lastSuccessAt = (string) $run['at'];
                break;
            }
        }

        $emptyStreak = self::trailingEmptyRuns($runs);
        $rollingMean = self::rollingMeanBefore($runs, \count($runs) - 1);

        $health = static fn (SourceStatus $status, string $detail): SourceHealth => new SourceHealth(
            sourceName: $sourceName,
            status: $status,
            detail: $detail,
            consecutiveEmptyRuns: $emptyStreak,
            lastSuccessAt: $lastSuccessAt,
            lastCount: $lastCount,
            rollingMean: $rollingMean,
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
            // The baseline is measured BEFORE the streak began, not including it — otherwise the
            // empty runs dilute the very mean that is supposed to prove they are abnormal.
            $baseline = self::rollingMeanBefore($runs, \count($runs) - $emptyStreak);

            if ($baseline !== null && $baseline > 0.0) {
                return $health(SourceStatus::BROKEN, sprintf(
                    '%d runs consécutifs à vide alors que la moyenne précédente était de %.1f annonces',
                    $emptyStreak,
                    $baseline,
                ));
            }

            // A source with a genuinely empty baseline is quiet, not broken. Alerting on it would
            // make the alert noise, and an alert nobody reads is worse than no alert at all.
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
     * excluding) `$index`. Null when there is nothing in the window to average.
     *
     * @param list<array{item_count:int|string, ok:int|string, at_epoch:int|string, ...}> $runs
     */
    private static function rollingMeanBefore(array $runs, int $index): ?float
    {
        if ($index <= 0 || $index > \count($runs)) {
            return null;
        }

        $reference = (int) $runs[$index === \count($runs) ? $index - 1 : $index]['at_epoch'];
        $cutoff = $reference - self::ROLLING_WINDOW_DAYS * 86400;

        $counts = [];

        for ($i = $index - 1; $i >= 0; --$i) {
            if ((int) $runs[$i]['at_epoch'] < $cutoff) {
                break;
            }

            if ((int) $runs[$i]['ok'] === 1) {
                $counts[] = (int) $runs[$i]['item_count'];
            }
        }

        return $counts === [] ? null : array_sum($counts) / \count($counts);
    }

    /**
     * Parse an ISO-8601 timestamp, strictly.
     *
     * `new DateTimeImmutable($s)` accepts an empty string as "now" and shrugs at a good deal of
     * nonsense besides. Here the timestamp orders the price history and defines the health window,
     * so a silently-reinterpreted one would corrupt both without any visible symptom.
     *
     * @throws \InvalidArgumentException on anything that is not a full ISO-8601 instant
     */
    private static function epoch(string $iso): int
    {
        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s.u\Z'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $iso);

            if ($parsed !== false && $parsed->format($format) === $iso) {
                return $parsed->getTimestamp();
            }
        }

        throw new \InvalidArgumentException(sprintf('horodatage ISO-8601 illisible : %s', $iso));
    }

    /** Fold for comparison, falling back to the raw bytes rather than collapsing unreadable input to one key. */
    private static function normaliseText(string $value): string
    {
        return Text::foldTolerant($value) ?? 'brut:' . bin2hex($value);
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
        $url = trim($url);

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
