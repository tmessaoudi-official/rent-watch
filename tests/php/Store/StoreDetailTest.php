<?php

declare(strict_types=1);

namespace RentWatch\Tests\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Store\Store;

/**
 * Schema v5's `listing_detail` cache — what a listing's own detail page said, read ONCE.
 *
 * The cache exists because hydration without persistence is worse than no hydration at all: a
 * listing the title filter REJECTED on the pass that hydrated it comes back as a bare card on the
 * next pass, passes the criteria, and notifies. Every guarantee below is aimed at one of the two
 * silent failures that surround it — re-fetching for ever (a crawl of somebody else's site, visible
 * only in their access log) and never re-fetching a page that failed (a listing permanently missing
 * a field, while the source's health stays green).
 *
 * Categories per `CLAUDE.md` § "Testing": **identity** (nothing collapses onto a shared key),
 * **persistence** (it survives reopening and an older schema is upgraded), **failure paths** (a
 * failure is recorded AS a failure and erases nothing), **health** (exhaustion is countable).
 */
#[CoversClass(Store::class)]
final class StoreDetailTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
    }

    // ── persistence ───────────────────────────────────────────────────────────────────────────────

    /**
     * A freshly-opened store reports the version the code declares.
     *
     * Deliberately symbolic on the constant rather than repeating the literal: the assertion worth
     * having is that the file on disk agrees with the code, not that someone remembered to edit a
     * number in two places. The literal is pinned once, below, so a bump is still a visible choice.
     */
    public function testAFreshStoreIsAtTheCurrentSchemaVersion(): void
    {
        self::assertSame(9, Store::SCHEMA_VERSION, 'v7 added listings.evidence_json and listings.outcome; v8 added listings.notified_as; v9 added the commute_cache table');
        self::assertSame(Store::SCHEMA_VERSION, $this->store->schemaVersion());
    }

    public function testNeverAttemptedIsNullAndNotAnEmptyRow(): void
    {
        self::assertNull($this->store->detail('inli', 'PRV-1'));
    }

    public function testAHydrationSurvivesReopening(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rw-detail-') ?: self::fail('no temp file');

        try {
            $first = Store::open($path);
            $first->recordDetail('inli', 'PRV-1', 'https://x/1', ['title' => 'T4 Sartrouville'], '2026-08-23T10:00:00+02:00');
            unset($first);

            $detail = Store::open($path)->detail('inli', 'PRV-1');

            self::assertNotNull($detail);
            self::assertSame(['title' => 'T4 Sartrouville'], $detail->fields);
            self::assertSame('https://x/1', $detail->urlFetched);
        } finally {
            @unlink($path);
        }
    }

    // ── identity ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The key is COMPOUND, and this is the assertion that notices half of it being dropped.
     *
     * `(source, external_id)` is exactly the shape a refactor reduces to `external_id` without
     * anything going red — until two landlords both number a listing `1`, and one of them serves
     * the other's description to the classifier.
     */
    public function testTheSameExternalIdOnTwoSourcesIsTwoRows(): void
    {
        $this->store->recordDetail('inli', '1', 'https://inli/1', ['title' => 'chez In\'li'], '2026-08-23T10:00:00+02:00');
        $this->store->recordDetail('logirep', '1', 'https://logirep/1', ['title' => 'chez Logirep'], '2026-08-23T10:00:00+02:00');

        self::assertSame(['title' => 'chez In\'li'], $this->store->detail('inli', '1')?->fields);
        self::assertSame(['title' => 'chez Logirep'], $this->store->detail('logirep', '1')?->fields);
    }

    /**
     * Hard rule 9 across the SQL/JSON boundary, which is the one place it keeps going wrong.
     *
     * The fields are stored as raw strings, so `"0"` must come back as the string `"0"` and not as
     * an absent key — `Payload::floor()` reads RDC as 0, and a 0 that round-trips to null is the
     * display twin of rejecting a listing for not stating a floor.
     */
    public function testAZeroFloorAndAnEmptyStringSurviveTheRoundTrip(): void
    {
        $this->store->recordDetail('inli', 'RDC', null, [
            'floor' => '0',
            'description' => '',
        ], '2026-08-23T10:00:00+02:00');

        $fields = $this->store->detail('inli', 'RDC')?->fields;

        self::assertSame(['floor' => '0', 'description' => ''], $fields);
        self::assertArrayHasKey('floor', $fields ?? [], 'a zero floor is a fact, not an absence');
    }

    /** Read and found nothing is a SUCCESS, and must not read back as never attempted. */
    public function testAnEmptyHydrationIsStoredAsReadNotAsMissing(): void
    {
        $this->store->recordDetail('inli', 'BARE', 'https://x/bare', [], '2026-08-23T10:00:00+02:00');

        $detail = $this->store->detail('inli', 'BARE');

        self::assertNotNull($detail);
        self::assertSame([], $detail->fields);
        self::assertTrue($detail->isHydrated(), 'read-and-bare owes no further request');
    }

    // ── failure paths ─────────────────────────────────────────────────────────────────────────────

    public function testAFailureIsRecordedAsAFailureAndNotAsAnEmptyHydration(): void
    {
        $this->store->recordDetailFailure('inli', 'GONE', 'HTTP 404', '2026-08-23T10:00:00+02:00');

        $detail = $this->store->detail('inli', 'GONE');

        self::assertNotNull($detail);
        self::assertNull($detail->fields, 'tried-and-failed is not read-and-bare');
        self::assertFalse($detail->isHydrated());
        self::assertSame(1, $detail->attempts);
        self::assertSame('HTTP 404', $detail->lastError);
    }

    public function testAttemptsAccumulateSoABackoffCanSeeThem(): void
    {
        $this->store->recordDetailFailure('inli', 'FLAKY', 'HTTP 500', '2026-08-23T10:00:00+02:00');
        $this->store->recordDetailFailure('inli', 'FLAKY', 'HTTP 500', '2026-08-23T10:20:00+02:00');
        $this->store->recordDetailFailure('inli', 'FLAKY', 'timeout', '2026-08-23T10:40:00+02:00');

        $detail = $this->store->detail('inli', 'FLAKY');

        self::assertSame(3, $detail?->attempts);
        self::assertSame('timeout', $detail?->lastError, 'the most recent failure, not the first');
        self::assertSame('2026-08-23T10:40:00+02:00', $detail?->lastAttemptAt);
    }

    /**
     * A source that starts failing must not ERASE what it told us last week.
     *
     * The stored fields stay usable and the failure is recorded beside them. The opposite — a
     * failure blanking the row — would turn one bad afternoon into a permanent loss of every
     * hydrated title on the source, and it would look like the pages had simply gone bare.
     */
    public function testAFailureAfterASuccessKeepsTheHydration(): void
    {
        $this->store->recordDetail('inli', 'PRV-9', 'https://x/9', ['title' => 'kept'], '2026-08-23T10:00:00+02:00');
        $this->store->recordDetailFailure('inli', 'PRV-9', 'HTTP 503', '2026-08-24T10:00:00+02:00');

        $detail = $this->store->detail('inli', 'PRV-9');

        self::assertSame(['title' => 'kept'], $detail?->fields);
        self::assertTrue($detail?->isHydrated());
        self::assertSame('HTTP 503', $detail?->lastError);
        self::assertSame(2, $detail?->attempts);
    }

    /** And the reverse: a success after failures clears the error rather than leaving a stale one. */
    public function testASuccessAfterAFailureClearsTheError(): void
    {
        $this->store->recordDetailFailure('inli', 'PRV-8', 'HTTP 500', '2026-08-23T10:00:00+02:00');
        $this->store->recordDetail('inli', 'PRV-8', 'https://x/8', ['title' => 'finally'], '2026-08-23T10:20:00+02:00');

        $detail = $this->store->detail('inli', 'PRV-8');

        self::assertSame(['title' => 'finally'], $detail?->fields);
        self::assertNull($detail?->lastError);
        self::assertSame(2, $detail?->attempts, 'the failed attempt still counts');
        self::assertSame('2026-08-23T10:20:00+02:00', $detail?->fetchedAt);
    }

    // ── health ────────────────────────────────────────────────────────────────────────────────────

    public function testFailureCountSeesOnlyNeverSucceededRowsOnItsOwnSource(): void
    {
        $this->store->recordDetailFailure('inli', 'A', 'HTTP 404', '2026-08-23T10:00:00+02:00');
        $this->store->recordDetailFailure('inli', 'B', 'HTTP 404', '2026-08-23T10:00:00+02:00');
        $this->store->recordDetailFailure('inli', 'B', 'HTTP 404', '2026-08-23T10:20:00+02:00');
        // Succeeded once, so it is not owed and not broken.
        $this->store->recordDetail('inli', 'C', null, ['title' => 'ok'], '2026-08-23T10:00:00+02:00');
        // Another landlord's problem is not this one's.
        $this->store->recordDetailFailure('logirep', 'D', 'HTTP 404', '2026-08-23T10:00:00+02:00');

        self::assertSame(2, $this->store->detailFailureCount('inli'));
        self::assertSame(1, $this->store->detailFailureCount('inli', minAttempts: 2), 'only B has two');
        self::assertSame(1, $this->store->detailFailureCount('logirep'));
        self::assertSame(0, $this->store->detailFailureCount('cityloger'));
    }

    // ------------------------------------------------------------------ persistence: map drift

    /**
     * A row captured under one detail map must not be served after the map changes.
     *
     * This is the hole Phase 2 left and Phase 2b walked into. Rows are keyed `(source,
     * external_id)` and a page on record costs no request ever again — so adding `floor` and
     * `elevator` to In'li's map would leave every already-hydrated row serving title+description
     * FOR EVER. No refetch, no error, no signal: the config would say the fields are collected and
     * the listings would never carry them.
     *
     * A fingerprint mismatch reads as ABSENT, so the row is refetched through the ordinary budget
     * and priority path — no new mechanism, and the per-pass cost stays bounded.
     */
    public function testARowCapturedUnderADifferentMapIsNotServed(): void
    {
        $store = $this->store;

        $store->recordDetail('inli', 'PRV-1', 'https://x.test/1', ['title' => 'T'], '2026-08-23T10:00:00Z', 'FP-OLD');

        self::assertNotNull(
            $store->detail('inli', 'PRV-1', 'FP-OLD'),
            'the row is served while the map is unchanged',
        );
        self::assertNull(
            $store->detail('inli', 'PRV-1', 'FP-NEW'),
            'a changed map makes the cached row absent, so it is refetched rather than served stale',
        );
    }

    /**
     * A refetch under the new map REPLACES the row rather than accumulating one per map version.
     *
     * The key is still `(source, external_id)`, so this is really an assertion that the fingerprint
     * did not quietly become part of the identity — which would orphan the whole cache on every map
     * edit instead of refreshing it, and grow the table without bound.
     */
    public function testARefetchUnderTheNewMapReplacesTheRow(): void
    {
        $store = $this->store;

        $store->recordDetail('inli', 'PRV-1', 'https://x.test/1', ['title' => 'T'], '2026-08-23T10:00:00Z', 'FP-OLD');
        $store->recordDetail('inli', 'PRV-1', 'https://x.test/1', ['title' => 'T', 'floor' => '3'], '2026-08-23T11:00:00Z', 'FP-NEW');

        $row = $store->detail('inli', 'PRV-1', 'FP-NEW');

        self::assertNotNull($row);
        self::assertSame(['title' => 'T', 'floor' => '3'], $row->fields);
        self::assertSame(2, $row->attempts, 'it is the same row, on its second attempt');
        self::assertNull($store->detail('inli', 'PRV-1', 'FP-OLD'), 'the old fingerprint is gone, not kept beside it');
    }
}
