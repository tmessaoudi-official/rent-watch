<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleStore;
use Scout\Core\RunStore;

/**
 * The car database owns no HOUSING tables — the point of the 2026-09-01 store split.
 *
 * `VehicleStore` composed the rent housing store wholesale to reach six generic methods
 * (`recordRun`, `health`, `shouldAlert`, `markAlerted`, `clearAlerts`, `journalMode`), and the cost
 * was visible on the live file: `state/car-watch.sqlite3` carried `listings`, `price_history`,
 * `listing_detail` and `commute_cache`, all empty, plus the RENT schema version in `schema_meta`.
 *
 * Two guarantees are pinned here, and the second is the one that makes the first safe to ship.
 */
#[CoversClass(VehicleStore::class)]
#[CoversClass(RunStore::class)]
final class VehicleStoreHousingTablesTest extends TestCase
{
    private string $db = '';

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/carstore-housing-' . bin2hex(random_bytes(8)) . '.sqlite3';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    /** A FRESH car database is created with the vehicle and generic tables, and nothing else. */
    public function testAFreshCarDatabaseHasNoHousingTables(): void
    {
        VehicleStore::open($this->db);

        $tables = $this->tables();

        foreach (['listings', 'price_history', 'listing_detail', 'commute_cache'] as $housing) {
            self::assertNotContains(
                $housing,
                $tables,
                "a car database must not carry the housing table `{$housing}`",
            );
        }

        // The counterweight: this must not be satisfied by creating nothing at all.
        foreach (['vehicle_listings', 'vehicle_price_history', 'source_runs', 'source_alerts', 'run_meta'] as $wanted) {
            self::assertContains($wanted, $tables);
        }
    }

    /**
     * An EXISTING database created by the composed housing store has its four empty rent tables
     * dropped — and its vehicle data and its run log survive untouched.
     *
     * The run log is the assertion that matters. `source_runs` IS the 7-day health baseline, so
     * losing or scrambling it during the migration would not present as data loss: it would present
     * as `SOURCE_BROKEN` on sources that are fine, which is the fixture-poisoned-baseline incident
     * of 2026-09-01 rebuilt by a refactor.
     */
    public function testAnExistingDatabaseLosesTheHousingTablesAndKeepsEverythingElse(): void
    {
        $this->seedAsIfComposedWithTheHousingStore();

        $before = new \PDO('sqlite:' . $this->db);
        self::assertSame(2, (int) $before->query('SELECT COUNT(*) FROM vehicle_listings')->fetchColumn());
        self::assertSame(3, (int) $before->query('SELECT COUNT(*) FROM source_runs')->fetchColumn());
        unset($before);

        VehicleStore::open($this->db);

        $tables = $this->tables();
        self::assertNotContains('listings', $tables);
        self::assertNotContains('price_history', $tables);
        self::assertNotContains('listing_detail', $tables);
        self::assertNotContains('commute_cache', $tables);

        $after = new \PDO('sqlite:' . $this->db);
        self::assertSame(
            2,
            (int) $after->query('SELECT COUNT(*) FROM vehicle_listings')->fetchColumn(),
            'the seen-set must survive the migration',
        );
        self::assertSame(
            3,
            (int) $after->query('SELECT COUNT(*) FROM source_runs')->fetchColumn(),
            'the run log IS the health baseline — losing it manufactures SOURCE_BROKEN on healthy sources',
        );
    }

    /**
     * A NON-EMPTY housing table REFUSES, loudly, and drops nothing.
     *
     * Housing rows inside the car database would mean something this design does not understand,
     * and dropping data to make a refactor tidy is not a trade available here. Without this the
     * cleanup is an unconditional `DROP TABLE` on a file whose contents it never checked.
     */
    public function testANonEmptyHousingTableRefusesAndDropsNothing(): void
    {
        $this->seedAsIfComposedWithTheHousingStore();

        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->exec("INSERT INTO listings (dedup_key, source, title) VALUES ('k', 'inli', 'Appartement')");
        unset($pdo);

        try {
            VehicleStore::open($this->db);
            self::fail('a housing row in the car database must refuse, not be dropped');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('listings', $e->getMessage());
            self::assertStringContainsString('1 ligne', $e->getMessage());
        }

        self::assertContains(
            'listings',
            $this->tables(),
            'the refusal must leave the file exactly as it found it',
        );
    }

    /**
     * The cleanup runs even though `migrate()` RETURNS EARLY at the current version.
     *
     * The live car database is already at vehicle schema 1, so a cleanup placed inside the migration
     * transaction would never execute on the one file it exists for. This asserts the ordering
     * rather than the outcome, because the outcome is identical either way on a fresh database and
     * the bug is only reachable on an already-migrated one.
     */
    public function testTheCleanupRunsOnAnAlreadyCurrentDatabase(): void
    {
        $this->seedAsIfComposedWithTheHousingStore();

        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT OR REPLACE INTO vehicle_meta (key, value) VALUES ('schema_version', '"
            . VehicleStore::SCHEMA_VERSION . "')");
        unset($pdo);

        VehicleStore::open($this->db);

        self::assertNotContains(
            'listings',
            $this->tables(),
            'migrate() returns early at the current version — the cleanup must sit above that return',
        );
    }

    /** Build the shape the composed housing store used to leave behind. */
    private function seedAsIfComposedWithTheHousingStore(): void
    {
        $pdo = new \PDO('sqlite:' . $this->db, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        RunStore::ddl($pdo);
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('schema_version', '12')");

        foreach ([
            'listings' => 'dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL, title TEXT NOT NULL',
            'price_history' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL',
            'listing_detail' => 'source TEXT NOT NULL, external_id TEXT NOT NULL',
            'commute_cache' => 'commune_key TEXT NOT NULL, minutes INTEGER',
        ] as $table => $columns) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} ({$columns})");
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_listings (
            dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL, external_id TEXT NOT NULL,
            url TEXT, title TEXT NOT NULL, price_eur INTEGER, first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL, seen_epoch INTEGER NOT NULL, notified_at TEXT,
            outcome TEXT, score INTEGER, snapshot_json TEXT)');

        foreach ([['a', 'paruvendu'], ['b', 'autohero']] as [$k, $src]) {
            $pdo->exec("INSERT INTO vehicle_listings (dedup_key, source, external_id, title,"
                . " first_seen_at, last_seen_at, seen_epoch)"
                . " VALUES ('{$k}', '{$src}', '{$k}1', 'Voiture', '2026-08-30T10:00:00Z',"
                . " '2026-08-30T10:00:00Z', 1787000000)");
        }

        $runs = RunStore::fromPdo($pdo, 'wal');
        $runs->recordRun('paruvendu', 3, true, null, '2026-08-30T10:00:00Z');
        $runs->recordRun('autohero', 9, true, null, '2026-08-30T10:15:00Z');
        $runs->recordRun('leboncoin', 1, true, null, '2026-08-30T10:30:00Z');
    }

    /** @return list<string> */
    private function tables(): array
    {
        $pdo = new \PDO('sqlite:' . $this->db);

        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }
}
