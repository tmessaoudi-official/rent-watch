<?php

declare(strict_types=1);

namespace RentWatch\Tests\Store;

use PHPUnit\Framework\TestCase;
use RentWatch\Core\ListingSnapshot;
use RentWatch\Core\RawListing;
use RentWatch\Store\Store;

/**
 * Schema v7: the evidence a verdict was formed from, and the outcome it was judged to.
 *
 * Two columns, two jobs, and they are separate on purpose.
 *
 * `evidence_json` exists so `scout reclassify` can re-run an improved classifier over a listing
 * WITHOUT re-fetching an ad the source may have removed — and, more importantly, without running on
 * LESS evidence than the original verdict saw. That is a §1 surface, not a convenience: a card whose
 * field says `PLS` while its title says *logement intermédiaire* classifies `UNKNOWN` today by
 * conflict, and re-run on the title alone it becomes a MATCH.
 *
 * `outcome` exists so `scout digest` can find what was digested and never delivered by reading the
 * STORE rather than the last run. The pipeline's retry only works while the listing stays
 * published, so a digest entry whose ad is delisted between passes is silently lost without this.
 * It cannot be re-derived from `tenure`: the criteria engine can REJECT a listing before the tenure
 * branch is ever reached, so `tenure = UNKNOWN` does not mean "was digested".
 *
 * Categories per `CLAUDE.md` § Store tests: **persistence** (it survives reopening, an older schema
 * is upgraded and NOT backfilled), **identity** (evidence is scoped to its own listing),
 * **seen-set** (a delivered digest entry stops being pending), **failure paths** (a corrupt
 * snapshot is loud, never a bare listing).
 */
