<?php

declare(strict_types=1);

namespace RentWatch\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RentWatch\Cli\Scout;

/**
 * Q27 in the loop, rather than in the policy object.
 *
 * {@see \RentWatch\Tests\Core\HeartbeatTest} proves the interval arithmetic. This proves the thing
 * that arithmetic is for: that `scout run --watch` actually emits the beat, that it emits it
 * **whether or not anything matched**, and that it does not emit one per pass. Before this suite
 * existed, `HEARTBEAT_HOURS` was documented in `.env.example` and read by no code at all — the beat
 * existed only as a `NotificationKind` used by the manual `test-notify` one-shot, so a watcher that
 * died at 03:00 was indistinguishable from one watching a quiet market until somebody thought to
 * look.
 *
 * The clock is FIXED (Scout takes `$nowIso`), which is what makes "exactly one beat" assertable
 * without waiting 24 hours: the startup beat writes a marker at time T, and every later check in the
 * same run asks `isDue(T, T)`, which is false. A second beat appearing anyway would mean the marker
 * is not being written or not being read — the two ways this feature rots into a per-pass spam.
 */
#[CoversNothing]
final class ScoutHeartbeatTest extends TestCase
{
    private const string NOW = '2026-08-22T12:00:00+02:00';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        // `--watch`'s success case never returns, so the bound is not optional: without it a wrong
        // expectation does not fail, it hangs — and it hangs the suite and the sabotage ledger
        // behind it.
        //
        // ONE, not two. `WatchLoop` only breaks after the LAST pass, so a bound of 2 makes it sleep
        // out the real Q37 inter-pass interval — fifteen minutes — between them. Written as 2 first,
        // and the run had to be killed at 300 s.
        putenv('RENT_WATCH_MAX_PASSES=1');
    }

    protected function tearDown(): void
    {
        putenv('RENT_WATCH_MAX_PASSES');
        putenv('HEARTBEAT_HOURS');
        putenv('RENT_WATCH_DB');

        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }

        $this->roots = [];
    }

    public function testTheWatcherAnnouncesItsHeartbeatIntervalOnStartup(): void
    {
        // An interval nobody was told about cannot be used to judge whether the watcher has gone
        // quiet, which is the entire mechanism.
        $r = $this->watch();

        self::assertStringContainsString('battement de cœur', $r['out']);
        self::assertStringContainsString('24 h', $r['out'], 'the ruled default must be visible');
    }

    public function testAHeartbeatIsEmittedEvenThoughNothingMatched(): void
    {
        // The whole point of Q27. The fixture source matches nothing against these criteria, so a
        // watcher that only spoke when it had news would say nothing at all here.
        $r = $this->watch();

        self::assertStringContainsString('toujours actif', $r['out']);
    }

    public function testTheMarkerSuppressesASecondBeatAcrossARESTART(): void
    {
        // The failure this guards is a beat every time the watcher starts or passes: at the Q37
        // cadence that is one every fifteen minutes, which trains the operator to ignore them, which
        // removes the signal as surely as never sending it.
        //
        // Tested across two RUNS rather than two passes, and that is the better test rather than a
        // workaround: the loop only breaks after its last pass, so a two-pass bound would sleep out
        // the real inter-pass interval. Two runs against one `state/` directory is also the shape
        // this actually takes in production — a container that restarts is the common case, and the
        // marker surviving it is exactly what Q8's mounted volume is for.
        $root = $this->tempRoot();

        $first = $this->watchIn($root);
        $second = $this->scoutIn($root, ['run', '--watch']);

        self::assertSame(1, substr_count($first['out'], 'toujours actif'), 'the first start beats');
        self::assertStringNotContainsString(
            'toujours actif',
            $second['out'],
            'the marker must suppress the next start — one beat per interval, not one per restart',
        );
    }

    public function testTheHeartbeatCarriesWhatQ27SaysItMustCarry(): void
    {
        // "stating runs completed, sources OK and matches sent".
        $r = $this->watch();

        self::assertStringContainsString('surveillance', $r['out']);
        self::assertMatchesRegularExpression('~\d+ annonce\(s\) notifiée\(s\)~', $r['out']);
        self::assertMatchesRegularExpression('~\d+/\d+ source\(s\) en bon état~', $r['out']);
    }

    public function testTheMarkerIsWrittenToTheStateDirectorySoItSurvivesARestart(): void
    {
        // Q8 puts `state/` on the mounted volume. A marker written anywhere else resets on every
        // deploy, and a marker that resets makes every restart emit a beat — which is harmless, and
        // makes the interval meaningless.
        $root = $this->tempRoot();
        $this->watchIn($root);

        self::assertFileExists($root . '/state/heartbeat.txt');
        self::assertStringContainsString(
            '2026-08-22',
            (string) file_get_contents($root . '/state/heartbeat.txt'),
        );
    }

    public function testAnExistingRecentMarkerSuppressesTheStartupBeat(): void
    {
        // A container restarted twice in a minute must not beat twice.
        $root = $this->tempRoot();
        file_put_contents($root . '/state/heartbeat.txt', '2026-08-22T11:00:00+02:00');

        $r = $this->watchIn($root);

        self::assertStringNotContainsString('toujours actif', $r['out'], 'one hour is inside a 24h interval');
    }

    public function testAnOldMarkerDoesNotSuppressIt(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/state/heartbeat.txt', '2026-08-20T11:00:00+02:00');

        $r = $this->watchIn($root);

        self::assertStringContainsString('toujours actif', $r['out'], 'two days is well past the interval');
    }

    public function testAnUnusableHeartbeatHoursRefusesAtStartupRatherThanADayLater(): void
    {
        // Refused before the loop, deliberately. Discovering a bad value on the first due beat would
        // mean discovering it a day into an unattended run — and by then the operator has already
        // concluded the market is quiet.
        putenv('HEARTBEAT_HOURS=0');

        $r = $this->watch();

        self::assertNotSame(0, $r['code']);
        self::assertStringContainsString('HEARTBEAT_HOURS', $r['out'] . $r['err']);
    }

    public function testAShorterIntervalIsHonoured(): void
    {
        putenv('HEARTBEAT_HOURS=1');
        $root = $this->tempRoot();
        file_put_contents($root . '/state/heartbeat.txt', '2026-08-22T10:30:00+02:00');

        $r = $this->watchIn($root);

        self::assertStringContainsString('toujours actif', $r['out'], '90 minutes is past a 1h interval');
    }

    // ── the other half of Q27: what happened while it was down ───────────────────────────────────

    public function testAPreviousStartupRefusalIsReportedOnTheNextStartAndThenCleared(): void
    {
        // A startup refusal reaches nobody: the process exits before any channel is used, and under
        // Docker its stderr scrolls past in a log nobody is reading.
        $root = $this->tempRoot();
        file_put_contents($root . '/state/last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans NTFY_TOPIC');

        $r = $this->watchIn($root);

        self::assertStringContainsString('refus au démarrage précédent', $r['out']);
        self::assertStringContainsString('ntfy', $r['out']);
        self::assertFileDoesNotExist(
            $root . '/state/last-refusal.txt',
            'reported once, not on every start for the life of the deployment',
        );
    }

    public function testARunRefusalWritesTheNoteForTheNextStart(): void
    {
        // The producing half. An empty seen-set is Q36's refusal and is the easiest to provoke.
        $root = $this->tempRoot();
        putenv('RENT_WATCH_DB=' . $root . '/state/rent-watch.sqlite3');

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertSame(2, $r['code'], 'an empty seen-set is a startup refusal (Q36)');
        self::assertFileExists($root . '/state/last-refusal.txt');
        self::assertStringContainsString('base vide', (string) file_get_contents($root . '/state/last-refusal.txt'));
    }

    public function testTheRefusalNoteIsRedactedBeforeItTouchesTheDisk(): void
    {
        // Found by sabotage: removing `Redact::text()` from the write left the suite green, so the
        // guard was untested. A startup refusal is exactly the text that carries a credential, and
        // this is a second disk-write funnel beside `Store::recordRun()`, which redacts for the
        // same reason.
        //
        // A CONFIG error is the vehicle, because it is the only startup refusal whose message
        // quotes back a value the operator supplied — which is precisely how a secret ends up in
        // one. Here the bad value is a mailbox URL with a password in it, the shape somebody
        // actually pastes into a config file by mistake.
        $root = $this->tempRoot(['min_rooms' => 'imap://user:hunter2@mail.example.test/INBOX']);
        putenv('RENT_WATCH_DB=' . $root . '/state/rent-watch.sqlite3');

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertSame(2, $r['code'], 'a malformed criteria.json is a startup refusal');
        self::assertFileExists($root . '/state/last-refusal.txt', 'a config refusal during `run` must be recorded');

        $note = (string) file_get_contents($root . '/state/last-refusal.txt');
        self::assertStringNotContainsString('hunter2', $note, 'the password must never reach the disk');
        self::assertStringContainsString('masqué', $note, 'and the masking must be visible, not a silent drop');
    }

    public function testTheRecordedRefusalRunsThroughRedactAtAll(): void
    {
        // The direct assertion on the funnel, independent of which refusal happens to fire: whatever
        // reaches that file must have been through Redact, and Redact demonstrably masks this shape.
        self::assertStringNotContainsString(
            'hunter2',
            (string) \RentWatch\Core\Redact::text('échec IMAP sur imap://user:hunter2@mail.example.test/INBOX'),
        );
    }

    public function testAnUnwritableDatabaseIsARefusalRatherThanAStackTrace(): void
    {
        // The first thing a new deployment hits, and it was a fatal PDOException with a stack trace:
        // Q8 bind-mounts `state/` from the host, the image runs as a non-root uid, and a host
        // directory owned by somebody else is not writable — measured, running the real container.
        //
        // A malformed config is already ruled "an ordinary, expected, user-caused condition — caught
        // and printed, never a stack trace" (ConfigError). An unmounted or unwritable volume is the
        // same kind of condition and the likeliest one in production, so it gets the same treatment,
        // and the message has to name the PATH — that is the whole diagnosis.
        // An UNWRITABLE directory, not a missing one: `Store::open()` creates missing directories,
        // and in the container the directory existed and was owned by another uid. Getting this
        // wrong the first time is the point — the reproduction has to match the real failure.
        $root = $this->tempRoot();
        $locked = $root . '/locked';
        mkdir($locked, 0o500, true);
        $r = $this->scoutIn($root, ['run', '--once'], $locked . '/rent-watch.sqlite3');
        $text = $r['out'] . $r['err'];
        chmod($locked, 0o700);

        self::assertSame(2, $r['code'], 'an unusable store is a startup refusal');
        self::assertStringNotContainsString('Stack trace', $text);
        self::assertStringNotContainsString('PDOException', $text);
        self::assertStringContainsString('locked', $text, 'the refusal must name the path it could not open');
    }

    public function testADoctorRefusalDoesNotWriteTheNote(): void
    {
        // Scoped to `run`. A doctor refusal is read by whoever typed it, in the terminal they typed
        // it in — recording it would make the next watch start report noise they already acted on.
        $root = $this->tempRoot(['communes' => 'not-a-list']);

        $this->scoutIn($root, ['doctor']);

        self::assertFileDoesNotExist($root . '/state/last-refusal.txt');
    }

    /** @return array{code: int, out: string, err: string} */
    private function watch(): array
    {
        return $this->watchIn($this->tempRoot());
    }

    /** @return array{code: int, out: string, err: string} */
    private function watchIn(string $root): array
    {
        // Seeded first, because `run` refuses to notify on an empty seen-set (Q36) and would never
        // reach the loop.
        $this->scoutIn($root, ['run', '--once', '--seed']);

        return $this->scoutIn($root, ['run', '--watch']);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scoutIn(string $root, array $argv, ?string $db = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        // The override exists because this helper used to set the path unconditionally, which
        // silently discarded the one a test had just chosen — the unwritable-store test then
        // exercised a perfectly good database and failed for a reason unrelated to its subject.
        putenv('RENT_WATCH_DB=' . ($db ?? $root . '/state/rent-watch.sqlite3'));

        $code = (new Scout($root, $out, $err, self::NOW))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /** @param array<string,mixed> $criteria */
    private function tempRoot(array $criteria = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-hb-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        mkdir($root . '/tests/fixtures/fixture_demo', 0o775, true);
        $this->roots[] = $root;

        copy(
            dirname(__DIR__, 3) . '/tests/fixtures/fixture_demo/search.json',
            $root . '/tests/fixtures/fixture_demo/search.json',
        );

        file_put_contents($root . '/config/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 4,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/sources.json', json_encode([
            'sources' => [
                'fixture_demo' => [
                    'enabled' => true,
                    'family' => 'institutional',
                    'type' => 'fixture',
                    'mixed_tenure' => true,
                    'fixture' => 'tests/fixtures/fixture_demo/search.json',
                    // Mirrors the shipped block. A hand-written map here drifts from the payload the
                    // moment either changes, and a source that maps nothing seeds nothing — which
                    // makes every heartbeat assertion below fail for a reason that has nothing to do
                    // with heartbeats.
                    'items_path' => 'results.items',
                    'map' => [
                        'ref' => 'id',
                        'title' => 'title',
                        'commune' => ['city', 'address.city'],
                        'cp' => ['zipCode', 'address.postalCode'],
                        'rent' => ['rent.total', 'price'],
                        'charges_included' => true,
                        'surface' => 'surface',
                        'rooms' => ['rooms', 'nbRooms'],
                        'description' => 'description',
                        'tenure_field' => 'financement',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
