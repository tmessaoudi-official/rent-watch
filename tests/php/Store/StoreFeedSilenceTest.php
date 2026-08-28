<?php

declare(strict_types=1);

namespace Scout\Tests\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\SourceStatus;
use Scout\Store\Store;

/**
 * A source can keep REPORTING while its feed has stopped DELIVERING, and nothing saw it.
 *
 * **Measured on the live watcher, 2026-08-28.** `leboncoin` had reported `item_count = 3` on 263
 * consecutive passes. Behind that steady figure was ONE email, dated 26 August: the source was
 * re-reading the same message every fifteen minutes because `SEARCH SINCE 7 days` kept matching it.
 * Every existing verdict said healthy, and each was right on its own terms — the baseline is 3, the
 * last count is 3, so there is no drop; no run failed, so nothing is flaky; the schedule never
 * stopped, so it is not `STALE`. `CLAUDE.md` hard rule 2's exact shape, reached without a single
 * `catch` and with no test able to see it.
 *
 * **It self-corrects in the worst possible way.** On or about 2 September the 26 August message
 * falls out of the `SEARCH SINCE` window, the count goes 3 → 0 with nothing changing in the world,
 * and `BROKEN` finally fires naming the wrong day and the wrong cause — a week after the fact.
 *
 * **Why the obvious signal is the wrong one, and this is the whole design.** *"Warn when no NEW
 * listing has arrived for N days"* is exactly what a quiet rental market looks like, so it restates
 * hard rule 2's ambiguity instead of resolving it: Logirep legitimately returns the same 113
 * listings on every pass. The age of the newest MESSAGE is different in kind — it is a fact about
 * whether the portal sent anything, never an inference about the market. *"leboncoin has not sent
 * anything in three days"* is checkable; *"no new listings"* is not.
 */
#[CoversClass(Store::class)]
final class StoreFeedSilenceTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
    }

    /**
     * The leboncoin case, reproduced: a steady non-zero count off one ageing message.
     *
     * Every figure here is the live one. Three listings a pass, from a message dated 26 August,
     * judged on 29 August — the day a three-day threshold first bites, and four days before the
     * count would have collapsed on its own.
     */
    public function testASteadyCountOffAnAgeingMessageIsFeedSilentRatherThanOk(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->store->recordRun(
                sourceName: 'leboncoin',
                itemCount: 3,
                ok: true,
                error: null,
                atIso: sprintf('2026-08-%02dT%02d:00:00Z', 26 + intdiv($i, 8), $i % 8 + 8),
                feedNewestAt: '2026-08-26T07:33:06Z',
            );
        }

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('26', $health->detail, 'the detail must name the last message');
        self::assertTrue($health->status->isAlerting(), 'a silent feed that never reaches the user is worthless');
    }

    /** Inside the threshold the feed is simply quiet, and quiet is not broken. */
    public function testAMessageInsideTheThresholdIsStillOk(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-28T09:00:00Z', feedNewestAt: '2026-08-27T07:33:06Z');

        self::assertSame(SourceStatus::OK, $this->store->health('leboncoin', '2026-08-28T09:00:00Z', 3)->status);
    }

    /**
     * Hard rule 9 at the health layer: an unknown message date is UNKNOWN, never old.
     *
     * `FileMailbox` reports `null` on purpose — a directory of frozen fixtures is not a feed — and
     * every row written before schema v11 reads `null` too. Either read as "old" would turn the
     * documented `MAILBOX_DIR=…` workflow, and the entire historic run log, into a permanent alert.
     */
    public function testAnUnknownMessageDateYieldsNoVerdict(): void
    {
        // Judged three days after the run — past the threshold, and well inside the rolling window
        // so `STALE` (the schedule stopped) cannot fire and steal the verdict. A first draft used a
        // month, which tripped STALE and proved nothing about this branch.
        $this->store->recordRun('inli', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: null);

        self::assertSame(SourceStatus::OK, $this->store->health('inli', '2026-09-01T09:00:00Z', 3)->status);
    }

    /**
     * A FUTURE-dated message must not mask silence for ever.
     *
     * The verdict reduces every reported date to their maximum, so one portal with a skewed clock —
     * or one message stamped 2030 — would otherwise WIN that maximum and report the feed as fresh
     * for ever, suppressing the only signal that distinguishes a dead alert from a quiet market.
     * `Core/Heartbeat`'s bias applies unchanged: one alert too many, never one suppressed.
     */
    public function testAFutureDatedMessageCannotMaskAnAgeingFeed(): void
    {
        // The real shape of the risk, and the one a first draft missed: the verdict reduces many
        // reported dates to their MAXIMUM, so a single bogus 2030 stamp beside a genuine 26 August
        // one wins that maximum and reports the feed as fresh for ever. Clamping the bogus date to
        // `now` does not help — it makes it read as fresher still.
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2030-01-01T00:00:00Z');

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('26', $health->detail, 'the credible date decides, not the newest');
        self::assertStringNotContainsString('2030', $health->detail);
    }

    /**
     * When NOTHING reported is believable, say so rather than fall silent.
     *
     * A portal with a permanently fast clock would otherwise disable this verdict for ever, which is
     * the suppressed direction `Core/Heartbeat` rules against. The existing `STALE` branch says the
     * same thing about run timestamps; this mirrors it for message dates.
     */
    public function testAllFutureDatesAreReportedRatherThanIgnored(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2030-01-01T00:00:00Z');

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('horloge', $health->detail);
    }

    /**
     * A silent feed is a verdict about a WORKING source, so it never outranks a broken one.
     *
     * The failure paths know a cause; this one only knows an absence. Reporting `FEED_SILENT` on a
     * source whose last run threw would name the symptom and bury the exception.
     */
    public function testAFailedRunOutranksASilentFeed(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');
        $this->store->recordRun('leboncoin', 0, false, 'IMAP connection timed out', '2026-08-29T09:00:00Z', feedNewestAt: null);

        self::assertSame(SourceStatus::BROKEN, $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3)->status);
    }

    /**
     * When the count DOES collapse, the detail names the day the feed actually went quiet.
     *
     * This is the other half of the leboncoin fix and it is the one that survives the threshold
     * being wrong. Even if `FEED_SILENT` never fires, `BROKEN` must stop blaming the day the window
     * expired: the message is three days older than the collapse, and the operator needs the former.
     */
    public function testABrokenSourceNamesTheLastMessageItSaw(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');

        for ($i = 0; $i < 4; ++$i) {
            $this->store->recordRun('leboncoin', 0, true, null, sprintf('2026-09-0%dT09:00:00Z', 2 + $i), feedNewestAt: null);
        }

        $health = $this->store->health('leboncoin', '2026-09-06T09:00:00Z', 3);

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('26', $health->detail, 'BROKEN must name the last message, not just the empty streak');
    }

    /** A threshold of zero disables the one signal that distinguishes a dead alert from a quiet market. */
    public function testAThresholdOfZeroIsRefused(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');

        $this->expectException(\InvalidArgumentException::class);
        $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 0);
    }
}
