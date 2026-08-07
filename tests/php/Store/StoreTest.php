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

    // ── Identity: nothing may collapse onto a shared key ──────────────────────────────────────────

    /**
     * `trim()` strips seven ASCII bytes and nothing else.
     *
     * U+00A0 — what a decoded `&nbsp;` produces, and what `Text` itself calls a real adapter
     * artefact — survives it. An `externalId` of one no-break space therefore passed the "does this
     * source publish an id?" test, and EVERY listing in the run collapsed onto `:id:%C2%A0`. The
     * second flat is never notified, and the miss is indistinguishable from a quiet market.
     */
    public function testAnExternalIdOfOnlyUnicodeWhitespaceIsNoIdAtAll(): void
    {
        foreach (["\u{00A0}", "\u{2007}", "\u{202F}", "\u{3000}", "\u{200B}", "\u{FEFF}", " \t "] as $blank) {
            $a = $this->listing(externalId: $blank, url: 'https://a.test/1', title: 'T2 Nanterre');
            $b = $this->listing(externalId: $blank, url: 'https://a.test/2', title: 'T4 Meudon');

            self::assertNotSame(
                $this->store->dedupKey($a),
                $this->store->dedupKey($b),
                sprintf('two distinct flats collided on a blank id (U+%04X)', mb_ord($blank) ?: 0),
            );
        }
    }

    /** A padded id is the same id — otherwise the flat looks new every run. */
    public function testAPaddedExternalIdIsTheSameId(): void
    {
        self::assertSame(
            $this->store->dedupKey($this->listing(externalId: 'ANN-42')),
            $this->store->dedupKey($this->listing(externalId: "\u{00A0}ANN-42 ")),
        );
    }

    /**
     * A listing with no id, no URL and no title is refused, loudly.
     *
     * The fallback would otherwise hash `"\n"` — a plausible-looking key that every such listing
     * shares, so the second one silently vanishes and the price history of the first interleaves
     * two flats' rents. It is the shape a broken title selector plus a broken link selector takes,
     * and the item count stays non-zero so `health()` reports OK throughout.
     */
    public function testAListingWithNothingIdentifyingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->dedupKey($this->listing(externalId: '', url: '', title: ''));
    }

    // ── Out-of-order sightings, which the IMAP path makes routine ─────────────────────────────────

    /**
     * A replayed older sighting must not manufacture a price drop.
     *
     * Email-alert ingestion is the primary path (`CLAUDE.md` hard rule 4) and delivers out of
     * publication order routinely — an initial backfill, a provider-delayed alert. Comparing
     * against whatever was written LAST rather than against the chronologically preceding rent
     * fired a drop notification for a rent that had never moved.
     */
    public function testAStaleSightingManufacturesNoPriceDrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-06T09:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($listing, 1200, '2026-08-01T09:00:00+00:00');   // an archived run, replayed
        $latest = $this->store->record($listing, 1000, '2026-08-08T09:00:00+00:00');

        self::assertFalse($latest->isPriceDrop);
        self::assertSame(0, $latest->rentDeltaCc, 'the rent never moved, so the change is zero — not a 200-euro cut');
        self::assertSame([1200, 1000], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** The changes-only invariant survives an insertion between two equal rents. */
    public function testPriceHistoryStaysChangesOnlyUnderOutOfOrderSightings(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-06T09:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-01T09:00:00+00:00');

        self::assertSame([1000], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** A stale sighting does not roll the current state backwards. */
    public function testAStaleSightingDoesNotOverwriteTheCurrentState(): void
    {
        $listing = $this->listing(externalId: 'ANN-1', title: 'T3 Cergy', url: 'https://a.test/new');
        $stale = $this->listing(externalId: 'ANN-1', title: 'ANCIEN TITRE', url: 'https://a.test/old');

        $this->store->record($listing, 940, '2026-08-08T09:00:00+00:00');
        $this->store->record($stale, 1200, '2026-08-01T09:00:00+00:00');

        $snapshot = $this->store->snapshot($this->store->dedupKey($listing));

        self::assertNotNull($snapshot);
        self::assertSame('T3 Cergy', $snapshot->title);
        self::assertSame('https://a.test/new', $snapshot->url);
        self::assertSame(940, $snapshot->rentCc);
    }

    /**
     * A run whose field map partially broke must not erase what we already knew.
     *
     * The rent case was defended in the commit that introduced it; the URL and title were the same
     * bug, unnoticed. Markup drift is the premise of the whole health module, so one sighting with
     * a missed link selector is the expected case — and it left the seen-set holding a listing
     * with no link and no title, which is exactly the pair a notification needs to be actionable.
     */
    public function testAPartialReParseDoesNotEraseTheUrlOrTitle(): void
    {
        $full = $this->listing(externalId: 'ANN-1', url: 'https://a.test/annonce/1', title: 'T3 Cergy 68 m²');
        $partial = new RawListing(sourceName: 'inli', externalId: 'ANN-1');   // url null, title ''

        $this->store->record($full, 1450, '2026-08-07T09:00:00+00:00');
        $this->store->record($partial, null, '2026-08-08T09:00:00+00:00');

        $snapshot = $this->store->snapshot($this->store->dedupKey($full));

        self::assertNotNull($snapshot);
        self::assertSame('https://a.test/annonce/1', $snapshot->url);
        self::assertSame('T3 Cergy 68 m²', $snapshot->title);
        self::assertSame(1450, $snapshot->rentCc);
    }

    // ── Timestamps ────────────────────────────────────────────────────────────────────────────────

    /**
     * A trailing `Z` is the UTC instant, whatever the host timezone.
     *
     * `\Z` in a `createFromFormat` pattern is decoration: the instant is built in the DEFAULT
     * timezone and the Z discarded. On `Europe/Paris` — the natural deployment for an
     * Île-de-France tool — `…T10:30:00Z` therefore landed at 08:30 UTC, sorting BEFORE a 09:00 UTC
     * sibling written with an offset and inverting the price history, so a rise read as a drop.
     */
    public function testATrailingZIsTheUtcInstantWhateverTheHostTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $listing = $this->listing(externalId: 'ANN-1');

            $this->store->record($listing, 1000, '2026-07-01T10:30:00Z');            // 10:30 UTC
            $this->store->record($listing, 1200, '2026-07-01T11:00:00+02:00');       // 09:00 UTC — earlier

            self::assertSame([1200, 1000], $this->store->priceHistory($this->store->dedupKey($listing)));
        } finally {
            date_default_timezone_set($original);
        }
    }

    /** A valid UTC instant inside the spring DST gap is an instant, not an error. */
    public function testAnInstantInsideTheSpringDstGapIsAccepted(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $this->store->recordRun('inli', 4, true, null, '2026-03-29T02:30:00Z');

            self::assertSame(4, $this->store->health('inli')->lastCount);
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * A date that does not exist is refused rather than rolled forward.
     *
     * `createFromFormat` silently normalises `2026-02-30` to 2 March. The round-trip check is what
     * makes the parse strict, and `'hier matin'` — which `createFromFormat` rejects outright —
     * never exercised it.
     */
    public function testADateThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->recordRun('inli', 3, true, null, '2026-02-30T09:00:00+00:00');
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

    /**
     * An OLDER database is refused too, and that direction was the real gap.
     *
     * `CREATE TABLE IF NOT EXISTS` adds no columns to a table that already exists, and nothing
     * re-stamped `schema_meta` — so the day `SCHEMA_VERSION` becomes 2, every existing user
     * database would have opened silently as v1 forever, with `schemaVersion()` reporting 1 and
     * nothing downstream able to detect the mismatch.
     */
    public function testADatabaseFromAnOlderSchemaIsRefused(): void
    {
        $path = $this->temporaryDatabase();
        Store::open($path);

        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->exec("UPDATE schema_meta SET value = '0' WHERE key = 'schema_version'");
        unset($raw);

        $this->expectException(\RuntimeException::class);
        Store::open($path);
    }

    /** A database path whose parent is a regular file fails with this class's own message. */
    public function testADatabasePathThatTraversesAFileIsRefused(): void
    {
        $file = $this->temporaryDatabase();

        $this->expectException(\RuntimeException::class);
        Store::open($file . '/store.db');
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

    // ── Source health: the two ways a dead source reported OK ─────────────────────────────────────

    /**
     * A source that breaks after a GAP longer than the rolling window is still broken.
     *
     * `rollingMeanBefore()` anchors its window on the first empty run of the streak, so any gap
     * longer than the window — a holiday, a reclaimed container, a temporarily disabled source —
     * left it with nothing to average and returned null. Null is "I do not know what normal looks
     * like for this source", and it shared a branch with "normal is zero": ten consecutive empty
     * runs against a documented 25-listing history reported `OK`, detail *"0 annonces au dernier
     * run"*. The identical sequence WITHOUT the gap alerted correctly, which is what made it
     * invisible — it works in the test and fails in the field.
     */
    public function testASourceThatBreaksAfterALongGapIsStillReportedBroken(): void
    {
        foreach (range(1, 14) as $day) {
            $this->store->recordRun('inli', 25, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        // Nine days off — longer than ROLLING_WINDOW_DAYS. The site redesigns meanwhile.
        foreach (['2026-08-23', '2026-08-24', '2026-08-25'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('25', $health->detail);
    }

    /**
     * The run log is monotonic per source, and an out-of-order write is refused loudly.
     *
     * Timestamps are caller-supplied and never checked against a clock — a deliberate choice, since
     * health can only be tested if time is an argument. The cost is that ONE run stamped from a
     * skewed clock (NTP not yet synced after a resume, a VM restored from a snapshot, a caller
     * passing the listing's published date instead of the run time) sorts last forever and makes
     * every later run invisible to `health()`: twenty consecutive outright failures reported `OK`,
     * detail cheerfully naming the 25 listings of the skewed run. Refusing the write converts that
     * silent freeze into a diagnosable one. Unlike a sighting, a run has no legitimate
     * out-of-order case — it is logged when it happens.
     */
    public function testARunMayNotBeLoggedBeforeOneAlreadyRecorded(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2027-01-01T09:00:00+00:00');   // skewed clock

        $this->expectException(\InvalidArgumentException::class);

        $this->store->recordRun('inli', 0, false, 'HTTP 503', '2026-08-07T09:00:00+00:00');
    }

    /**
     * A source that succeeds and never once produces an item is not healthy.
     *
     * The twin of NEVER_RUN. A source onboarded with a wrong field map answers HTTP 200 and parses
     * zero items, forever: it never fails so nothing alerts, and its baseline really is zero so the
     * empty-run rule correctly declines to fire. Thirty days of `OK` for a source that has never
     * worked.
     */
    public function testASourceThatNeverProducesAnythingIsNotReportedHealthy(): void
    {
        foreach (range(1, 30) as $day) {
            $this->store->recordRun('newsource', 0, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $health = $this->store->health('newsource');

        self::assertSame(SourceStatus::NEVER_PRODUCED, $health->status);
        self::assertTrue($health->status->isAlerting());
    }

    /**
     * A source failing half its fetches is not healthy either.
     *
     * `trailingEmptyRuns()` resets on any success and the BROKEN rule reads only the last run, so
     * half the market missed every day was indistinguishable from a working source.
     */
    public function testASourceFailingHalfItsRunsIsFlagged(): void
    {
        foreach ([[1, true], [2, false], [3, true], [4, false], [5, true], [6, false], [7, true]] as [$day, $ok]) {
            $this->store->recordRun(
                'inli',
                $ok ? 12 : 0,
                $ok,
                $ok ? null : 'HTTP 503',
                sprintf('2026-08-%02dT09:00:00+00:00', $day),
            );
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::WARN_FLAKY, $health->status);
        self::assertSame(3, $health->failedRunsInWindow);
        self::assertSame(7, $health->runsInWindow);
    }

    /**
     * Every field of the verdict carries evidence, and every one is asserted.
     *
     * Five of `SourceHealth`'s fields could be replaced with constants and the whole suite stayed
     * green — including `lastSuccessAt`, `lastCount` and `rollingMean`, the three `CLAUDE.md` hard
     * rule 2 names by name. An unasserted field is a field that can silently become wrong, and the
     * class exists so a verdict can be argued with.
     */
    public function testHealthCarriesTheEvidenceForItsVerdict(): void
    {
        $this->store->recordRun('inli', 20, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 0, false, 'HTTP 503', '2026-08-02T09:00:00+00:00');
        $this->store->recordRun('inli', 18, true, null, '2026-08-03T09:00:00+00:00');
        $this->store->recordRun('inli', 19, true, null, '2026-08-04T09:00:00+00:00');

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::OK, $health->status);
        self::assertSame('inli', $health->sourceName);
        self::assertSame('2026-08-04T09:00:00+00:00', $health->lastSuccessAt);
        self::assertSame('2026-08-02T09:00:00+00:00', $health->lastFailureAt);
        self::assertSame(19, $health->lastCount);
        self::assertSame(19.0, $health->rollingMean);          // mean of 20 and 18, before the last run
        self::assertSame(4, $health->runsInWindow);
        self::assertSame(1, $health->failedRunsInWindow);
        self::assertSame(4, $health->totalRuns);
        self::assertSame(0.25, $health->failureRate());
        self::assertSame(0, $health->consecutiveEmptyRuns);
    }

    /** Exactly one status means "nothing to tell the user". */
    public function testEveryStatusExceptOkAlerts(): void
    {
        foreach (SourceStatus::cases() as $status) {
            self::assertSame(
                $status !== SourceStatus::OK,
                $status->isAlerting(),
                sprintf('%s alerts when it should not, or vice versa', $status->value),
            );
        }
    }

    /**
     * An adapter's error text is masked before it is persisted or shown.
     *
     * `recordRun()` stores it, `health()` interpolates it into the detail, and `isAlerting()` says
     * that detail should reach the user — so a cURL error carrying the IDFM key, or an IMAP failure
     * carrying the mailbox, would put a credential into the database and into a push notification
     * through a channel nobody would think to grep (`CLAUDE.md` hard rule 7). The store is the
     * single funnel every adapter passes through, so the guard belongs here.
     */
    public function testCredentialsInAnAdapterErrorAreMaskedBeforeTheyReachTheUser(): void
    {
        $this->store->recordRun(
            'transit',
            0,
            false,
            'GET https://prim.iledefrance-mobilites.fr/marketplace/v2?apikey=SECRET123456 failed for jean.dupont@example.com',
            '2026-08-07T09:00:00+00:00',
        );

        $detail = $this->store->health('transit')->detail;

        self::assertStringNotContainsString('SECRET123456', $detail);
        self::assertStringNotContainsString('jean.dupont@example.com', $detail);
        self::assertStringContainsString('prim.iledefrance-mobilites.fr', $detail);   // still diagnosable
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
