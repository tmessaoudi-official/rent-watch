<?php

declare(strict_types=1);

namespace RentWatch\Tests\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\RawListing;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * Schema v4's `group_key` overlay — the cross-portal price history, ruled 2026-08-19 23:02.
 *
 * The overlay is a HISTORY concept and nothing else. `dedup_key` stays per-source, `price_history`
 * rows stay attached to the source that observed them, and the notification gate is not consulted
 * here at all — `Core/Dedup`'s asymmetry doctrine says an over-merge that could suppress a
 * notification is silent, and silent is the one failure this project refuses.
 *
 * Every test below belongs to one of the store's named categories (`CLAUDE.md` § "Testing"):
 * **persistence** for the migration, **identity** for what the key ties together, **rent events**
 * for what the joined history reports.
 */
#[CoversClass(Store::class)]
final class StoreGroupTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
    }

    // ── persistence ───────────────────────────────────────────────────────────────────────────────

    /**
     * The overlay shipped as v4, so an older database is upgraded rather than refused.
     *
     * The bare CURRENT-version assertion lives in `StoreDetailTest` and `PipelineRunTest`; what
     * this one guards is that the overlay's own floor never moves backwards. Asserting `=== 5` here
     * too would mean every future migration edits three files to say the same thing, and the third
     * one is where somebody eventually writes the wrong number.
     */
    public function testTheOverlayShippedAtVersionFourOrLater(): void
    {
        self::assertGreaterThanOrEqual(4, Store::SCHEMA_VERSION);
        self::assertSame(Store::SCHEMA_VERSION, $this->store->schemaVersion());
    }

    /**
     * The column is added by the migration and is NOT backfilled.
     *
     * NULL means "never clustered under a version that recorded it", which is the truth about a row
     * stored before v4. Backfilling it with the row's own key would be worse than useless: it would
     * be indistinguishable from a real single-member group, and this is the query that has to tell
     * those apart.
     */
    public function testUpgradeAddsTheColumnWithoutBackfillingIt(): void
    {
        $path = $this->temporaryDatabase();
        $this->writeVersionThreeDatabase($path);

        $upgraded = Store::open($path);

        self::assertSame(Store::SCHEMA_VERSION, $upgraded->schemaVersion());
        self::assertNull($upgraded->groupKey('inli:id:ANN-1'));
    }

    /** A half-applied migration must be safe to run again — the seen-set cannot be rebuilt. */
    public function testTheMigrationStepIsReRunnable(): void
    {
        $path = $this->temporaryDatabase();
        $this->writeVersionThreeDatabase($path);

        $first = Store::open($path);
        $first->migrate();
        $second = Store::open($path);
        $second->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $second->schemaVersion());
    }

    // ── identity ──────────────────────────────────────────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS. `Dedup::cluster()` keeps the FIRST item as survivor and `Core/Pacer`
     * shuffles source order every pass — so survivorship flips between passes as a matter of course.
     * If the group key were minted from whoever survived this pass, the group would be renamed every
     * time the shuffle changed its mind, and a member that delisted in between would keep a key
     * nobody else carries.
     *
     * That is verbatim the failure the ruling rejected the read-only join for: a flat whose
     * surviving portal changes loses its history at exactly the seam worth notifying on.
     */
    public function testTheGroupKeySurvivesASurvivorFlip(): void
    {
        $inli = $this->record('inli', 'A-1', 1200);
        $cdc = $this->record('cdc', 'B-9', 1200);

        // Pass 1 — In'li came first, so In'li survived.
        $first = $this->store->assignGroup([$inli, $cdc]);

        // Pass 2 — the shuffle put CDC first. Same flat, opposite order.
        $second = $this->store->assignGroup([$cdc, $inli]);

        self::assertSame($first, $second, 'the group was renamed when survivorship flipped');
        self::assertSame($first, $this->store->groupKey($inli));
        self::assertSame($first, $this->store->groupKey($cdc));
    }

    /**
     * A member that delists keeps its group, so its history stays reachable from the survivor.
     *
     * This is the seam the whole overlay exists for: the flat is still on one portal, and the rent
     * it was published at on the other is exactly the comparison worth making.
     */
    public function testADelistedMemberKeepsItsGroup(): void
    {
        $inli = $this->record('inli', 'A-1', 1200);
        $cdc = $this->record('cdc', 'B-9', 1200);
        $group = $this->store->assignGroup([$inli, $cdc]);

        // CDC delists; only In'li comes back this pass.
        $this->store->assignGroup([$inli]);

        self::assertSame($group, $this->store->groupKey($cdc), 'the delisted member was orphaned');
        self::assertSame($group, $this->store->groupKey($inli));
    }

    /**
     * Two groups that turn out to be one flat are MERGED, not left to disagree.
     *
     * Reachable whenever a third listing corroborates two that never clustered with each other —
     * the tolerances in `Dedup` are pairwise, so transitivity is not guaranteed.
     */
    public function testTwoGroupsThatMeetAreMerged(): void
    {
        $a = $this->record('inli', 'A-1', 1200);
        $b = $this->record('cdc', 'B-9', 1200);
        $c = $this->record('seqens', 'C-3', 1210);

        $left = $this->store->assignGroup([$a, $b]);
        $right = $this->store->assignGroup([$c, $this->record('vilogia', 'D-4', 1210)]);

        self::assertNotSame($left, $right);

        $merged = $this->store->assignGroup([$b, $c]);

        foreach ([$a, $b, $c] as $key) {
            self::assertSame($merged, $this->store->groupKey($key), 'a member was left in the old group');
        }
    }

    /** A listing that clusters with nothing has no group — NULL, not a group of one. */
    public function testAListingThatClustersAloneHasNoGroup(): void
    {
        $only = $this->record('inli', 'A-1', 1200);

        self::assertNull($this->store->assignGroup([$only]));
        self::assertNull($this->store->groupKey($only));
    }

    /** An unknown key is refused loudly — silently grouping nothing is the failure mode. */
    public function testAssigningAGroupToAnUnknownKeyIsRefused(): void
    {
        $known = $this->record('inli', 'A-1', 1200);

        $this->expectException(\InvalidArgumentException::class);
        $this->store->assignGroup([$known, 'inli:id:NEVER-RECORDED']);
    }

    // ── rent events ───────────────────────────────────────────────────────────────────────────────

    /**
     * A singleton reports ITS OWN history, not an empty one.
     *
     * `WHERE group_key = (SELECT group_key ...)` returns the empty set when the group is NULL,
     * because NULL never equals NULL in SQL. That would report "no price history" for every listing
     * that never clustered — which is most of them — and it would report it silently.
     */
    public function testASingletonReportsItsOwnHistory(): void
    {
        $key = $this->record('inli', 'A-1', 1200);
        $this->store->record($this->listing('inli', 'A-1'), 1150, '2026-08-10T09:00:00+00:00');

        $history = $this->store->groupPriceHistory($key);

        self::assertSame([1200, 1150], array_column($history, 'rent_cc'));
        self::assertSame(['inli', 'inli'], array_column($history, 'source'));
    }

    /** The joined history is chronological across members, and says which source saw what. */
    public function testAGroupsHistoryJoinsItsMembersInChronologicalOrder(): void
    {
        $inli = $this->record('inli', 'A-1', 1200, '2026-08-01T09:00:00+00:00');
        $cdc = $this->record('cdc', 'B-9', 1195, '2026-08-02T09:00:00+00:00');
        $this->store->record($this->listing('inli', 'A-1'), 1150, '2026-08-03T09:00:00+00:00');

        $this->store->assignGroup([$inli, $cdc]);

        $history = $this->store->groupPriceHistory($inli);

        self::assertSame([1200, 1195, 1150], array_column($history, 'rent_cc'));
        self::assertSame(['inli', 'cdc', 'inli'], array_column($history, 'source'));

        // Read from either member — the group is the unit, not the row you happened to ask about.
        self::assertSame($history, $this->store->groupPriceHistory($cdc));
    }

    /**
     * The per-source history is UNTOUCHED by grouping.
     *
     * The ruling confines the blast radius to one presentation view: per-source rows, per-source
     * `priceHistory()` and per-source drop detection all stay as they were, so an over-merged group
     * misreports a merged timeline and nothing else. If grouping could change `priceHistory()`, a
     * bad cluster would manufacture a phantom rent drop — and a phantom drop is a notification.
     */
    public function testGroupingDoesNotChangeThePerSourceHistory(): void
    {
        $inli = $this->record('inli', 'A-1', 1200);
        $cdc = $this->record('cdc', 'B-9', 900);

        $before = $this->store->priceHistory($inli);
        $this->store->assignGroup([$inli, $cdc]);

        self::assertSame($before, $this->store->priceHistory($inli));
        self::assertSame([1200], $this->store->priceHistory($inli));
    }

    // ── seen-set ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Grouping does not mark anything notified, and cannot.
     *
     * A group-scoped `wasNotified()` would permanently suppress the second listing once two members
     * stop clustering. Under-merge notifies twice, which is visible and self-correcting; over-merge
     * hides a flat, which is silent. This asserts the store offers no route to the silent one.
     */
    public function testAnOverMergedGroupCannotSuppressANotification(): void
    {
        $inli = $this->record('inli', 'A-1', 1200);
        $cdc = $this->record('cdc', 'B-9', 1200);

        $this->store->assignGroup([$inli, $cdc]);
        $this->store->markNotified($inli, '2026-08-10T09:00:00+00:00', 'MATCH');

        self::assertTrue($this->store->wasNotified($inli));
        self::assertFalse(
            $this->store->wasNotified($cdc),
            'being grouped with a notified listing marked this one notified — it would never be sent',
        );
    }

    // ── scaffolding ───────────────────────────────────────────────────────────────────────────────

    private function record(
        string $source,
        string $externalId,
        int $rentCc,
        string $atIso = '2026-08-01T09:00:00+00:00',
    ): string {
        return $this->store->record($this->listing($source, $externalId), $rentCc, $atIso)->dedupKey;
    }

    private function listing(string $source, string $externalId): RawListing
    {
        return new RawListing(
            sourceName: $source,
            externalId: $externalId,
            title: 'T3 Cergy',
            description: 'Bel appartement.',
            fields: [],
            url: 'https://' . $source . '.test/annonce/' . $externalId,
        );
    }

    /** A v3 database — the shape the overlay upgrades FROM. */
    private function writeVersionThreeDatabase(string $path): void
    {
        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->exec(
            <<<'SQL'
            CREATE TABLE schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE listings (
                dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL, external_id TEXT NOT NULL,
                url TEXT, title TEXT NOT NULL, rent_cc INTEGER,
                first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, seen_epoch INTEGER NOT NULL DEFAULT 0,
                notified_at TEXT, tenure TEXT, confidence_bp INTEGER, signals_json TEXT
            );
            CREATE TABLE price_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL,
                rent_cc INTEGER NOT NULL, at TEXT NOT NULL, at_epoch INTEGER NOT NULL
            );
            CREATE TABLE source_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_count INTEGER NOT NULL,
                ok INTEGER NOT NULL, error TEXT, at TEXT NOT NULL, at_epoch INTEGER NOT NULL,
                duration_ms INTEGER
            );
            CREATE TABLE source_alerts (
                source TEXT NOT NULL, status TEXT NOT NULL, at TEXT NOT NULL,
                at_epoch INTEGER NOT NULL, PRIMARY KEY (source, status)
            );
            INSERT INTO schema_meta VALUES ('schema_version', '3');
            SQL
        );

        $raw->prepare(
            'INSERT INTO listings (dedup_key, source, external_id, url, title, rent_cc,
                first_seen_at, last_seen_at, seen_epoch)
             VALUES (:k, :s, :e, :u, :t, :r, :f, :l, :p)',
        )->execute([
            'k' => 'inli:id:ANN-1', 's' => 'inli', 'e' => 'ANN-1', 'u' => 'https://a.test/1',
            't' => 'T3 Cergy', 'r' => 1000, 'f' => '2026-08-01T09:00:00+00:00',
            'l' => '2026-08-05T09:00:00+00:00', 'p' => 1785913200,
        ]);
    }

    /** @var list<string> */
    private array $temporaryPaths = [];

    private function temporaryDatabase(): string
    {
        $path = sys_get_temp_dir() . '/rent-watch-group-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $this->temporaryPaths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryPaths = [];
    }
    /**
     * The group veto reports the HIGHEST-CONFIDENCE excluded member, not an arbitrary one.
     *
     * It was inert while the only caller null-checked the result, so no test could tell "strongest"
     * from "any" — a review panel said so on 2026-08-24. `Pipeline` now builds the rejection from
     * the returned tenure, so the value reaches the operator in the rejection line and the choice
     * is load-bearing.
     */
    public function testTheGroupVetoReportsTheHighestConfidenceExcludedMember(): void
    {
        $weak = $this->listing('inli', 'GRP-W');
        $strong = $this->listing('cdc', 'GRP-S');

        $weakKey = $this->store->record($weak, 900, '2026-08-07T09:00:00+00:00')->dedupKey;
        $strongKey = $this->store->record($strong, 900, '2026-08-07T09:00:00+00:00')->dedupKey;

        $this->store->recordVerdict($weakKey, 'PLS', 30, ['faible'], $weak);
        $this->store->recordVerdict($strongKey, 'PLAI', 95, ['explicite'], $strong);
        $this->store->assignGroup([$weakKey, $strongKey]);

        self::assertSame(
            Tenure::PLAI,
            $this->store->groupExcludedTenure($weakKey),
            'the strongest excluded member is what the rejection should name',
        );
    }

    /** A group holding only eligible tenures vetoes nothing. */
    public function testTheGroupVetoIsSilentOnAnEligibleGroup(): void
    {
        $a = $this->listing('inli', 'GRP-A');
        $b = $this->listing('cdc', 'GRP-B');

        $aKey = $this->store->record($a, 900, '2026-08-07T09:00:00+00:00')->dedupKey;
        $bKey = $this->store->record($b, 900, '2026-08-07T09:00:00+00:00')->dedupKey;

        $this->store->recordVerdict($aKey, 'LLI', 90, ['explicite'], $a);
        $this->store->recordVerdict($bKey, 'UNKNOWN', 0, ['aucun signal'], $b);
        $this->store->assignGroup([$aKey, $bKey]);

        self::assertNull($this->store->groupExcludedTenure($aKey));
    }

    /** A listing that clustered alone has no group, and therefore no veto. */
    public function testASingletonHasNoGroupVeto(): void
    {
        $solo = $this->listing('inli', 'GRP-SOLO');
        $key = $this->store->record($solo, 900, '2026-08-07T09:00:00+00:00')->dedupKey;
        $this->store->recordVerdict($key, 'PLS', 99, ['explicite'], $solo);
        $this->store->assignGroup([$key]);

        self::assertNull(
            $this->store->groupExcludedTenure($key),
            'a singleton is not a group — its own tenure is judged on its own path',
        );
    }

}
