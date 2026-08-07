<?php

declare(strict_types=1);

namespace RentWatch\Tests\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceStatus;
use RentWatch\Store\Store;

/**
 * The store is where a silent failure costs the most, and it is not the obvious one.
 *
 * `CLAUDE.md` § "Credentials & stateful data" lists the seen-set and the price history as data that
 * must never be casually deleted: losing the seen-set re-notifies the entire market at once, and
 * price history is not reconstructible because a listing only ever shows its CURRENT rent. Both
 * failures are silent in the direction that matters — nothing warns you that a rent drop went
 * unnoticed, because the evidence it happened is the thing that was lost.
 */
#[CoversClass(Store::class)]
final class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        // In-memory, so no test can leave a real seen-set behind or read one.
        $this->store = Store::open(':memory:');
    }

    /** The schema is created on open and creating it twice is not an error. */
    public function testMigrationIsIdempotent(): void
    {
        $this->store->migrate();
        $this->store->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $this->store->schemaVersion());
    }

    /**
     * `(source, externalId)` is the stable key; the URL/title hash is only the FALLBACK.
     *
     * spec §7: "stable key `(source, external_ref)`, falling back to a hash of normalised
     * `(url, title)`". The fallback matters because several institutional feeds do not expose a
     * stable id at all — and if the key changed between runs, every listing would look new on every
     * run and the user would be notified about the same flat forever.
     */
    public function testDedupKeyPrefersTheSourcesOwnId(): void
    {
        $withId = $this->listing(externalId: 'ANN-42', url: 'https://a.test/1', title: 'T3 Cergy');
        $sameIdDifferentText = $this->listing(externalId: 'ANN-42', url: 'https://a.test/CHANGED', title: 'T3 Cergy — baisse de prix');

        self::assertSame($this->store->dedupKey($withId), $this->store->dedupKey($sameIdDifferentText));
    }

    public function testDedupKeyFallsBackToUrlAndTitleWhenThereIsNoId(): void
    {
        $a = $this->listing(externalId: '', url: 'https://a.test/1', title: 'T3 Cergy');
        $b = $this->listing(externalId: '', url: 'https://a.test/1', title: 'T3 Cergy');
        $c = $this->listing(externalId: '', url: 'https://a.test/2', title: 'T3 Cergy');

        self::assertSame($this->store->dedupKey($a), $this->store->dedupKey($b));
        self::assertNotSame($this->store->dedupKey($a), $this->store->dedupKey($c));
    }

    /** Two sources may legitimately use the same id; the key must not collide across them. */
    public function testDedupKeyIsScopedToTheSource(): void
    {
        $inli = $this->listing(source: 'inli', externalId: '17');
        $cdc = $this->listing(source: 'cdc_habitat', externalId: '17');

        self::assertNotSame($this->store->dedupKey($inli), $this->store->dedupKey($cdc));
    }

    /** First sighting is new; the second is not. This is the whole seen-set contract. */
    public function testAListingIsNewExactlyOnce(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        self::assertTrue($this->store->record($listing, 950, '2026-08-07T09:00:00+00:00')->isNew);
        self::assertFalse($this->store->record($listing, 950, '2026-08-07T10:00:00+00:00')->isNew);
    }

    /** A rent DROP on a listing already seen is its own notification-worthy event (spec §7). */
    public function testARentDropIsReported(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $second = $this->store->record($listing, 940, '2026-08-08T09:00:00+00:00');

        self::assertFalse($second->isNew);
        self::assertSame(1000, $second->previousRentCc);
        self::assertSame(-60, $second->rentDeltaCc);
        self::assertTrue($second->isPriceDrop);
    }

    /** A rent RISE is recorded but is not a price drop — the event is a drop, not a change. */
    public function testARentRiseIsRecordedButIsNotADrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 940, '2026-08-07T09:00:00+00:00');
        $second = $this->store->record($listing, 1000, '2026-08-08T09:00:00+00:00');

        self::assertSame(60, $second->rentDeltaCc);
        self::assertFalse($second->isPriceDrop);
    }

    /**
     * `null` IS NOT ZERO, and here it is not a price drop either — `CLAUDE.md` hard rule 9.
     *
     * A source that stops publishing the rent, or one that never published it, must not read as a
     * drop to 0. This is the trap in its natural habitat: `1000 → null` is "the listing stopped
     * saying", and treating it as a 1000-euro reduction would fire the loudest notification the
     * system has on the least information it has ever had.
     */
    public function testAnUnknownRentIsNeverAPriceDrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $vanished = $this->store->record($listing, null, '2026-08-08T09:00:00+00:00');

        self::assertFalse($vanished->isPriceDrop);
        self::assertNull($vanished->rentDeltaCc);

        // …and the reverse: appearing for the first time is not a drop from nothing.
        $appeared = $this->store->record($this->listing(externalId: 'ANN-2'), null, '2026-08-07T09:00:00+00:00');
        $priced = $this->store->record($this->listing(externalId: 'ANN-2'), 900, '2026-08-08T09:00:00+00:00');

        self::assertTrue($appeared->isNew);
        self::assertFalse($priced->isPriceDrop);
        self::assertNull($priced->rentDeltaCc);
    }

    /**
     * A rent that vanishes and comes back LOWER is still a drop.
     *
     * The last KNOWN figure is kept when a source stops publishing the rent, rather than being
     * overwritten with the null. Overwriting reads as "we never knew", so the return at 940 would
     * look like a first sighting of the price and the drop — the notification-worthy event — would
     * be silently swallowed. That failure leaves no trace at all: nothing arrives, and nothing says
     * why.
     */
    public function testARentThatVanishesAndReturnsLowerIsStillADrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($listing, null, '2026-08-08T09:00:00+00:00');
        $back = $this->store->record($listing, 940, '2026-08-09T09:00:00+00:00');

        self::assertSame(1000, $back->previousRentCc);
        self::assertSame(-60, $back->rentDeltaCc);
        self::assertTrue($back->isPriceDrop);
        self::assertSame([1000, 940], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** Price history is append-only, and a rent that did not change adds no row. */
    public function testPriceHistoryRecordsEveryChangeAndOnlyChanges(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-08T09:00:00+00:00');
        $this->store->record($listing, 940, '2026-08-09T09:00:00+00:00');

        self::assertSame([1000, 940], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** Notifying is recorded separately from seeing: a digested listing was seen, not notified. */
    public function testNotificationIsRecordedSeparatelyFromSighting(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');
        $key = $this->store->dedupKey($listing);

        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');
        self::assertFalse($this->store->wasNotified($key));

        $this->store->markNotified($key, '2026-08-07T09:05:00+00:00');
        self::assertTrue($this->store->wasNotified($key));
    }

    // ── Source health (spec §8) ───────────────────────────────────────────────────────────────────

    /** A source that has never run is NEVER_RUN, not OK — absence of failure is not health. */
    public function testASourceThatHasNeverRunIsNotReportedHealthy(): void
    {
        self::assertSame(SourceStatus::NEVER_RUN, $this->store->health('inli')->status);
    }

    /** Three consecutive empty runs against a non-zero baseline is BROKEN (spec §8). */
    public function testThreeConsecutiveEmptyRunsAgainstANonZeroBaselineIsBroken(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $day) {
            $this->store->recordRun('inli', 12, true, null, $day . 'T09:00:00+00:00');
        }

        foreach (['2026-08-04', '2026-08-05', '2026-08-06'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertSame(3, $health->consecutiveEmptyRuns);
    }

    /**
     * A source whose baseline IS zero is not broken for returning zero.
     *
     * A genuinely quiet source — one with no matching stock this week — must not be reported as a
     * broken selector, or the alert becomes noise and stops being read. This is the distinction
     * spec §8 draws with "when its baseline is non-zero".
     */
    public function testASourceWithAZeroBaselineIsNotBroken(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            $this->store->recordRun('quiet_source', 0, true, null, $day . 'T09:00:00+00:00');
        }

        self::assertNotSame(SourceStatus::BROKEN, $this->store->health('quiet_source')->status);
    }

    /** A >70% drop below the rolling mean warns (spec §8). */
    public function testALargeDropBelowTheRollingMeanWarns(): void
    {
        foreach (range(1, 7) as $day) {
            $this->store->recordRun('inli', 20, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $this->store->recordRun('inli', 3, true, null, '2026-08-08T09:00:00+00:00');

        self::assertSame(SourceStatus::WARN_DROP, $this->store->health('inli')->status);
    }

    /** A failed fetch is a failure, not an empty result — `CLAUDE.md` hard rule 3. */
    public function testAFailedRunIsDistinguishedFromAnEmptyOne(): void
    {
        $this->store->recordRun('inli', 12, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 0, false, 'HTTP 503', '2026-08-02T09:00:00+00:00');

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('HTTP 503', $health->detail);
    }

    // ── Identity details the fallback key depends on ──────────────────────────────────────────────
    //
    // These cover normalisation choices made while implementing `dedupKey()`, not in the contract
    // above. Each is a way the SAME flat could come back with a different key on the next run — and
    // a key that churns means every listing looks new every run, which is the notification storm
    // from the other direction.

    /** A fragment is a scroll position, never an identity. */
    public function testTheUrlFragmentIsNotPartOfIdentity(): void
    {
        $plain = $this->listing(externalId: '', url: 'https://a.test/annonce/1');
        $anchored = $this->listing(externalId: '', url: 'https://a.test/annonce/1#photos');

        self::assertSame($this->store->dedupKey($plain), $this->store->dedupKey($anchored));
    }

    /**
     * Host case is not identity (RFC 3986); path case IS.
     *
     * Folding the path too would be the tempting simplification, and it is the wrong direction:
     * over-merging hides a flat completely, while under-merging only costs a duplicate notification.
     */
    public function testTheUrlHostIsCaseInsensitiveButThePathIsNot(): void
    {
        $lower = $this->listing(externalId: '', url: 'https://a.test/Annonce/1');
        $shoutedHost = $this->listing(externalId: '', url: 'https://A.TEST/Annonce/1');
        $shoutedPath = $this->listing(externalId: '', url: 'https://a.test/ANNONCE/1');

        self::assertSame($this->store->dedupKey($lower), $this->store->dedupKey($shoutedHost));
        self::assertNotSame($this->store->dedupKey($lower), $this->store->dedupKey($shoutedPath));
    }

    /** Landlords re-case and re-accent their own titles between runs; that is not a new flat. */
    public function testATitleRecasedOrReaccentedIsTheSameListing(): void
    {
        $a = $this->listing(externalId: '', title: 'Appartement à Cergy');
        $b = $this->listing(externalId: '', title: 'APPARTEMENT A CERGY');

        self::assertSame($this->store->dedupKey($a), $this->store->dedupKey($b));
    }

    // ── Failure paths, which must be loud ─────────────────────────────────────────────────────────

    /** A silent no-op here would leave the listing un-notified and re-notify it on every later run. */
    public function testMarkingAnUnknownListingNotifiedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->markNotified('inli:id:never-recorded', '2026-08-07T09:00:00+00:00');
    }

    /**
     * A timestamp that is not a full ISO-8601 instant is refused, not reinterpreted.
     *
     * `new DateTimeImmutable('')` silently means "now". Since the timestamp orders the price history
     * and defines the health window, a reinterpreted one corrupts both with no visible symptom.
     */
    public function testAnUnreadableTimestampIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->recordRun('inli', 3, true, null, 'hier matin');
    }

    public function testPriceHistoryOfAnUnknownListingIsEmpty(): void
    {
        self::assertSame([], $this->store->priceHistory('inli:id:never-recorded'));
    }

    /** A failed run is not an empty run — the source did not answer, it did not answer "nothing". */
    public function testAFailedRunDoesNotExtendTheEmptyStreak(): void
    {
        $this->store->recordRun('inli', 12, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 0, true, null, '2026-08-02T09:00:00+00:00');
        $this->store->recordRun('inli', 0, true, null, '2026-08-03T09:00:00+00:00');
        $this->store->recordRun('inli', 0, false, 'timeout', '2026-08-04T09:00:00+00:00');

        self::assertSame(0, $this->store->health('inli')->consecutiveEmptyRuns);
    }

    /** A source that starts producing again is healthy again; the streak is trailing, not cumulative. */
    public function testASourceThatRecoversIsHealthyAgain(): void
    {
        $this->store->recordRun('inli', 12, true, null, '2026-08-01T09:00:00+00:00');

        foreach (['2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        self::assertSame(SourceStatus::BROKEN, $this->store->health('inli')->status);

        $this->store->recordRun('inli', 11, true, null, '2026-08-05T09:00:00+00:00');

        self::assertSame(SourceStatus::OK, $this->store->health('inli')->status);
    }

    // ── Persistence, which is the entire point ────────────────────────────────────────────────────

    /**
     * The seen-set survives closing the process. This is the test that would have caught a store
     * that works perfectly in memory and writes nothing — which looks identical in every test above.
     */
    public function testTheSeenSetAndPriceHistorySurviveReopening(): void
    {
        $path = $this->temporaryDatabase();
        $listing = $this->listing(externalId: 'ANN-1');

        $first = Store::open($path);
        self::assertTrue($first->record($listing, 1000, '2026-08-07T09:00:00+00:00')->isNew);
        $first->markNotified($first->dedupKey($listing), '2026-08-07T09:05:00+00:00');
        unset($first);

        $reopened = Store::open($path);
        $second = $reopened->record($listing, 940, '2026-08-08T09:00:00+00:00');

        self::assertFalse($second->isNew);
        self::assertTrue($second->isPriceDrop);
        self::assertTrue($reopened->wasNotified($reopened->dedupKey($listing)));
        self::assertSame([1000, 940], $reopened->priceHistory($reopened->dedupKey($listing)));
    }

    /**
     * A database written by NEWER code is refused rather than operated on blindly.
     *
     * Guessing at a schema you do not understand is how a seen-set gets corrupted, and the cost of
     * a corrupted seen-set is the notification storm.
     */
    public function testADatabaseFromANewerSchemaIsRefused(): void
    {
        $path = $this->temporaryDatabase();
        Store::open($path);

        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->prepare("UPDATE schema_meta SET value = :v WHERE key = 'schema_version'")
            ->execute(['v' => (string) (Store::SCHEMA_VERSION + 1)]);
        unset($raw);

        $this->expectException(\RuntimeException::class);
        Store::open($path);
    }

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryPaths = [];
    }

    private function temporaryDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rentwatch-store-');

        if ($path === false) {
            self::fail('impossible de créer un fichier temporaire pour le test');
        }

        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @param array<string,string> $fields */
    private function listing(
        string $source = 'inli',
        string $externalId = 'ANN-1',
        string $url = 'https://inli.test/annonce/1',
        string $title = 'T3 Cergy',
        array $fields = [],
    ): RawListing {
        return new RawListing(
            sourceName: $source,
            externalId: $externalId,
            title: $title,
            description: 'Bel appartement.',
            fields: $fields,
            url: $url,
        );
    }
}
