<?php

declare(strict_types=1);

namespace RentWatch\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RentWatch\Cli\Scout;

/**
 * The CLI, driven end to end against the committed fixture source.
 *
 * Every assertion here reads REAL stdout. `CLAUDE.md` § Completion gate is explicit that a claim
 * about `scout` output is evidenced by its actual output — *"the doctor command is implemented"* is
 * not evidence, and a test that asserted on a mocked writer would be the same claim in a different
 * costume.
 *
 * Categories: **exit codes** (this runs under a supervisor) · **doctor** (§8's four columns) ·
 * **seed** (Q36) · **refusals** (Q26, Q28, Q36) · **payload** (what actually reaches a phone).
 */
final class ScoutTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private ?string $dbPath = null;

    /** @var list<string> */
    private array $tempRoots = [];

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-cli-' . bin2hex(random_bytes(8)) . '.sqlite3';
        putenv('RENT_WATCH_DB=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('RENT_WATCH_DB');
        putenv('NTFY_TOPIC');
        putenv('SMTP_TO');

        if ($this->dbPath !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->dbPath . $suffix);
            }
        }

        foreach ($this->tempRoots as $root) {
            @unlink($root . '/config/criteria.json');
            @unlink($root . '/config/sources.json');
            @rmdir($root . '/config');
            @rmdir($root);
        }
        $this->tempRoots = [];
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scout(array $argv): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        // A FIXED clock. Without one, `STALE` and the counting window depend on wall time, and a
        // test that passes at 23:59 and fails at 00:01 teaches people to re-run rather than to read.
        $code = (new Scout(self::ROOT, $out, $err, '2026-08-07T12:00:00+02:00'))->run($argv);

        rewind($out);
        rewind($err);

        return [
            'code' => $code,
            'out' => (string) stream_get_contents($out),
            'err' => (string) stream_get_contents($err),
        ];
    }

    /**
     * A throwaway repo root with its own config, so the CLI's config-dependent refusals can be
     * exercised.
     *
     * Added because a sabotage run showed four of them were unreachable from the committed config
     * alone: nothing in `config/sources.json` is a pollable private portal, so the scraping gate
     * never ran; nothing names a bad channel, so that refusal never ran. A guard no test can reach
     * is a guard that will be deleted by someone who sees no failure.
     *
     * @param array<string,mixed> $criteria
     * @param array<string,mixed> $sources
     */
    private function tempRoot(array $criteria = [], array $sources = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-root-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0o775, true);
        $this->tempRoots[] = $root;

        file_put_contents($root . '/config/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 4,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/sources.json', json_encode([
            'sources' => $sources === [] ? ['demo' => [
                'enabled' => false,
                'family' => 'institutional',
                'type' => 'json',
                'mixed_tenure' => true,
                'map' => ['ref' => 'id'],
            ]] : $sources,
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scoutIn(string $root, array $argv): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Scout($root, $out, $err, '2026-08-07T12:00:00+02:00'))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    // ---------------------------------------------------------------- doctor

    public function testDoctorReportsAllFourColumnsSpecAsksFor(): void
    {
        // spec §8: "run every source once, report status, TIMING and item counts". Three of the four
        // were implemented for a long time and the fourth was aspirational, which is what Q25 was
        // raised about. All four now appear.
        $r = $this->scout(['doctor']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('SOURCE', $r['out']);
        self::assertStringContainsString('ÉTAT', $r['out']);
        self::assertStringContainsString('ITEMS', $r['out']);
        self::assertStringContainsString('DURÉE', $r['out']);
        self::assertMatchesRegularExpression('~fixture_demo\s+ok\s+10\s+\d+ ms~', $r['out']);
    }

    public function testDoctorPrintsTheJournalMode(): void
    {
        // CLAUDE.md § Testing requires it: WAL can be silently refused on a network mount, and a
        // store in rollback-journal mode makes two processes contend instead of share. Both
        // failures are silent.
        $r = $this->scout(['doctor']);

        self::assertMatchesRegularExpression('~journal (wal|delete|memory|truncate|persist|off)~', $r['out']);
    }

    public function testDoctorPrintsTheSchemaVersionAndTheDigestTimezone(): void
    {
        $r = $this->scout(['doctor']);

        self::assertStringContainsString('schéma v3', $r['out']);
        self::assertStringContainsString('digest à 8h', $r['out'], 'Q34: the digest hour must be visible with its timezone');
    }

    // ---------------------------------------------------------------- seed (Q36)

    public function testRunRefusesOnAFreshlyCreatedDatabase(): void
    {
        // Q8 rules out GitHub Actions BECAUSE no persistent disk means re-notifying everything, then
        // adopts Docker-on-a-VPS, which has the identical failure mode. A typo in `-v` produces a
        // valid, empty, migrated database indistinguishable from a healthy one.
        $r = $this->scout(['run', '--once']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('base vide', $r['err']);
        self::assertStringContainsString('--seed', $r['err'], 'the refusal must name the way through');
        self::assertSame('', $r['out'], 'nothing may be notified on a fresh database');
    }

    public function testSeedPopulatesTheSeenSetWithoutNotifying(): void
    {
        $r = $this->scout(['run', '--seed']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('seen-set amorcé', $r['out']);
        self::assertStringNotContainsString('[MATCH]', $r['out']);
    }

    public function testTheRunAFTERSeedIsSilentRatherThanFloodingOneRunLater(): void
    {
        // THE BUG THIS TEST EXISTS FOR. Recording the sighting alone was not enough: `notified_at`
        // stayed NULL, so the next run notified all six matches and the flood simply moved one run
        // later — exactly what Q36 exists to prevent. `--seed` means "already seen AND already told
        // about"; only what appears afterwards is news.
        $this->scout(['run', '--seed']);
        $second = $this->scout(['run', '--once']);

        self::assertSame(0, $second['code'], $second['err']);
        self::assertStringNotContainsString('[MATCH]', $second['out']);
        self::assertStringNotContainsString('[DIGEST]', $second['out']);
    }

    public function testTheDigestDoesNotRepeatItselfOnEveryPass(): void
    {
        // Q34: entries are marked emitted only after delivery. Without that the digest re-sends its
        // whole contents every pass, which is the alert fatigue it was designed to avoid — and a
        // digest the developer has learned to skip costs the fail-closed rule its only landing zone.
        $this->scout(['run', '--seed']);
        $this->scout(['run', '--once']);
        $third = $this->scout(['run', '--once']);

        self::assertStringNotContainsString('[DIGEST]', $third['out']);
    }

    // ---------------------------------------------------------------- payload

    public function testAFirstRealRunEmitsTheNotificationPayload(): void
    {
        // Seeded, then the seen-set is emptied so the listings are new again — which is the closest
        // an offline test gets to "a source published something".
        $this->scout(['run', '--seed']);
        $pdo = new \PDO('sqlite:' . (string) $this->dbPath);
        $pdo->exec('DELETE FROM listings');

        $r = $this->scout(['run', '--once']);

        self::assertSame(0, $r['code'], $r['err']);
        // The high-priority match, with its score, its commune, its rent and its reasons — the
        // shape that has to be readable on a lock screen.
        self::assertStringContainsString('!! [MATCH] 75/100 — Sartrouville · T4 88 m² · 1450 € CC', $r['out']);
        self::assertStringContainsString('champ structuré financement = « LLI »', $r['out']);
        self::assertStringContainsString('1450 € CC — 350 € sous le plafond', $r['out']);
        self::assertStringContainsString('https://example.test/annonces/demo-0001', $r['out']);
    }

    public function testTheDigestEntryExplainsItselfRatherThanBeingABareLink(): void
    {
        $this->scout(['run', '--seed']);
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec('DELETE FROM listings');

        $r = $this->scout(['run', '--once']);

        self::assertStringContainsString('[DIGEST] À vérifier : 1 annonce(s)', $r['out']);
        self::assertStringContainsString('aucun signal dans l\'annonce', $r['out']);
    }

    public function testNothingWithAnExcludedTenureAppearsInTheOutput(): void
    {
        // §1, at the only surface the developer actually sees. The fixture carries a PLAI listing.
        $this->scout(['run', '--seed']);
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec('DELETE FROM listings');

        $r = $this->scout(['run', '--once']);

        self::assertStringNotContainsString('demo-0002', $r['out'], 'the PLAI listing must not be surfaced');
        self::assertStringNotContainsString('PLAI', $r['out']);
    }

    public function testVerboseNamesEveryRejectionSoOverFilteringIsVisible(): void
    {
        // A hard disqualifier rejects silently and is logged only (hard rule 8), so `-v` is the ONLY
        // way a mis-scoped filter is ever noticed — nothing arrives either way.
        $this->scout(['run', '--seed']);
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec('DELETE FROM listings');

        $r = $this->scout(['run', '--once', '-v']);

        self::assertStringContainsString('écartée fixture_demo:demo-0002 — tenure: PLAI', $r['out']);
        self::assertStringContainsString('écartée fixture_demo:demo-0009 — commune: Nanterre', $r['out']);
    }

    // ---------------------------------------------------------------- dump

    public function testDumpShowsTheRawItemAndTheMappedResult(): void
    {
        // spec §10: what makes onboarding a source take five minutes. It prints the MAPPED listing
        // too, so a field map that silently reads nothing shows as a row of (null) here rather than
        // being discovered weeks later as a quiet source.
        $r = $this->scout(['dump', 'fixture_demo']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('annonce brute', $r['out']);
        self::assertStringContainsString('après application du field map', $r['out']);
        self::assertStringContainsString('effectiveRentCc', $r['out']);
        self::assertStringContainsString('tenure           LLI', $r['out']);
    }

    public function testDumpNamesTheKnownSourcesWhenAskedForOneThatDoesNotExist(): void
    {
        $r = $this->scout(['dump', 'nope']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('source inconnue', $r['err']);
        self::assertStringContainsString('fixture_demo', $r['err'], 'a refusal should say what IS available');
    }

    public function testDumpWithoutASourceIsAUsageError(): void
    {
        $r = $this->scout(['dump']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('usage', $r['err']);
    }

    // ---------------------------------------------------------------- refusals

    public function testAnUnknownCommandIsRefusedWithTheUsage(): void
    {
        $r = $this->scout(['frobnicate']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('commande inconnue', $r['err']);
        self::assertStringContainsString('scout doctor', $r['out']);
    }

    public function testWatchRefusesRatherThanRunningAnUnpacedLoop(): void
    {
        // Q37 ruled the pacing — 15 min ± 5, 5 s between hosts, 60 s per host, shuffled order —
        // and an unpaced loop over 15 sources from one IP is what a scraper looks like. Refusing
        // with the ruling quoted is honest; a loop that ignored it would get the developer's own
        // IP banned (hard rule 5).
        $r = $this->scout(['run', '--watch']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('Q37', $r['err']);
        self::assertStringContainsString('--once', $r['err'], 'the refusal must name what to use instead');
    }

    public function testHelpListsEveryDocumentedVerb(): void
    {
        $r = $this->scout(['help']);

        self::assertSame(0, $r['code']);
        foreach (['doctor', 'dump', 'run --once', '--seed', 'digest', 'reclassify', 'test-notify'] as $verb) {
            self::assertStringContainsString($verb, $r['out'], "spec §10 lists `{$verb}`");
        }
        self::assertStringContainsString('--i-accept-legal-risk', $r['out'], 'hard rule 4 / Q26');
    }

    public function testPollingAPrivatePortalIsSKIPPEDWithoutTheLegalRiskFlag(): void
    {
        // Hard rule 4 / Q26. Direct scraping of a private portal is opt-in and must REFUSE to run
        // without an explicit flag — and the flag is never persisted in config, so starting a
        // scrape stays a deliberate act each time rather than a boolean somebody flipped once.
        $root = $this->tempRoot(sources: ['portal' => [
            'enabled' => true,
            'family' => 'private',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor']);

        self::assertStringContainsString('--i-accept-legal-risk', $r['err']);
        self::assertStringContainsString('ignorée', $r['err'], 'skipping must be LOUD — a silent skip is a source that quietly does not run');
    }

    public function testAnUnknownNotificationChannelIsRefusedRatherThanDropped(): void
    {
        // A typo in `notify.channels` that silently yielded nothing would be a channel the developer
        // believes is enabled and is not — which is the "computed and never sent" failure hard rule
        // 2 calls worse than no alert at all.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfyy']]]);

        $r = $this->scoutIn($root, ['test-notify']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('canal inconnu', $r['err']);
        self::assertStringContainsString('ntfyy', $r['err']);
    }

    public function testAMalformedCriteriaFileStopsEverything(): void
    {
        // Q28: a validation error in criteria.json IS a startup refusal, because the criteria decide
        // what is filtered and a wrong filter is invisible — the user sees plausible results forever.
        $root = $this->tempRoot();
        file_put_contents($root . '/config/criteria.json', '{"communes": [');

        $r = $this->scoutIn($root, ['doctor']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('configuration', $r['err']);
    }

    public function testDoctorRecordsAMeasuredDurationForEverySource(): void
    {
        // Q25. The printed column can read "0 ms" on a fast fixture, so the assertion is on what was
        // STORED: null means nobody measured, and that is the state the column was added to end.
        $this->scout(['doctor']);

        $duration = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT duration_ms FROM source_runs WHERE source = 'fixture_demo'")
            ->fetchColumn();

        self::assertNotFalse($duration);
        self::assertNotNull($duration, 'doctor must measure, not merely print a column');
    }

    public function testDoctorPassesTheClockSoStaleCanFire(): void
    {
        // Without `$nowIso`, `Store::health()` has no clock and ONE verdict becomes underivable:
        // STALE never fires at all. That is the clock's only job, and CLAUDE.md § Testing makes
        // passing it a requirement of `scout doctor` in as many words.
        //
        // AND THIS TEST DOES NOT PROVE DOCTOR PASSES IT — stated plainly, because an earlier version
        // of this comment claimed it did. A sabotage run showed the claim was false: dropping the
        // argument in `Scout` leaves the suite green, and it cannot be otherwise. `doctor` records
        // its own successful run immediately before asking for health, so the clock and the newest
        // stamp always agree and no verdict can differ. The argument is defensive there rather than
        // load-bearing; it is load-bearing in the run loop and in any read that is not preceded by a
        // run, which is what this asserts.
        $root = $this->tempRoot(sources: ['stale_demo' => [
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'fixture',
            'mixed_tenure' => true,
            'fixture' => 'tests/fixtures/fixture_demo/search.json',
            'items_path' => 'results.items',
            'map' => ['ref' => 'id'],
        ]]);

        // A run log whose newest entry is two months before the fixed clock. `doctor` records its
        // own run too, so the backdated rows must be inserted with a stamp the health window still
        // sees as the latest — which is what makes the clock the deciding input.
        $pdo = new \PDO('sqlite:' . (string) $this->dbPath);
        \RentWatch\Store\Store::open((string) $this->dbPath);
        $pdo = new \PDO('sqlite:' . (string) $this->dbPath);
        $pdo->exec("INSERT INTO source_runs (source, item_count, ok, error, at, at_epoch, duration_ms)
                    VALUES ('stale_demo', 10, 1, NULL, '2026-06-01T12:00:00+02:00', 1780308000, 5)");

        $store = \RentWatch\Store\Store::open((string) $this->dbPath);

        self::assertSame(
            \RentWatch\Core\SourceStatus::STALE,
            $store->health('stale_demo', '2026-08-07T12:00:00+02:00')->status,
            'with a clock, a source last seen in June is STALE in August',
        );
        self::assertNotSame(
            \RentWatch\Core\SourceStatus::STALE,
            $store->health('stale_demo')->status,
            'and WITHOUT a clock the same rows cannot produce that verdict — which is exactly why '
            . 'doctor must pass one, and why asserting on the store alone proves nothing about doctor',
        );

        unset($root);
    }

    public function testTestNotifyReportsSuccessThroughTheConsoleChannel(): void
    {
        $r = $this->scout(['test-notify']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('test de notification', $r['out']);
    }

    public function testReclassifyListsRowsWithNoRecordedVerdict(): void
    {
        $this->scout(['run', '--seed']);
        $r = $this->scout(['reclassify']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('verdict indéterminé', $r['out']);
        // Honest about what is not built: the classifier needs the ad's TEXT, which `listings` does
        // not keep. Saying so beats a verb that appears to work and silently does nothing.
        self::assertStringContainsString('schéma v4', $r['out']);
    }
}
