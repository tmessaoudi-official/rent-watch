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

    public function testRecordingAFactOnAnUnknownRowIsLoud(): void
    {
        // A 0-row UPDATE that returns quietly is a fact that was never stored, reported as stored.
        $this->expectException(\LogicException::class);

        $this->store->recordTwin('never-recorded', Tenure::PLS, 'cdc_habitat');
    }
    /**
     * `Store::tenure()` — the row's OWN last reading, which the pipeline's durable-reading rule is
     * built on. It arrived in schema v12's commit with no store-level assertion anywhere (round-4
     * panel, 2026-08-31), and CLAUDE.md's own rule is that a store behaviour without a named
     * category is a behaviour nobody decided to guarantee.
     *
     * The third case PINS A KNOWN WEAKNESS RATHER THAN A GUARANTEE, and says so — a round-5 reviewer
     * read the first draft of this docblock, which asserted the opposite of the assertion below, and
     * was right to call it out. What is true: `Store::tenure()` uses `Tenure::tryFrom()`, so an
     * unrecognised stored string reads as `null`, which is INDISTINGUISHABLE from a pre-v3
     * never-judged row — and `durableOwnReading()` treats `null` as "nothing to preserve". So a
     * corrupted value, or any future rename of a `Tenure` case, silently RELEASES every durable
     * excluded reading in the store with no signal at all.
     *
     * That is against the store's stated posture (a corrupt snapshot is refused loudly rather than
     * degraded) and the fail-closed direction — throw, or treat an unreadable value as excluded — is
     * NOT taken. The assertion below therefore documents the current behaviour so a change to it is
     * deliberate; it is not a claim that the behaviour is right. Recorded as owed.
     */
    public function testTheRowsOwnReadingIsReadableAndAnUnknownValueIsNotSilentlyEligible(): void
    {
        $key = $this->recorded('a1');

        self::assertNull($this->store->tenure('never-seen'), 'an unknown key has no reading');
        self::assertNull($this->store->tenure($key), 'and neither has a row that was never judged');

        $this->store->recordVerdict($key, Tenure::PLS->value, 9000, ['financement PLS'], $this->listing('a1'));
        self::assertSame(Tenure::PLS, $this->store->tenure($key), 'the stored reading round-trips');

        // A value no `Tenure` case matches — the shape a corrupt row or a future rename would leave.
        $this->store->recordVerdict($key, 'NOT_A_TENURE', 9000, ['corrompu'], $this->listing('a1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/illisible en colonne tenure/');
        $this->store->tenure($key);
    }

    /**
     * THE SAME FAIL-CLOSED ON THE OTHER TWO §1 VETOES. `tryFrom()` returns `null` for an
     * unparseable value exactly as it does for a genuinely NULL column, so ONE case-flip or enum
     * rename used to release the row's own durable reading, the schema-v4 group veto and the
     * schema-v12 twin veto together — three §1 mechanisms, silently, in the direction §1 forbids.
     */
    public function testACorruptStoredTenureIsRefusedOnTheGroupAndTwinVetoesToo(): void
    {
        // A FILE-backed store, because the corruption has to be applied behind its back and a
        // `:memory:` database cannot be reached by a second connection.
        $path = sys_get_temp_dir() . '/rentwatch-twin-corrupt-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $store = Store::open($path);

        try {
            $a = $store->dedupKey($this->listing('a1'));
            $b = $store->dedupKey($this->listing('b1'));
            $store->record($this->listing('a1'), 1450, '2026-08-30T10:00:00+02:00');
            $store->record($this->listing('b1'), 1450, '2026-08-30T10:00:00+02:00');
            $store->assignGroup([$a, $b]);
            $store->recordTwin($a, Tenure::PLS, 'cdc_habitat');

            (new \PDO('sqlite:' . $path))->exec("UPDATE listings SET tenure = 'pls', twin_tenure = 'pls'");

            foreach ([
                'group' => fn () => $store->groupExcludedTenure($b),
                'twin' => fn () => $store->twinTenure($a),
            ] as $which => $read) {
                try {
                    $read();
                    self::fail($which . ' veto read a corrupt tenure as "nothing said" — that releases it');
                } catch (\RuntimeException $e) {
                    self::assertStringContainsString('illisible', $e->getMessage(), $which);
                }
            }
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
