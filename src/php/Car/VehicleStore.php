<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\RunStore;

/**
 * The car domain's seen-set, price history and evidence — on its OWN database file, beside a
 * composed {@see RunStore} that provides the run log, source health, feed silence and alert
 * cooldowns.
 *
 * UNTIL 2026-09-01 THIS COMPOSED THE RENT HOUSING STORE to reach six methods, and the live file
 * showed what that cost: `state/car-watch.sqlite3` carried `listings`, `price_history`,
 * `listing_detail` and `commute_cache` — four rent tables, all empty — and recorded the RENT schema
 * version in `schema_meta`, in a database with no housing in it. {@see migrate()} drops those four,
 * under a row-count guard.
 *
 * Two connections to one file, one per class: the composed store owns its tables and its
 * migrations, this class owns `vehicle_*`. Both run WAL with a busy timeout, so two writers wait
 * rather than fail. On `:memory:` they are two separate databases, which is fine for a test and
 * is why production is always a file.
 *
 * The contract mirrors the rent store's categories where they apply — identity, order, price
 * events, seen-set, evidence, persistence — and the Q36 analog is here: {@see isSeenSetEmpty()}
 * reads the VEHICLE table, because the composed store's own check reads the housing one and
 * would say "empty" on this file forever.
 */
final readonly class VehicleStore
{
    public const int SCHEMA_VERSION = 1;
    private const int BUSY_TIMEOUT_MS = 10000;

    private function __construct(
        private readonly \PDO $pdo,
        private readonly RunStore $runs,
    ) {}

    public static function open(string $path, ?int $feedSilentDays = null): self
    {
        $runs = RunStore::open($path, $feedSilentDays);

        $pdo = new \PDO('sqlite:' . $path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->query('PRAGMA journal_mode = WAL')?->closeCursor();

        $store = new self($pdo, $runs);
        $store->migrate();

        return $store;
    }

    /** The run log, health, feed silence and alert cooldowns — the generic store. */
    public function runs(): RunStore
    {
        return $this->runs;
    }

    public function journalMode(): string
    {
        return $this->runs->journalMode();
    }

    public function dedupKey(VehicleListing $car): string
    {
        return $car->sourceName . ':id:' . rawurlencode(trim($car->externalId));
    }

    /**
     * Record one observation at `$atIso` — the source's instant when it states one, the pass time
     * otherwise — and say whether it is new, current, and a price drop.
     *
     * A sighting OLDER than the stored one is superseded: it updates nothing, writes no history
     * and is never a drop. History is changes-only.
     */
    public function record(VehicleListing $car, string $atIso): VehicleSighting
    {
        $key = $this->dedupKey($car);
        if (trim($car->externalId) === '') {
            throw new \InvalidArgumentException('une annonce sans identifiant ne peut pas être enregistrée : ' . $car->sourceName);
        }
        $epoch = self::epoch($atIso);
        $price = $car->priceEur;

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->pdo->prepare('SELECT seen_epoch, price_eur FROM vehicle_listings WHERE dedup_key = :k');
            $q->execute(['k' => $key]);
            $row = $q->fetch();

            $isNew = $row === false;
            $isCurrent = $isNew || $epoch >= (int) $row['seen_epoch'];
            $previous = $isNew || !$isCurrent || $row['price_eur'] === null ? null : (int) $row['price_eur'];

            if ($isNew) {
                $this->pdo->prepare(
                    'INSERT INTO vehicle_listings (dedup_key, source, external_id, url, title, price_eur, first_seen_at, last_seen_at, seen_epoch)
                     VALUES (:k, :s, :e, :u, :t, :p, :at, :at, :ep)',
                )->execute(['k' => $key, 's' => $car->sourceName, 'e' => $car->externalId, 'u' => $car->url, 't' => $car->title, 'p' => $price, 'at' => $atIso, 'ep' => $epoch]);
            } elseif ($isCurrent) {
                $this->pdo->prepare(
                    'UPDATE vehicle_listings SET last_seen_at = :at, seen_epoch = :ep, url = COALESCE(:u, url), title = CASE WHEN :t = \'\' THEN title ELSE :t END,
                            price_eur = COALESCE(:p, price_eur) WHERE dedup_key = :k',
                )->execute(['k' => $key, 'at' => $atIso, 'ep' => $epoch, 'u' => $car->url, 't' => $car->title, 'p' => $price]);
            }

            if ($isCurrent && $price !== null) {
                $last = $this->pdo->prepare('SELECT price_eur FROM vehicle_price_history WHERE dedup_key = :k ORDER BY at_epoch DESC, id DESC LIMIT 1');
                $last->execute(['k' => $key]);
                $lastPrice = $last->fetchColumn();
                if ($lastPrice === false || (int) $lastPrice !== $price) {
                    $this->pdo->prepare('INSERT INTO vehicle_price_history (dedup_key, price_eur, at, at_epoch) VALUES (:k, :p, :at, :ep)')
                        ->execute(['k' => $key, 'p' => $price, 'at' => $atIso, 'ep' => $epoch]);
                }
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return new VehicleSighting(
            dedupKey: $key,
            isNew: $isNew,
            isCurrent: $isCurrent,
            priceEur: $price,
            previousPriceEur: $previous,
            isPriceDrop: $isCurrent && $price !== null && $previous !== null && $price < $previous,
        );
    }

    /** The verdict and the evidence it was formed from — every constructor parameter, by the snapshot's own reflection test. */
    public function recordVerdict(string $dedupKey, VehicleVerdict $verdict, VehicleListing $car): void
    {
        $this->pdo->prepare('UPDATE vehicle_listings SET outcome = :o, score = :sc, snapshot_json = :j WHERE dedup_key = :k')
            ->execute(['k' => $dedupKey, 'o' => $verdict->outcome->value, 'sc' => $verdict->score, 'j' => VehicleSnapshot::encode($car)]);
    }

    public function wasNotified(string $dedupKey): bool
    {
        $q = $this->pdo->prepare('SELECT notified_at FROM vehicle_listings WHERE dedup_key = :k');
        $q->execute(['k' => $dedupKey]);
        $v = $q->fetchColumn();

        return $v !== false && $v !== null;
    }

    public function markNotified(string $dedupKey, string $atIso): void
    {
        $this->pdo->prepare('UPDATE vehicle_listings SET notified_at = :at WHERE dedup_key = :k AND notified_at IS NULL')
            ->execute(['k' => $dedupKey, 'at' => $atIso]);
    }

    /** Q36 analog: nothing recorded at all — a fresh file, or a missing volume mount wearing one's face. */
    public function isSeenSetEmpty(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM vehicle_listings')->fetchColumn() === 0;
    }

    /** @return array<string, true> every external id ever recorded for a source — the sitemap novelty gate */
    public function knownExternalIds(string $source): array
    {
        $q = $this->pdo->prepare('SELECT external_id FROM vehicle_listings WHERE source = :s');
        $q->execute(['s' => $source]);
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $out[(string) $row['external_id']] = true;
        }

        return $out;
    }

    /** @return list<int> every price this car has been seen at, oldest first — changes only */
    public function priceHistory(string $dedupKey): array
    {
        $q = $this->pdo->prepare('SELECT price_eur FROM vehicle_price_history WHERE dedup_key = :k ORDER BY at_epoch ASC, id ASC');
        $q->execute(['k' => $dedupKey]);

        return array_map(static fn (array $r): int => (int) $r['price_eur'], $q->fetchAll());
    }

    public function snapshot(string $dedupKey): ?VehicleListing
    {
        $q = $this->pdo->prepare('SELECT snapshot_json FROM vehicle_listings WHERE dedup_key = :k');
        $q->execute(['k' => $dedupKey]);
        $json = $q->fetchColumn();

        return $json === false || $json === null ? null : VehicleSnapshot::decode((string) $json);
    }

    public function snapshotPrice(string $dedupKey): ?int
    {
        return $this->column($dedupKey, 'price_eur') === null ? null : (int) $this->column($dedupKey, 'price_eur');
    }

    public function snapshotScore(string $dedupKey): ?int
    {
        $v = $this->column($dedupKey, 'score');

        return $v === null ? null : (int) $v;
    }

    public function outcomeOf(string $dedupKey): ?string
    {
        $v = $this->column($dedupKey, 'outcome');

        return $v === null ? null : (string) $v;
    }

    private function column(string $dedupKey, string $column): mixed
    {
        $q = $this->pdo->prepare('SELECT ' . $column . ' FROM vehicle_listings WHERE dedup_key = :k');
        $q->execute(['k' => $dedupKey]);
        $v = $q->fetchColumn();

        return $v === false ? null : $v;
    }

    /**
     * The ROLLUP queue (A5, row 6): every MATCH nobody has been told about, oldest first, with the
     * score and snapshot the pass stored — the car store keeps both, so nothing is re-judged here.
     *
     * @return list<array{dedup_key: string, source: string, external_id: string, url: ?string, title: string, price_eur: ?int, score: ?int, snapshot_json: ?string}>
     */
    public function pendingRollup(int $limit = 50): array
    {
        $q = $this->pdo->prepare("SELECT dedup_key, source, external_id, url, title, price_eur, score, snapshot_json FROM vehicle_listings WHERE outcome = 'MATCH' AND notified_at IS NULL ORDER BY seen_epoch ASC, dedup_key ASC LIMIT :l");
        $q->bindValue('l', max(1, $limit), \PDO::PARAM_INT);
        $q->execute();

        /** @var list<array{dedup_key: string, source: string, external_id: string, url: ?string, title: string, price_eur: ?int, score: ?int, snapshot_json: ?string}> $rows */
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    public function pendingRollupCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM vehicle_listings WHERE outcome = 'MATCH' AND notified_at IS NULL")->fetchColumn();
    }

    /** @return array{count: int, notified: int, matches: int} */
    public function counts(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) c, SUM(notified_at IS NOT NULL) n, SUM(outcome = 'MATCH') m FROM vehicle_listings")->fetch();

        return ['count' => (int) $row['c'], 'notified' => (int) $row['n'], 'matches' => (int) $row['m']];
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        // ABOVE the version check on purpose, because that check RETURNS EARLY at the current
        // version and the live car database is already there — a cleanup placed in the migration
        // transaction below would never run on the one file it exists for.
        $this->dropOrphanedHousingTables();

        $q = $this->pdo->query("SELECT value FROM vehicle_meta WHERE key = 'schema_version'");
        $current = (int) ($q->fetchColumn() ?: 0);
        if ($current > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf('base véhicules au schéma %d, ce code connaît le %d — mettez le code à jour, pas la base', $current, self::SCHEMA_VERSION));
        }
        if ($current === self::SCHEMA_VERSION) {
            return;
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_listings (
                dedup_key     TEXT PRIMARY KEY,
                source        TEXT NOT NULL,
                external_id   TEXT NOT NULL,
                url           TEXT,
                title         TEXT NOT NULL,
                price_eur     INTEGER,
                first_seen_at TEXT NOT NULL,
                last_seen_at  TEXT NOT NULL,
                seen_epoch    INTEGER NOT NULL,
                notified_at   TEXT,
                outcome       TEXT,
                score         INTEGER,
                snapshot_json TEXT
            )');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS vehicle_listings_source ON vehicle_listings (source)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_price_history (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                dedup_key TEXT NOT NULL REFERENCES vehicle_listings (dedup_key),
                price_eur INTEGER NOT NULL,
                at        TEXT NOT NULL,
                at_epoch  INTEGER NOT NULL
            )');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS vehicle_price_history_key ON vehicle_price_history (dedup_key, at_epoch, id)');
            $this->pdo->prepare("INSERT OR REPLACE INTO vehicle_meta (key, value) VALUES ('schema_version', :v)")->execute(['v' => (string) self::SCHEMA_VERSION]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Drop the four RENT tables an older build created in this file, and only if they are EMPTY.
     *
     * Until 2026-09-01 this class composed the rent housing store, whose migration created
     * `listings`, `price_history`, `listing_detail` and `commute_cache` here. Measured on the live
     * `state/car-watch.sqlite3`: all four present, all four with zero rows. Now that the composed
     * store is {@see RunStore}, a FRESH car database never gets them — so leaving them on the
     * existing one makes that machine differ from every new deployment, which is how a bug
     * reproduces in one place and not another.
     *
     * A NON-EMPTY one REFUSES, loudly. Housing rows in the car database would mean something this
     * design does not understand, and dropping data to make a refactor tidy is not a trade available
     * here. Fail-closed, as everywhere else in this repo.
     *
     * `schema_meta` is deliberately LEFT ALONE. It is inert — nothing in the car domain reads it
     * once the housing store is gone — and leaving it means that reverting this change finds the
     * version the composed store expects, rather than a versionless file it would re-migrate from
     * scratch across twelve upgrade steps against tables that already exist.
     */
    private function dropOrphanedHousingTables(): void
    {
        foreach (['listings', 'price_history', 'listing_detail', 'commute_cache'] as $table) {
            $exists = $this->pdo
                ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'")
                ?->fetchColumn();

            if ($exists === false || $exists === null) {
                continue;
            }

            $rows = (int) ($this->pdo->query('SELECT COUNT(*) FROM ' . $table)?->fetchColumn() ?: 0);

            if ($rows > 0) {
                throw new \RuntimeException(sprintf(
                    'la table de logement « %s » contient %d ligne(s) dans la base véhicules — '
                        . 'inattendu, rien n\'est supprimé : inspectez le fichier avant de continuer',
                    $table,
                    $rows,
                ));
            }

            $this->pdo->exec('DROP TABLE ' . $table);
        }
    }

    /**
     * Strict ISO-8601 to unix seconds — FORWARDS to the single implementation.
     *
     * This was a verbatim THIRD copy of the rent store's parser (the rent store had one, this class
     * had one). Extracting {@see RunStore} is what made one home for it obvious: the split removes a
     * duplication rather than adding one. Strictness matters here — `new \DateTimeImmutable` is a
     * RELATIVE-expression parser that misparses far more often than it throws, and every misparse
     * moves the instant forward, which would move a sighting in time.
     */
    public static function epoch(string $iso): int
    {
        return RunStore::epoch($iso);
    }
}
