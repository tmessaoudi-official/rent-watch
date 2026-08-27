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
 * repo, so a rename applied here and not there leaves `RENT_WATCH_DB` set and `SCOUT_DB` unset.
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
        LegacyEnv::check(['SCOUT_DB' => 'state/x.sqlite3', 'TZ' => 'Europe/Paris']);
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
        yield 'db' => ['RENT_WATCH_DB', 'SCOUT_DB'];
        yield 'offline' => ['RENT_WATCH_OFFLINE', 'SCOUT_OFFLINE'];
        yield 'max passes' => ['RENT_WATCH_MAX_PASSES', 'SCOUT_MAX_PASSES'];
        yield 'backup keep' => ['RENT_WATCH_BACKUP_KEEP', 'SCOUT_BACKUP_KEEP'];
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

        LegacyEnv::check(['RENT_WATCH_DB' => 'old.sqlite3', 'SCOUT_DB' => 'new.sqlite3']);
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
            self::assertStringContainsString('SCOUT_DB', $e->getMessage());
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

    /** An empty legacy value is still SET — the shadowing case, and the one that reads as absent. */
    public function testAnEmptyLegacyValueIsStillRefused(): void
    {
        $this->expectException(ConfigError::class);

        LegacyEnv::check(['RENT_WATCH_MAX_PASSES' => '']);
    }
}
