<?php

declare(strict_types=1);

namespace Scout\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Config\LegacyEnv;

/**
 * The `RENT_WATCH_*` → `SCOUT_*` rename must not fail SILENTLY.
 *
 * The hazard is specific and lives outside git: the deployed `.env` on the host is not in the
 * repo, so a rename applied here and not there leaves `RENT_WATCH_DB` set and `RENT_SCOUT_DB` unset.
 * `dbPath()` would then fall back to its default, `Store::open()` would create a brand-new empty
 * database, and the watcher would look healthy while knowing nothing. Q36's flood guard stops the
 * re-notification of the entire market — that is the safe half — but it stops the WATCHER, and
 * nothing on the machine says why.
 *
 * So a legacy name is a REFUSAL, not a fallback. Reading it silently would be worse than either:
 * the rename would then never be finished anywhere, and both spellings would work for ever.
 *
 * It refuses when the legacy name is present AT ALL, not merely when its successor is missing.
 * A `.env` carrying both lines is the shadowing bug that cost the IDFM key its first hour — the
 * loader applies the FIRST occurrence and an empty string counts as set — and "the new one wins,
 * quietly" is exactly how that hour was lost.
 */
#[CoversClass(LegacyEnv::class)]
final class LegacyEnvTest extends TestCase
{
    public function testAnEnvironmentWithNoLegacyNamesPasses(): void
    {
        LegacyEnv::check(['RENT_SCOUT_DB' => 'state/x.sqlite3', 'TZ' => 'Europe/Paris']);
        $this->assertTrue(true, 'no exception is the assertion');
    }

    public function testAnEmptyEnvironmentPasses(): void
    {
        LegacyEnv::check([]);
        $this->assertTrue(true, 'no exception is the assertion');
    }

    /**
     * Every mapped name, by data provider rather than by hand: a fifth legacy name added to the map
     * without a case here would otherwise ship unproven.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function legacyNames(): iterable
    {
        yield 'db' => ['RENT_WATCH_DB', 'RENT_SCOUT_DB'];
        yield 'offline' => ['RENT_WATCH_OFFLINE', 'SCOUT_OFFLINE'];
        yield 'max passes' => ['RENT_WATCH_MAX_PASSES', 'SCOUT_MAX_PASSES'];
        yield 'backup keep' => ['RENT_WATCH_BACKUP_KEEP', 'SCOUT_BACKUP_KEEP'];
        // The domain split of 2026-08-29: an unprefixed rent key is refused, naming its RENT_ successor.
        yield 'db, unprefixed' => ['SCOUT_DB', 'RENT_SCOUT_DB'];
        yield 'mailbox, unprefixed' => ['IMAP_MAILBOX', 'RENT_IMAP_MAILBOX'];
        yield 'topic, unprefixed' => ['NTFY_TOPIC', 'RENT_NTFY_TOPIC'];
        yield 'heartbeat, unprefixed' => ['HEARTBEAT_HOURS', 'RENT_HEARTBEAT_HOURS'];
        yield 'feed silence, unprefixed' => ['FEED_SILENT_DAYS', 'RENT_FEED_SILENT_DAYS'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyNames')]
    public function testALegacyNameIsRefusedAndBothSpellingsAreNamed(string $old, string $new): void
    {
        try {
            LegacyEnv::check([$old => 'whatever']);
            self::fail($old . ' must be refused');
        } catch (ConfigError $e) {
            self::assertStringContainsString($old, $e->getMessage());
            self::assertStringContainsString($new, $e->getMessage());
        }
    }

    /** The map must be complete — a name in the map with no case above would go unproven. */
    public function testEveryMappedNameHasACase(): void
    {
        $covered = [];

        foreach (self::legacyNames() as [$old, $new]) {
            $covered[$old] = $new;
        }

        self::assertSame(LegacyEnv::MAP, $covered, 'every mapped legacy name needs a case above');
    }

    /**
     * Refused even when the successor is also set. "The new one wins, quietly" is how a shadowed
     * key survives an hour of debugging; a stale line is a thing to delete, not to out-rank.
     */
    public function testItRefusesEvenWhenTheSuccessorIsAlsoSet(): void
    {
        $this->expectException(ConfigError::class);

        LegacyEnv::check(['RENT_WATCH_DB' => 'old.sqlite3', 'RENT_SCOUT_DB' => 'new.sqlite3']);
    }

    /**
     * The message names the VARIABLE and never its VALUE. `RENT_WATCH_DB` is a path rather than a
     * credential, but this message is written to `state/last-refusal.txt` and read back onto the
     * heartbeat, and a refusal channel that quotes values is how `imap://user:password@host` ends
     * up in a file — which is why `Redact` exists at all.
     */
    public function testTheMessageQuotesTheNameAndNotTheValue(): void
    {
        try {
            LegacyEnv::check(['RENT_WATCH_DB' => '/srv/secret-path/rent-watch.sqlite3']);
            self::fail('expected a refusal');
        } catch (ConfigError $e) {
            self::assertStringNotContainsString('secret-path', $e->getMessage());
        }
    }