final class StoreEvidenceTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
    }

    // ── persistence ───────────────────────────────────────────────────────────────────────────────

    public function testAFreshStoreIsAtTheCurrentSchemaVersion(): void
    {
        self::assertSame(7, Store::SCHEMA_VERSION, 'v7 added listings.evidence_json and listings.outcome');
        self::assertSame(Store::SCHEMA_VERSION, $this->store->schemaVersion());
    }

    /**
     * The evidence stored is the evidence the classifier consumed, down to the falsy-but-real values.
     */
    public function testEvidenceRoundTripsThroughTheStore(): void
    {
        $listing = $this->listing(floor: 0, hasElevator: false, detailRead: true);
        $key = $this->store->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;

        $this->store->recordVerdict($key, 'LLI', 9000, ['label explicite'], $listing);

        $recovered = $this->store->evidence($key);

        self::assertNotNull($recovered);
        self::assertEquals($listing, $recovered);
        self::assertSame(0, $recovered->floor, 'RDC is a floor, not an unknown floor');
        self::assertFalse($recovered->hasElevator, 'an explicit "no lift" is not an unmentioned lift');
        self::assertTrue($recovered->detailRead, 'a hydrated listing must not come back as unread');
    }

    public function testEvidenceSurvivesReopening(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rw-evidence-') ?: self::fail('no temp file');

        try {
            $listing = $this->listing(floor: 3, hasElevator: true, detailRead: true);

            $first = Store::open($path);
            $key = $first->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
            $first->recordVerdict($key, 'LLI', 9000, ['label explicite'], $listing);

            $second = Store::open($path);

            self::assertEquals($listing, $second->evidence($key));
        } finally {
            @unlink($path);
        }
    }

    /**
     * A listing stored before v7 has NO evidence, and that is the truth rather than a gap to paper
     * over.
     *
     * Backfilling anything here would be indistinguishable from a real snapshot — and telling those
     * apart is the ENTIRE job of this column, because reclassify must skip a row it cannot judge on
     * at-least-as-much evidence rather than degrade to whatever is lying around. Same precedent as
     * `tenure` (v3) and `group_key` (v4), both of which refuse to backfill for the same reason.
     */
    public function testAPreVersionSevenRowIsNotBackfilled(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rw-evidence-old-') ?: self::fail('no temp file');

        try {
            $this->writeVersionSixDatabase($path);

            $upgraded = Store::open($path);

            self::assertSame(Store::SCHEMA_VERSION, $upgraded->schemaVersion(), 'the upgrade did not run');
            self::assertNotNull($upgraded->snapshot('inli:id:ANN-1'), 'the upgrade lost the seen-set');
            self::assertNull(
                $upgraded->evidence('inli:id:ANN-1'),
                'a pre-v7 row must have NO evidence — an invented one is indistinguishable from a real capture',
            );
            self::assertNull(
                $upgraded->outcome('inli:id:ANN-1'),
                'a pre-v7 row was never judged under a version that recorded the outcome',
            );
        } finally {
            @unlink($path);
        }
    }

    /** Running the upgrade twice is not an error — a migration that died half-way must be re-runnable. */
    public function testTheUpgradeIsReRunnable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rw-evidence-twice-') ?: self::fail('no temp file');

        try {
            $this->writeVersionSixDatabase($path);

            Store::open($path);
            $second = Store::open($path);
            $second->migrate();

            self::assertSame(Store::SCHEMA_VERSION, $second->schemaVersion());
        } finally {
            @unlink($path);
        }
    }

    // ── identity ──────────────────────────────────────────────────────────────────────────────────

    public function testEvidenceIsScopedToItsOwnListing(): void
    {
        $one = $this->listing(externalId: 'ANN-1', title: 'T4 Sartrouville');
        $two = $this->listing(externalId: 'ANN-2', title: 'T3 Cergy');

        $keyOne = $this->store->record($one, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
        $keyTwo = $this->store->record($two, 1100, '2026-08-23T09:00:00+00:00')->dedupKey;

        $this->store->recordVerdict($keyOne, 'LLI', 9000, [], $one);
        $this->store->recordVerdict($keyTwo, 'UNKNOWN', 0, [], $two);

        self::assertSame('T4 Sartrouville', $this->store->evidence($keyOne)?->title);
        self::assertSame('T3 Cergy', $this->store->evidence($keyTwo)?->title);
    }

    public function testAListingWithNoVerdictYetHasNoEvidence(): void
    {
        $key = $this->store->record($this->listing(), 1450, '2026-08-23T09:00:00+00:00')->dedupKey;

        self::assertNull($this->store->evidence($key));
    }

    // ── outcome, and the pending digest ───────────────────────────────────────────────────────────

    public function testAnOutcomeIsRecordedAndReadBack(): void
    {
        $key = $this->store->record($this->listing(), 1450, '2026-08-23T09:00:00+00:00')->dedupKey;

        $this->store->recordOutcome($key, 'DIGEST');

        self::assertSame('DIGEST', $this->store->outcome($key));
    }

    /**
     * A member absorbed by dedup is RECORDED and CLASSIFIED but never JUDGED — only the survivor
     * reaches the criteria engine. Its outcome is NULL, and that must not read as "digested".
     */
    public function testAnUnjudgedMemberHasNoOutcomeAndIsNotPending(): void
    {
        $listing = $this->listing();
        $key = $this->store->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
        $this->store->recordVerdict($key, 'UNKNOWN', 0, ['régime indéterminé'], $listing);

        self::assertNull($this->store->outcome($key));
        self::assertSame([], $this->store->pendingDigest());
    }

    public function testPendingDigestReturnsWhatWasDigestedAndNeverDelivered(): void
    {
        $listing = $this->listing(title: 'T4 à vérifier');
        $key = $this->store->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
        $this->store->recordVerdict($key, 'UNKNOWN', 4000, ['régime indéterminé'], $listing);
        $this->store->recordOutcome($key, 'DIGEST');

        $pending = $this->store->pendingDigest();

        self::assertCount(1, $pending);
        self::assertSame($key, $pending[0]['dedup_key']);
        self::assertSame('T4 à vérifier', $pending[0]['title']);

        // RAW, not decoded, and that is deliberate. A bulk query that decoded would throw on the
        // FIRST corrupt snapshot and take the whole digest with it — one unreadable row costing
        // every readable one, which is the opposite of the skip-and-say-so rule. Decoding is the
        // caller's job precisely so its failure is per-row.
        self::assertNotNull($pending[0]['evidence_json']);
        self::assertEquals($listing, ListingSnapshot::decode($pending[0]['evidence_json']));
        self::assertSame(['régime indéterminé'], json_decode((string) $pending[0]['signals_json'], true));
    }

    /**
     * Delivery is what removes an entry, and `notified_at` is the field that records it — being
     * carried in a DELIVERED digest IS being told about the listing.
     */
    public function testADeliveredEntryStopsBeingPending(): void
    {
        $listing = $this->listing();
        $key = $this->store->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
        $this->store->recordVerdict($key, 'UNKNOWN', 4000, [], $listing);
        $this->store->recordOutcome($key, 'DIGEST');

        self::assertCount(1, $this->store->pendingDigest());

        $this->store->markNotified($key, '2026-08-23T10:00:00+00:00');

        self::assertSame([], $this->store->pendingDigest(), 'a delivered entry must not repeat');
    }

    /**
     * A MATCH and a REJECT are not digest entries. This is the assertion that stops `pendingDigest()`
     * from quietly becoming "everything not yet notified" — which on a REJECT would surface a
     * listing the criteria threw out, and §1's whole landing zone is this digest.
     */
    public function testOnlyDigestOutcomesArePending(): void
    {
        foreach (['MATCH', 'REJECT'] as $index => $outcome) {
            $listing = $this->listing(externalId: 'ANN-' . $index);
            $key = $this->store->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
            $this->store->recordVerdict($key, 'LLI', 9000, [], $listing);
            $this->store->recordOutcome($key, $outcome);
        }

        self::assertSame([], $this->store->pendingDigest());
    }

    /**
     * A later pass re-judging the same listing replaces its outcome rather than adding one.
     *
     * The failure this pins: a listing that was digested and is now a MATCH must LEAVE the pending
     * digest, or `scout digest` announces as doubtful something the pipeline already notified as a
     * match.
     */
    public function testAReJudgedListingCarriesOnlyItsLatestOutcome(): void
    {
        $listing = $this->listing();
        $key = $this->store->record($listing, 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
        $this->store->recordVerdict($key, 'UNKNOWN', 4000, [], $listing);
        $this->store->recordOutcome($key, 'DIGEST');
        $this->store->recordOutcome($key, 'MATCH');

        self::assertSame('MATCH', $this->store->outcome($key));
        self::assertSame([], $this->store->pendingDigest());
    }

    // ── failure paths ─────────────────────────────────────────────────────────────────────────────

    /**
     * A corrupt snapshot is LOUD. Hard rule 3: an exception must not become an empty result.
     *
     * Returning a bare listing here would classify as `UNKNOWN` and read as an honest doubt rather
     * than as a row nobody can judge — and reclassify would then have run on strictly less evidence
     * than the original, which is the §1 breach. The caller's job is to skip it and SAY SO, which it
     * can only do if this throws.
     */
    public function testACorruptEvidenceBlobIsRefusedLoudly(): void
    {
        $key = $this->store->record($this->listing(), 1450, '2026-08-23T09:00:00+00:00')->dedupKey;
        $this->store->recordVerdict($key, 'UNKNOWN', 0, [], $this->listing());

        $this->corrupt($key);

        $this->expectException(\JsonException::class);

        $this->store->evidence($key);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

    private function listing(
        string $externalId = 'ANN-1',
        string $title = 'T4 lumineux',
        ?int $floor = null,
        ?bool $hasElevator = null,
        bool $detailRead = false,
    ): RawListing {
        return new RawListing(
            sourceName: 'inli',
            externalId: $externalId,
            title: $title,
            description: 'Bel appartement.',
            fields: ['financement' => 'LLI'],
            url: 'https://inli.test/annonce/' . $externalId,
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.5,
            rooms: 4,
            floor: $floor,
            hasElevator: $hasElevator,
            detailRead: $detailRead,
        );
    }

    /** Overwrite a stored snapshot with something that is not JSON. */
    private function corrupt(string $key): void
    {
        $reflection = new \ReflectionProperty(Store::class, 'pdo');
        /** @var \PDO $pdo */
        $pdo = $reflection->getValue($this->store);

        $pdo->prepare('UPDATE listings SET evidence_json = :bad WHERE dedup_key = :key')
            ->execute(['bad' => '{not json', 'key' => $key]);
    }

    /** A v6 database — the shape v7 upgrades FROM. */
    private function writeVersionSixDatabase(string $path): void
    {
        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->exec(
            <<<'SQL'
            CREATE TABLE schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE listings (
                dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL, external_id TEXT NOT NULL,
                url TEXT, title TEXT NOT NULL, rent_cc INTEGER,
                first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, seen_epoch INTEGER NOT NULL DEFAULT 0,
                notified_at TEXT, tenure TEXT, confidence_bp INTEGER, signals_json TEXT, group_key TEXT
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
            CREATE TABLE listing_detail (
                source TEXT NOT NULL, external_id TEXT NOT NULL, url_fetched TEXT,
                fields_json TEXT, fetched_at TEXT, attempts INTEGER NOT NULL DEFAULT 0,
                last_attempt_at TEXT, last_error TEXT, map_fingerprint TEXT,
                PRIMARY KEY (source, external_id)
            );
            INSERT INTO schema_meta VALUES ('schema_version', '6');
            SQL
        );

        $raw->prepare(
            'INSERT INTO listings (dedup_key, source, external_id, url, title, rent_cc,
                first_seen_at, last_seen_at, seen_epoch, tenure, confidence_bp)
             VALUES (:k, :s, :e, :u, :t, :r, :f, :l, :p, :n, :b)',
        )->execute([
            'k' => 'inli:id:ANN-1', 's' => 'inli', 'e' => 'ANN-1', 'u' => 'https://a.test/1',
            't' => 'T3 Cergy', 'r' => 1000, 'f' => '2026-08-01T09:00:00+00:00',
            'l' => '2026-08-05T09:00:00+00:00', 'p' => 1785913200, 'n' => 'UNKNOWN', 'b' => 4000,
        ]);
    }
}
