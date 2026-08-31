<?php

declare(strict_types=1);

namespace Scout\Tests\Car\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Cli\Scout;
use Scout\Car\Cli\CarScout;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Tests\Car\CarRecordingChannel;

/**
 * `scout --domain=car …` against the ParuVendu fixtures and a throwaway database. Every run is
 * pinned to `--source=paruvendu`: Autohero polls a live site, and the suite runs SCOUT_OFFLINE=1.
 */
#[CoversClass(CarScout::class)]
final class CarScoutTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/scout-car-cli-' . bin2hex(random_bytes(6)) . '.sqlite3';
        putenv('CAR_SCOUT_DB=' . $this->db);
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/car/paruvendu');
    }

    protected function tearDown(): void
    {
        putenv('CAR_SCOUT_DB');
        putenv('MAILBOX_DIR');
        foreach (glob($this->db . '*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink(\dirname($this->db) . '/car-heartbeat.txt');
        @unlink(\dirname($this->db) . '/car-last-refusal.txt');
    }

    public function testTheDomainFlagIsDispatchedFromTheGenericEntryPoint(): void
    {
        $r = $this->scout(['--domain=car', 'help']);

        self::assertSame(0, $r['code']);
        self::assertStringContainsString('scout --domain=car doctor', $r['out']);
        self::assertStringNotContainsString('digest', $r['out'], 'no digest verb in the car domain');
    }

    public function testRunRefusesOnAnEmptyVehicleSeenSetUntilSeeded(): void
    {
        $refused = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu']);
        self::assertSame(2, $refused['code']);
        self::assertStringContainsString('--seed', $refused['err'], 'Q36, the car analog');

        $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
        self::assertSame(0, $seed['code'], $seed['err']);
        self::assertStringContainsString('mode --seed', $seed['out']);

        $channel = new CarRecordingChannel();
        $again = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], $channel);
        self::assertSame(0, $again['code'], $again['err']);
        self::assertSame([], array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH), 'seeded cards are never pushed');
        self::assertStringContainsString('3 annonce(s) analysées', $again['out']);
    }

    public function testAnUnseededRunPushesEveryMatchOnceWithTheSourceLeadingTheTitle(): void
    {
        // Seed with an EMPTY folder, then run against the real one: three novel cards.
        $empty = sys_get_temp_dir() . '/scout-car-empty-' . bin2hex(random_bytes(4));
        mkdir($empty);
        putenv('MAILBOX_DIR=' . $empty);
        // A seed over nothing leaves the seen-set empty, so record one throwaway car by hand.
        \Scout\Car\VehicleStore::open($this->db)->record(new \Scout\Car\VehicleListing(sourceName: 'paruvendu', externalId: 'seed'), '2026-08-01T00:00:00Z');
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/car/paruvendu');

        $channel = new CarRecordingChannel();
        $r = $this->scout(['--domain=car', 'run', '--once', '-v', '--source=paruvendu'], $channel);
        $r2 = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], $channel);

        self::assertSame(0, $r['code'], $r['err']);
        $matches = array_values(array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH));
        self::assertCount(3, $matches, 'three cards, three pushes, none twice');
        self::assertStringStartsWith('paruvendu · ', $matches[0]->title);
        self::assertStringContainsString('Renault Austral 2023 · 26 000 km · 21 000 €', $matches[0]->title);
        rmdir($empty);
    }

    public function testDoctorReportsTheSourceAndTheSeenSet(): void
    {
        $r = $this->scout(['--domain=car', 'doctor', '--source=paruvendu']);

        self::assertStringContainsString('VIDE', $r['out'], 'an empty seen-set is named, with the seed command');
        self::assertStringContainsString('paruvendu', $r['out']);
        self::assertStringContainsString('car-watch', $r['out']);
    }

    public function testDumpShowsTheFirstCardAndItsVerdict(): void
    {
        $r = $this->scout(['--domain=car', 'dump', 'paruvendu']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('priceEur     21000', $r['out']);
        self::assertStringContainsString('verdict      MATCH', $r['out']);
    }

    public function testAnUnknownSourceIsAWarningNotASilentEmptyRun(): void
    {
        $r = $this->scout(['--domain=car', 'doctor', '--source=nope']);

        self::assertStringContainsString('source inconnue', $r['err']);
    }

    public function testTheHeartbeatSeesAVerdictOnlyTheClockCanDerive(): void
    {
        // The beat must read health WITH the clock. `FEED_SILENT` and `STALE` are underivable
        // without one, so a portal that stopped sending — or a watcher that did — counted as
        // healthy on the one channel whose job is to say otherwise: the rent side's 2026-08-29
        // defect, unfixed on the car twin until a review panel proved it at the store on
        // 2026-08-30. Pinned through `STALE` rather than `FEED_SILENT` because the offline
        // `FileMailbox` deliberately reports no message date (an unknown date yields no verdict);
        // in production the IMAP mailbox does, and the same clock carries both. Seeded on one day,
        // watched a month later (outside the 7-day rolling window): the startup beat (cold start, no marker) must name the source.
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);

            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], null, '2026-10-01T20:00:00+02:00');

            self::assertStringContainsString('[HEARTBEAT]', $r['out'], 'a cold start beats');
            self::assertStringContainsString('en alerte : paruvendu (stale)', $r['out'], 'the beat sees what only the clock can derive');
            self::assertStringNotContainsString('toutes les sources sont OK', substr($r['out'], 0, (int) strpos($r['out'], 'annonce(s) analysées')), 'the STARTUP beat, before any pass, must not call a week-old run healthy');
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }
    }
    /**
     * A THROWING PASS MUST STILL BEAT (round-4 panel, 2026-08-31).
     *
     * The beat sat after the work INSIDE the pass closure, and `WatchLoop` wraps that closure in its
     * own `try` — so any throw from `runOnce()` or `report()` skipped it. A car watcher losing every
     * pass then emitted nothing to any channel, which is exactly the state the beat exists to
     * distinguish from a quiet market. The rent side carries the same `finally` and its comment
     * records a panel finding the identical defect there on 2026-08-24; the car twin claimed to
     * mirror it and did not.
     *
     * A read-only database is the lever: it throws INSIDE the pass, past `VehiclePipeline`'s own
     * per-source catch, which is precisely the class of failure that was silencing the watcher.
     *
     * TWO beats are asserted, not one, and that is the whole design of this test. A cold start beats
     * BEFORE the loop, so "a beat appeared" is satisfied by the startup beat alone and stays green
     * with the `finally` deleted — a first version asserted exactly that and passed against a landed
     * mutation. The marker is therefore made UNWRITABLE (a directory where the file goes): `beat()`
     * writes it with `@file_put_contents` so a full volume cannot crash a liveness signal, and
     * `lastHeartbeat()` reads `is_file()`, so every check is due and the in-loop beat becomes
     * reachable. Two is then the CORRECT count, per the documented one-beat-too-many bias.
     */
    public function testAPassThatThrowsStillEmitsTheHeartbeat(): void
    {
        putenv('SCOUT_MAX_PASSES=1');
        $marker = \dirname($this->db) . '/car-heartbeat.txt';
        try {
            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);

            @unlink($marker);
            self::assertTrue(mkdir($marker), 'the marker must be genuinely unwritable, or the in-loop beat is unreachable');
            self::assertTrue(chmod($this->db, 0o444), 'the store must actually become read-only, or this test proves nothing');

            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu']);
            chmod($this->db, 0o644);

            self::assertStringContainsString('passe échouée', $r['err'], 'the pass genuinely threw');
            self::assertSame(
                2,
                substr_count($r['out'], '[HEARTBEAT]'),
                'the startup beat AND the in-loop one — a pass that throws must not take the liveness signal with it',
            );
        } finally {
            @chmod($this->db, 0o644);
            @rmdir($marker);
            putenv('SCOUT_MAX_PASSES');
        }
    }

    public function testAnUnusableHeartbeatHoursRefusalNamesTheCarKey(): void
    {
        // Dropping the key argument at the call site left the suite green (round-2 panel): the
        // refusal then named `HEARTBEAT_HOURS`, a legacy name the tool itself refuses at startup.
        putenv('CAR_HEARTBEAT_HOURS=0');
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);

            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu']);

            self::assertNotSame(0, $r['code'], 'zero would disable the only liveness signal');
            self::assertStringContainsString('CAR_HEARTBEAT_HOURS', $r['out'] . $r['err']);
        } finally {
            putenv('CAR_HEARTBEAT_HOURS');
            putenv('SCOUT_MAX_PASSES');
        }
    }
    public function testARunRefusalIsRecordedAndReportedOnTheNextBeat(): void
    {
        // Q27's second half, the car twin (round-3 panel): under `restart: unless-stopped` a
        // startup refusal is a crash loop whose stderr nobody reads. The note goes on the mounted
        // volume and the next successful start says it on the beat, then clears it.
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $refused = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu']);
            self::assertSame(2, $refused['code']);
            $note = \dirname($this->db) . '/car-last-refusal.txt';
            self::assertFileExists($note, 'the refusal is written where the next start can find it');
            self::assertStringContainsString('seen-set', (string) file_get_contents($note));

            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);
            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu']);

            self::assertStringContainsString('démarrage précédent refusé', $r['out'], 'the beat reports it');
            self::assertStringContainsString('seen-set', $r['out']);
            self::assertFileDoesNotExist($note, 'reported, therefore cleared');
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }
    }

    public function testANewerStoreIsARecordedRefusalNotATrace(): void
    {
        // A rollback image opening a store a newer image migrated: the schema check throws, and
        // that must land as a refusal (exit 2, one line, the note written), never a stack trace.
        $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
        self::assertSame(0, $seed['code'], $seed['err']);
        (new \PDO('sqlite:' . $this->db))->exec("UPDATE schema_meta SET value = '99' WHERE key = 'schema_version'");

        $r = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('version 99', $r['err']);
        self::assertFileExists(\dirname($this->db) . '/car-last-refusal.txt');
    }
    /** @return array{code: int, out: string, err: string} */
    private function scout(array $argv, ?CarRecordingChannel $channel = null, string $now = '2026-08-29T20:00:00+02:00'): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Scout(self::ROOT, $out, $err, $now, null, $channel === null ? null : new Notifier([$channel])))->run($argv);

        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }
}
