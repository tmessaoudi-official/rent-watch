<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * Category **twin** (schema v12, 2026-08-30): what the OTHER track last said about this flat.
 * Identities, groups and histories stay per track (developer ruling, 2026-08-06/29); the twin
 * fact is the one cross-track datum, and it exists because a veto that lives only in the pass's
 * harvest lapses the moment the twin is not fetched — a review panel pushed a PLS flat's agency
 * copy on the pass after its landlord listing failed to load.
 */
#[CoversClass(Store::class)]
final class StoreTwinTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
    }

    public function testARowWithNoTwinHasNoFact(): void
    {
        $key = $this->recorded('a1');

        self::assertNull($this->store->twinTenure($key));
        self::assertNull($this->store->twinTenure('never-seen'));
    }

    public function testTheFactIsRecordedWithItsSource(): void
    {
        $key = $this->recorded('a1');

        $this->store->recordTwin($key, Tenure::UNKNOWN, 'cdc_habitat');

        self::assertSame(['tenure' => Tenure::UNKNOWN, 'source' => 'cdc_habitat'], $this->store->twinTenure($key));
    }

    public function testAnExcludedFactIsDurable(): void
    {
        // The group veto's rule, read across the track boundary: once the other route said PLS,
        // a later reading that says nothing — or says LLI — does not clear it.
        $key = $this->recorded('a1');
        $this->store->recordTwin($key, Tenure::PLS, 'cdc_habitat');

        $this->store->recordTwin($key, Tenure::LLI, 'cdc_habitat');
        self::assertSame(Tenure::PLS, $this->store->twinTenure($key)['tenure']);

        $this->store->recordTwin($key, Tenure::UNKNOWN, 'inli');
        self::assertSame(['tenure' => Tenure::PLS, 'source' => 'cdc_habitat'], $this->store->twinTenure($key));
    }

    public function testOtherwiseTheLastReadingWins(): void
    {
        $key = $this->recorded('a1');

        $this->store->recordTwin($key, Tenure::UNKNOWN, 'cdc_habitat');
        $this->store->recordTwin($key, Tenure::LLI, 'cdc_habitat');
        self::assertSame(Tenure::LLI, $this->store->twinTenure($key)['tenure'], 'a doubt clears');

        $this->store->recordTwin($key, Tenure::UNKNOWN, 'cdc_habitat');
        self::assertSame(Tenure::UNKNOWN, $this->store->twinTenure($key)['tenure'], 'and can return');

        $this->store->recordTwin($key, Tenure::PLUS, 'cdc_habitat');
        self::assertSame(Tenure::PLUS, $this->store->twinTenure($key)['tenure'], 'until it is excluded');
    }

    public function testTheFactSurvivesReopening(): void
    {
        $path = sys_get_temp_dir() . '/scout-twin-' . bin2hex(random_bytes(6)) . '.sqlite3';
        try {
            $store = Store::open($path);
            $key = $store->dedupKey($this->listing('a1'));
            $store->record($this->listing('a1'), 1450, '2026-08-30T10:00:00+02:00');
            $store->recordTwin($key, Tenure::PLS, 'cdc_habitat');
            unset($store);

            self::assertSame(['tenure' => Tenure::PLS, 'source' => 'cdc_habitat'], Store::open($path)->twinTenure($key));
        } finally {
            foreach (glob($path . '*') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    public function testAPreV12StoreGainsTheColumnsAndNoFact(): void
    {
        // The migration adds the columns and backfills NOTHING: a row from before v12 has no twin
        // fact, which is the truth — nothing was recorded — and the pipeline learns it on the next
        // pass both routes are seen together. Same precedent as `tenure`, `group_key`, `outcome`.
        $path = sys_get_temp_dir() . '/scout-twin-' . bin2hex(random_bytes(6)) . '.sqlite3';
        try {
            $store = Store::open($path);
            $key = $store->dedupKey($this->listing('a1'));
            $store->record($this->listing('a1'), 1450, '2026-08-30T10:00:00+02:00');
            unset($store);

            $raw = new \PDO('sqlite:' . $path);
            $raw->exec('ALTER TABLE listings DROP COLUMN twin_tenure');
            $raw->exec('ALTER TABLE listings DROP COLUMN twin_source');
            $raw->exec("UPDATE schema_meta SET value = '11' WHERE key = 'schema_version'");
            unset($raw);

            $reopened = Store::open($path);
            self::assertSame(Store::SCHEMA_VERSION, $reopened->schemaVersion());
            self::assertNull($reopened->twinTenure($key));
            $reopened->recordTwin($key, Tenure::PLS, 'cdc_habitat');
            self::assertSame(Tenure::PLS, $reopened->twinTenure($key)['tenure']);
        } finally {
            foreach (glob($path . '*') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    private function recorded(string $id): string
    {
        $listing = $this->listing($id);
        $this->store->record($listing, 1450, '2026-08-30T10:00:00+02:00');

        return $this->store->dedupKey($listing);
    }

    private function listing(string $id): RawListing
    {
        return new RawListing(
            sourceName: 'seloger', externalId: $id, title: 'Appartement T4',
            description: 'Beau 4 pieces de 88 m2.', fields: [],
            url: 'https://seloger.test/' . $id, commune: 'Sartrouville', postcode: '78500',
            rentCc: 1450, surfaceM2: 88.0, rooms: 4,
        );
    }
}
