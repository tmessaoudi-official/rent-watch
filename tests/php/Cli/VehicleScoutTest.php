<?php

declare(strict_types=1);

namespace Scout\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Cli\Scout;
use Scout\Cli\VehicleScout;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Tests\Vehicle\CarRecordingChannel;

/**
 * `scout --domain=car …` against the ParuVendu fixtures and a throwaway database. Every run is
 * pinned to `--source=paruvendu`: Autohero polls a live site, and the suite runs SCOUT_OFFLINE=1.
 */
#[CoversClass(VehicleScout::class)]
final class VehicleScoutTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/scout-car-cli-' . bin2hex(random_bytes(6)) . '.sqlite3';
        putenv('CAR_SCOUT_DB=' . $this->db);
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/paruvendu');
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

    public function testTheDomainFlagIsDispatchedFromTheRentCli(): void
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
        \Scout\Vehicle\VehicleStore::open($this->db)->record(new \Scout\Vehicle\VehicleListing(sourceName: 'paruvendu', externalId: 'seed'), '2026-08-01T00:00:00Z');
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/paruvendu');

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

    /** @return array{code: int, out: string, err: string} */
    private function scout(array $argv, ?CarRecordingChannel $channel = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Scout(self::ROOT, $out, $err, '2026-08-29T20:00:00+02:00', null, $channel === null ? null : new Notifier([$channel])))->run($argv);

        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }
}
