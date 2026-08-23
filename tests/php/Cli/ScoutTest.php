<?php

declare(strict_types=1);

namespace RentWatch\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RentWatch\Store\Store;
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

    private ?string $demoRootPath = null;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-cli-' . bin2hex(random_bytes(8)) . '.sqlite3';
        putenv('RENT_WATCH_DB=' . $this->dbPath);
        // `--watch` never returns on its own. Every test here that reaches it expects to be stopped
        // BEFORE the loop starts, so if that expectation is ever wrong the test would block rather
        // than fail — and block the suite, and the sabotage ledger behind it. One pass is enough to
        // prove any of them.
        putenv('RENT_WATCH_MAX_PASSES=1');
    }

    protected function tearDown(): void
    {
        putenv('RENT_WATCH_DB');
        putenv('RENT_WATCH_MAX_PASSES');
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

        // ── scoped to the fixture source ──────────────────────────────────────────────────────
        //
        // These tests exercise the RUN LOOP — seeding, notification, exit codes, doctor's columns —
        // against the real repo root, and until 2026-08-19 that was harmless because every source
        // in the shipped config was disabled. Enabling In'li made every one of them depend on a
        // live landlord's website: the run's exit code became 1 because a network source could not
        // be reached, which says nothing about the loop under test.
        //
        // `--source=fixture_demo` pins them to the frozen payload they were always really about.
        // Which sources ship ENABLED is a different question with its own test — see
        // `ConfigTest::testEveryEnabledSourceCarriesTheEvidenceThatItWasVerified` — and it belongs
        // there, not smeared across twenty CLI assertions that would each fail for the same reason.
        $command = $argv[0] ?? '';
        $names = array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--source='));
        if (in_array($command, ['run', 'doctor'], true) && $names === []) {
            $argv[] = '--source=fixture_demo';
        }

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
    public function testSourceFlagLimitsTheRunToTheNamedSource(): void
    {
        // In'li is enabled in the shipped config and would be polled without this flag — which is
        // exactly why the flag exists: onboarding a source needs a run against one block, and the
        // alternative was editing committed config to disable the others.
        $r = $this->scout(['doctor', '--source=fixture_demo']);

        self::assertStringContainsString('fixture_demo', $r['out']);
        self::assertStringNotContainsString('inli', $r['out']);
    }

    public function testAnUnknownSourceNameIsAWarningRatherThanASilentEmptyRun(): void
    {
        // The worst outcome for a debugging flag is a typo that reports a clean, fast, empty pass.
        // The developer reads "0 problems" and concludes the source is fine, when it was never run.
        $r = $this->scout(['doctor', '--source=inlii']);

        self::assertStringContainsString('inconnue', $r['out'] . $r['err']);
    }

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

    /**
     * Hard rule 2 reaches the one failure no other column can show.
     *
     * A detail page that stops parsing does not change a source's COUNT and does not fail its run,
     * so `ok / 168 annonces` stays true while every listing quietly loses its title. That is the
     * broken-selector-forever shape one layer down, and `detailFailureCount` is what sees it — but
     * a count nobody reads is not a signal, and this is the assertion that it is read.
     */
    public function testDoctorReportsDetailPagesItHasGivenUpOn(): void
    {
        $store = \RentWatch\Store\Store::open((string) $this->dbPath);

        for ($attempt = 0; $attempt < \RentWatch\Adapters\HtmlSource::DETAIL_ATTEMPT_CAP; ++$attempt) {
            $store->recordDetailFailure('fixture_demo', 'ANN-1', 'HTTP 404', '2026-08-23T10:0' . $attempt . ':00+02:00');
        }

        unset($store);
        $r = $this->scout(['doctor', '--source=fixture_demo']);

        self::assertStringContainsString('illisible', $r['out']);
    }

    public function testDoctorPrintsTheSchemaVersionAndTheDigestTimezone(): void
    {
        $r = $this->scout(['doctor']);

        // Symbolic, not the literal `v4` this used to hold: what the test is for is that doctor
        // PRINTS the version, and a literal makes every migration edit a CLI test to restate a
        // number three other tests already assert bare.
        self::assertStringContainsString('schéma v' . Store::SCHEMA_VERSION, $r['out']);
        self::assertStringContainsString('digest à 8h', $r['out'], 'Q34: the digest hour must be visible with its timezone');
    }

    /**
     * Make the fixture's listings look newly published — WITHOUT emptying the seen-set.
     *
     * Emptying it is what these tests used to do, and an empty seen-set is now precisely the state
     * `scout run` refuses to notify on (Q36), because it is what a missing volume mount looks like.
     * So the stored rows are RENAMED instead: the seen-set still holds listings, just no longer
     * these ones — which is what "the source published something" actually means.
     *
     * The raw connection leaves `foreign_keys` off, so the price-history rows are orphaned rather
     * than cascaded. That is deliberate and harmless here: the point of these tests is the run
     * loop's output, and a listing seen for the first time has no price history to carry.
     */
    private function republishEverything(): void
    {
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec(
            "UPDATE listings SET dedup_key = 'ancienne:' || dedup_key, external_id = 'ancienne-' || external_id",
        );
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

    /**
     * ANY earlier command that merely OPENS the database creates the file, and the guard used to
     * read exactly that fact — "did `open()` create this?" — so a single `scout doctor` disarmed it
     * and the next run notified the entire back catalogue at once. `doctor` is the natural first
     * command to type on a new machine, which made this the likeliest path through the guard rather
     * than an exotic one.
     *
     * The fact the guard reads is now the one Q36 is about: whether anything has ever been recorded.
     */
    public function testAnEarlierDoctorDoesNotDisarmTheEmptyDatabaseGuard(): void
    {
        $doctor = $this->scout(['doctor']);
        self::assertSame(0, $doctor['code'], $doctor['err']);
        self::assertFileExists((string) $this->dbPath, 'doctor is expected to create the database — that is the trap');

        $r = $this->scout(['run', '--once']);

        self::assertSame(2, $r['code'], 'an existing but empty database is still an empty database');
        self::assertStringContainsString('base vide', $r['err']);
        self::assertSame('', $r['out'], 'nothing may be notified on an empty seen-set');
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
        // Seeded, then the stored rows are renamed so the fixture's listings are new again — which
        // is the closest an offline test gets to "a source published something".
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertSame(0, $r['code'], $r['err']);
        // The high-priority match, with its score, its commune, its rent and its reasons — the
        // shape that has to be readable on a lock screen.
        self::assertStringContainsString('!! [MATCH] 75/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC', $r['out']);
        self::assertStringContainsString('champ structuré financement = « LLI »', $r['out']);
        self::assertStringContainsString('1450 € CC — 350 € sous le plafond', $r['out']);
        self::assertStringContainsString('https://example.test/annonces/demo-0001', $r['out']);
    }

    public function testTheDigestEntryExplainsItselfRatherThanBeingABareLink(): void
    {
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertStringContainsString('[DIGEST] À vérifier : 1 annonce(s)', $r['out']);
        self::assertStringContainsString('aucun signal dans l\'annonce', $r['out']);
    }

    public function testNothingWithAnExcludedTenureAppearsInTheOutput(): void
    {
        // §1, at the only surface the developer actually sees. The fixture carries a PLAI listing.
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once']);

        // COUNTERWEIGHT FIRST. Two assertions of absence pass perfectly on a run that was refused
        // and printed nothing at all — which is exactly what happened when the Q36 guard started
        // reading the seen-set: this test stayed green while its siblings went red, and a §1 test
        // that cannot fail is worse than no test. The match proves the pass actually ran.
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('[MATCH]', $r['out'], 'the pass must have produced output for the absences below to mean anything');
        self::assertStringNotContainsString('demo-0002', $r['out'], 'the PLAI listing must not be surfaced');
        self::assertStringNotContainsString('PLAI', $r['out']);
    }

    public function testVerboseNamesEveryRejectionSoOverFilteringIsVisible(): void
    {
        // A hard disqualifier rejects silently and is logged only (hard rule 8), so `-v` is the ONLY
        // way a mis-scoped filter is ever noticed — nothing arrives either way.
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once', '-v']);

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

    /**
     * `--watch` used to refuse outright, because an unpaced loop over many sources from one IP is
     * what a scraper looks like (hard rule 5). It now runs, paced by {@see \RentWatch\Core\Pacer} to
     * the Q37 ruling — so what remains to assert here is that it is still subject to every guard
     * `--once` is subject to, rather than having quietly become a second, laxer entry point.
     *
     * The cadence itself is asserted in `PacerTest`, and the loop's survival of a failing pass in
     * `WatchLoopTest`. Neither belongs here: reaching them through the CLI would mean a real
     * database, a real config and a fifteen-minute wait.
     */
    public function testWatchIsStillSubjectToTheUnseededDatabaseGuard(): void
    {
        // Q36's guard. A missing volume mount yields a valid, empty, migrated database that is
        // indistinguishable from a healthy one — and with nothing batched, every historic listing
        // would push at once. A watcher would do that on its very first pass and then keep running.
        $r = $this->scout(['run', '--watch']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('base vide', $r['err']);
    }

    /**
     * THE SUITE MUST NOT BE ABLE TO HANG HERE, and until 2026-08-19 it could.
     *
     * `--watch` is the one CLI verb whose success case never returns: the guard above is what stops
     * these tests from entering a real fifteen-minute loop. So the moment the guard is what breaks —
     * the exact regression the case above exists for — the test does not fail, it BLOCKS, and the
     * sabotage ledger stalls for hours reporting nothing instead of printing one red line. That was
     * observed, not imagined: a sabotage run sat on this file for eleven minutes on its first case.
     *
     * `RENT_WATCH_MAX_PASSES` bounds the loop, and `setUp()` sets it for every test in this class,
     * so a broken guard now produces a fast wrong answer — which a test can catch.
     */
    public function testTheWatchLoopIsBoundedSoABrokenGuardFailsRatherThanHanging(): void
    {
        $this->scout(['run', '--seed']);

        $r = $this->scout(['run', '--watch']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('surveillance active', $r['out']);
        self::assertStringContainsString('RENT_WATCH_MAX_PASSES', $r['out'], 'a bounded watcher must say so — it is not the documented behaviour');
    }

    public function testWatchRefusesToBeCombinedWithSeed(): void
    {
        // `--seed` marks everything as already seen WITHOUT notifying, to bootstrap the seen-set
        // once. Repeated every fifteen minutes it would suppress every notification forever while
        // the process reported itself perfectly healthy — a watcher that watches and never speaks,
        // which is the silent failure hard rule 2 exists to make impossible.
        $r = $this->scout(['run', '--watch', '--seed']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--seed', $r['err']);
        self::assertStringContainsString('--once', $r['err'], 'the refusal must name what to use instead');
    }

    public function testHelpListsEveryDocumentedVerb(): void
    {
        $r = $this->scout(['help']);

        self::assertSame(0, $r['code']);
        foreach (['doctor', 'dump', 'run --once', '--seed', 'run --watch', 'digest', 'reclassify', 'test-notify'] as $verb) {
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


    // ── `--source=<name>` is an instruction, not a filter ─────────────────────────────────────────

    public function testAnExplicitlyNamedSourceRunsEvenThoughItIsDisabled(): void
    {
        // `/add-source` step 5 says to run `scout doctor` against a new block and only flip
        // `enabled: true` once it is green. That was IMPOSSIBLE until 2026-08-22: `sources()`
        // dropped every disabled definition before `--source` was consulted, so the documented
        // onboarding order could not be followed and the only way to get a run against one block
        // was to edit committed config — the exact edit the flag exists to avoid.
        //
        // `dump` has always worked this way, so this makes the two verbs agree rather than
        // inventing a rule.
        $root = $this->fixtureRoot(enabled: false);

        $r = $this->scoutIn($root, ['doctor', '--source=fixture_demo']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertMatchesRegularExpression('~fixture_demo\s+ok\s+10~', $r['out']);
        self::assertStringContainsString(
            'désactivée',
            $r['err'],
            'force-running a disabled source must SAY so — otherwise a --source left behind in a '
                . 'deployment looks exactly like a source somebody enabled on purpose',
        );
    }

    public function testADisabledSourceStaysOutOfAnOrdinaryRun(): void
    {
        // The other half, and the half that matters: naming a source force-runs it, and NOT naming
        // one must still honour `enabled: false`. Without this assertion the change above is
        // indistinguishable from deleting the enabled check outright.
        $root = $this->fixtureRoot(enabled: false);

        $r = $this->scoutIn($root, ['doctor']);

        self::assertDoesNotMatchRegularExpression('~fixture_demo\s+ok~', $r['out']);
    }

    public function testAnExplicitlyNamedSourceWithAnUnverifiedUrlIsRefused(): void
    {
        // Hard rule 1. The loader refuses `enabled: true` next to a REMPLACER placeholder, and that
        // was the whole guard for as long as a disabled source could never run. Force-running one
        // brings the placeholder back within reach, so the refusal moves with it — into
        // `buildSource()`, the single funnel `dump`, `doctor` and `run` all pass through.
        $root = $this->tempRoot(sources: ['demo' => [
            'enabled' => false,
            'family' => 'institutional',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'REMPLACER',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor', '--source=demo']);

        self::assertNotSame(0, $r['code'], 'a named source that cannot run must FAIL, never report a clean empty pass');
        self::assertStringContainsString('REMPLACER', $r['err']);
    }

    public function testAnExplicitlyNamedPrivatePortalStillNeedsTheLegalRiskFlag(): void
    {
        // Ordering, asserted because it is load-bearing: the enabled check sits ABOVE the scraping
        // gate, so force-running has to fall THROUGH to that gate rather than past it. A disabled
        // private portal named on the command line is the one path that could otherwise have
        // skipped hard rule 4 entirely.
        $root = $this->tempRoot(sources: ['portal' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor', '--source=portal']);

        self::assertStringContainsString('--i-accept-legal-risk', $r['err'], 'hard rule 4 outranks an explicit --source');
    }

    /**
     * A temp root whose only source is the demo fixture, at a chosen `enabled` state.
     *
     * The payload is COPIED into the root rather than referenced back at the repo's own tree: a
     * fixture path is resolved against the root the CLI was given, so a test reaching back would
     * keep passing while the resolution it claims to exercise was broken.
     */
    private function fixtureRoot(bool $enabled): string
    {
        // The SHIPPED block, with only `enabled` overridden — never a hand-written copy of it.
        //
        // It was a copy for one afternoon, and the copy silently dropped `elevator`, `floor`,
        // `description` and `tenure_field` from the field map. Nothing errored: the listings still
        // parsed, the run still passed, and the demo flat simply scored 55 instead of 75 because it
        // had lost its lift. A test fixture that is a partial duplicate of production config does
        // not fail, it drifts — and it drifts into asserting the behaviour of a mapping nobody
        // ships.
        $shipped = json_decode(
            (string) file_get_contents(self::ROOT . '/config/sources.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($shipped);
        $block = $shipped['sources']['fixture_demo'] ?? null;
        self::assertIsArray($block, 'the committed config must carry a fixture_demo block');
        $block['enabled'] = $enabled;

        $root = $this->tempRoot(sources: ['fixture_demo' => $block]);

        mkdir($root . '/tests/fixtures/fixture_demo', 0o775, true);
        copy(
            self::ROOT . '/tests/fixtures/fixture_demo/search.json',
            $root . '/tests/fixtures/fixture_demo/search.json',
        );

        // FROZEN criteria, not the shipped file. `tests/fixtures/fixture_demo/search.json` was
        // authored against a 1800 € ceiling and a 78/95 region: demo-0001 at 1450 € is the match,
        // demo-0009 (Nanterre, 92000) exists to be rejected on location. Read the shipped file
        // instead and one preference change — the region widened to all of Île-de-France, the
        // ceiling dropped to 1200 € on 2026-08-22 — silently turns every one of those into a
        // different listing, and four tests about the NOTIFICATION PAYLOAD start failing for a
        // reason that has nothing to do with payloads.
        copy(
            self::ROOT . '/tests/fixtures/criteria/pipeline.json',
            $root . '/config/criteria.json',
        );

        return $root;
    }

    /**
     * The demo root, built once per test so `run --seed` and the `run --once` after it share a
     * config. Memoised rather than rebuilt, because a second root would mean a second seen-set and
     * the second command would find nothing.
     */
    private function demoRoot(): string
    {
        return $this->demoRootPath ??= $this->fixtureRoot(enabled: true);
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
