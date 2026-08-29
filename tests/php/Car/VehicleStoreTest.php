<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\SourceStatus;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleOutcome;
use Scout\Car\VehicleStore;
use Scout\Car\VehicleVerdict;

/**
 * The car store's contract, in the rent store's categories where they apply: identity, order,
 * price events, seen-set, evidence, persistence, and the composed run log.
 */
#[CoversClass(VehicleStore::class)]
final class VehicleStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/scout-car-' . bin2hex(random_bytes(6)) . '.sqlite3';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    // ── seen-set ──────────────────────────────────────────────────────────────────────────────

    public function testACarIsNewExactlyOnceAndNotifiedIsADifferentFact(): void
    {
        $store = VehicleStore::open($this->path);
        $car = $this->car('a1', 21000);

        $first = $store->record($car, '2026-08-29T10:00:00Z');
        $second = $store->record($car, '2026-08-29T10:15:00Z');

        self::assertTrue($first->isNew);
        self::assertFalse($second->isNew);
        self::assertFalse($store->wasNotified($first->dedupKey), 'seen is not notified');
        $store->markNotified($first->dedupKey, '2026-08-29T10:15:01Z');
        self::assertTrue($store->wasNotified($first->dedupKey));
    }

    public function testTheSeenSetIsEmptyOnlyBeforeAnythingWasRecorded(): void
    {
        $store = VehicleStore::open($this->path);
        self::assertTrue($store->isSeenSetEmpty(), 'Q36: a fresh file, or a missing mount');
        $store->record($this->car('a1', 21000), '2026-08-29T10:00:00Z');
        self::assertFalse($store->isSeenSetEmpty());
    }

    // ── identity ──────────────────────────────────────────────────────────────────────────────

    public function testIdentityIsScopedToTheSource(): void
    {
        $store = VehicleStore::open($this->path);
        $store->record($this->car('a1', 21000, source: 'paruvendu'), '2026-08-29T10:00:00Z');
        $other = $store->record($this->car('a1', 21000, source: 'autohero'), '2026-08-29T10:00:00Z');

        self::assertTrue($other->isNew, 'the same id on another source is another car');
        self::assertSame(['a1' => true], $store->knownExternalIds('autohero'));
    }

    public function testABlankIdIsRefusedNotCollapsedOntoAOneSharedKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VehicleStore::open($this->path)->record($this->car('  ', 21000), '2026-08-29T10:00:00Z');
    }

    // ── order / price events ──────────────────────────────────────────────────────────────────

    public function testAStaleSightingManufacturesNoPriceDropAndOverwritesNothing(): void
    {
        $store = VehicleStore::open($this->path);
        $newer = $store->record($this->car('a1', 21500), '2026-08-29T13:34:25Z');
        $older = $store->record($this->car('a1', 21000), '2026-08-28T18:31:09Z');

        self::assertFalse($older->isCurrent);
        self::assertFalse($older->isPriceDrop, 'the 128-email loop, refused at the store');
        self::assertSame([21500], $store->priceHistory($newer->dedupKey), 'the timeline is not rewritten');
        self::assertSame(21500, $store->snapshotPrice($newer->dedupKey));
    }

    public function testAGenuineDropIsADropAndARiseIsNot(): void
    {
        $store = VehicleStore::open($this->path);
        $store->record($this->car('a1', 21500), '2026-08-28T10:00:00Z');
        $drop = $store->record($this->car('a1', 20000), '2026-08-29T10:00:00Z');
        $rise = $store->record($this->car('a1', 22000), '2026-08-30T10:00:00Z');

        self::assertTrue($drop->isPriceDrop);
        self::assertSame(21500, $drop->previousPriceEur);
        self::assertFalse($rise->isPriceDrop);
        self::assertSame([21500, 20000, 22000], $store->priceHistory($drop->dedupKey));
    }

    public function testAnUnknownPriceIsNotADropToZeroAndWritesNoHistory(): void
    {
        $store = VehicleStore::open($this->path);
        $store->record($this->car('a1', 21500), '2026-08-28T10:00:00Z');
        $unknown = $store->record($this->car('a1', null), '2026-08-29T10:00:00Z');

        self::assertFalse($unknown->isPriceDrop, 'hard rule 9');
        self::assertSame([21500], $store->priceHistory($unknown->dedupKey));
        self::assertSame(21500, $store->snapshotPrice($unknown->dedupKey), 'the last known price stands');
    }

    public function testARepeatedIdenticalPriceWritesNoHistoryRow(): void
    {
        $store = VehicleStore::open($this->path);
        $key = $store->record($this->car('a1', 21500), '2026-08-28T10:00:00Z')->dedupKey;
        $store->record($this->car('a1', 21500), '2026-08-28T10:15:00Z');
        $store->record($this->car('a1', 21500), '2026-08-28T10:30:00Z');

        self::assertSame([21500], $store->priceHistory($key), 'changes only');
    }

    public function testAnUnreadableInstantIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VehicleStore::open($this->path)->record($this->car('a1', 1), '2026-02-30T10:00:00Z');
    }

    // ── evidence / persistence ────────────────────────────────────────────────────────────────

    public function testTheVerdictAndSnapshotSurviveReopening(): void
    {
        $store = VehicleStore::open($this->path);
        $car = $this->car('a1', 21000);
        $key = $store->record($car, '2026-08-29T10:00:00Z')->dedupKey;
        $store->recordVerdict($key, VehicleVerdict::matched(78, ['x'], true), $car);
        unset($store);

        $again = VehicleStore::open($this->path);
        self::assertSame(78, $again->snapshotScore($key));
        self::assertSame('Renault Austral', $again->snapshot($key)?->title);
        self::assertSame(['count' => 1, 'notified' => 0, 'matches' => 1], $again->counts());
        self::assertSame(VehicleOutcome::MATCH->value, $again->outcomeOf($key));
    }

    // ── the composed run log ──────────────────────────────────────────────────────────────────

    public function testTheRunLogAndHealthComeFromTheComposedStoreOnTheSameFile(): void
    {
        $store = VehicleStore::open($this->path);
        $store->runs()->recordRun('paruvendu', 3, true, null, '2026-08-29T10:00:00Z', 400);

        self::assertSame(SourceStatus::OK, $store->runs()->health('paruvendu', '2026-08-29T10:05:00Z')->status);
        self::assertSame('wal', $store->journalMode());
    }

    private function car(string $id, ?int $price, string $source = 'paruvendu'): VehicleListing
    {
        return new VehicleListing(sourceName: $source, externalId: $id, title: 'Renault Austral', priceEur: $price, url: 'https://x.test/' . trim($id));
    }
}