    /**
     * `checkProcess()` is what `bin/scout` actually calls, and the pure `check()` above proves
     * nothing about it. The entry point is the one file no test is a subject of, so a guard proven
     * only in its pure half is a guard that may never run — the shape that left the heartbeat's
     * in-loop call site unreachable for a day.
     */
    public function testCheckProcessReadsTheRealEnvironment(): void
    {
        putenv('RENT_WATCH_DB=/tmp/whatever.sqlite3');

        try {
            LegacyEnv::checkProcess();
            self::fail('checkProcess must see a legacy name in the real environment');
        } catch (ConfigError $e) {
            self::assertStringContainsString('RENT_SCOUT_DB', $e->getMessage());
        } finally {
            putenv('RENT_WATCH_DB');
            unset($_ENV['RENT_WATCH_DB'], $_SERVER['RENT_WATCH_DB']);
        }
    }

    /** The counterweight: without it, the guarantee above is satisfied by refusing unconditionally. */
    public function testCheckProcessPassesOnACleanEnvironment(): void
    {
        LegacyEnv::checkProcess();
        $this->assertTrue(true, 'the suite runs with no legacy name set; no exception is the assertion');
    }

    /** `$_ENV` alone is enough — DotEnv writes all three, but a php.ini may not populate them all. */
    public function testCheckProcessReadsEnvSuperglobalToo(): void
    {
        $_ENV['RENT_WATCH_BACKUP_KEEP'] = '7';

        try {
            LegacyEnv::checkProcess();
            self::fail('a legacy name present only in $_ENV must still be refused');
        } catch (ConfigError $e) {
            self::assertStringContainsString('SCOUT_BACKUP_KEEP', $e->getMessage());
        } finally {
            unset($_ENV['RENT_WATCH_BACKUP_KEEP']);
        }
    }

    /**
     * WHERE the check runs is the guarantee, not merely THAT it runs.
     *
     * Called from `bin/scout` — the obvious place, beside the `.env` load — the refusal is stderr
     * only: `recordRefusal()` is a method on `Scout`, and no `Scout` exists that early. The reader
     * this guard is FOR is a container crash-looping under a restart policy on a host whose `.env`
     * still says `RENT_WATCH_DB`, and Q27's whole observation is that such a reader is not watching
     * stderr. So the call lives inside `Scout::run()`'s try, where `failRun()` persists it.
     *
     * This test fails if it is ever moved back, which a docblock alone would not.
     */
    public function testARefusalDuringRunIsRECORDEDForTheNextHeartbeat(): void
    {
        $root = sys_get_temp_dir() . '/scout-legacy-' . bin2hex(random_bytes(6));
        mkdir($root . '/state', 0o777, true);
        mkdir($root . '/config', 0o777, true);
        copy(\dirname(__DIR__, 3) . '/config/criteria.json', $root . '/config/criteria.json');
        copy(\dirname(__DIR__, 3) . '/config/sources.json', $root . '/config/sources.json');

        putenv('RENT_WATCH_MAX_PASSES=1');
        putenv('RENT_SCOUT_DB=' . $root . '/state/db.sqlite3');

        try {
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            $code = (new \Scout\Cli\Scout($root, $out, $err))->run(['run', '--once']);

            self::assertSame(2, $code, 'a legacy env name must stop the run');
            self::assertFileExists(
                $root . '/state/last-refusal.txt',
                'the refusal must survive the process for the next start to report it (Q27)'
            );
            self::assertStringContainsString(
                'SCOUT_MAX_PASSES',
                (string) file_get_contents($root . '/state/last-refusal.txt')
            );
        } finally {
            putenv('RENT_WATCH_MAX_PASSES');
            putenv('RENT_SCOUT_DB');
            unset($_ENV['RENT_WATCH_MAX_PASSES'], $_SERVER['RENT_WATCH_MAX_PASSES']);
            @unlink($root . '/state/last-refusal.txt');
            @unlink($root . '/state/db.sqlite3');
            @unlink($root . '/config/criteria.json');
            @unlink($root . '/config/sources.json');
            @rmdir($root . '/state');
            @rmdir($root . '/config');
            @rmdir($root);
        }
    }

    /** An empty legacy value is still SET — the shadowing case, and the one that reads as absent. */
    public function testAnEmptyLegacyValueIsStillRefused(): void
    {
        $this->expectException(ConfigError::class);

        LegacyEnv::check(['RENT_WATCH_MAX_PASSES' => '']);
    }
}
