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
