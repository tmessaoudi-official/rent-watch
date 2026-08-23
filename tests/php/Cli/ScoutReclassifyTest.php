<?php

declare(strict_types=1);

namespace RentWatch\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RentWatch\Cli\Scout;
use RentWatch\Core\RawListing;
use RentWatch\Store\Store;

/**
 * `scout reclassify` — Q35, and the §1 surface of the two commands that close Bucket A.
 *
 * The problem it solves is a permanent silent miss. The seen-set guarantees a listing is new
 * exactly once, so a listing digested as `UNKNOWN` under a classifier that is later improved is
 * never surfaced again by anything. Q18 (PLI) and Q21 (a shouted `PLUS`) both route there
 * deliberately, so that bin is not small.
 *
 * **The invariant is `reclassify runs on evidence ⊇ original, never ⊂`, and it is not a quality
 * preference — it is what stops this command being a §1 breach.** A card whose structured field
 * says `PLS` while its title says *logement intermédiaire* classifies `UNKNOWN` today BY CONFLICT.
 * Re-run on the title alone it becomes a MATCH. So a reclassify that degrades an evidence-less row
 * to whatever text is lying around does not make a smaller improvement: it manufactures the one
 * outcome §1 forbids, and it does it preferentially to the listings most likely to be social,
 * because those are the ones whose evidence conflicts.
 *
 * {@see testARowWithNoStoredEvidenceIsSkippedAndCountedNeverJudgedOnLess} is the case that pins it.
 *
 * The command re-JUDGES rather than merely re-classifying: Q35's promotion test is on `Outcome`,
 * which only the criteria engine produces, so each row runs the classifier AND the full judge
 * against TODAY's criteria.
 */
final class ScoutReclassifyTest extends TestCase
{
    private const NOW = '2026-08-23T21:00:00+02:00';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        $this->roots = [];
        putenv('RENT_WATCH_DB');
    }

    public function testAnImprovedVerdictIsRecordedAndAPromotionIsNotified(): void
    {
        $root = $this->tempRoot();
        // Stored UNKNOWN, but its snapshot says `logement intermédiaire` in plain words: exactly the
        // row Q35 exists for — one an improved classifier can now resolve.
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'A-1',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 promotion', mb_strtolower($result['out']));

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('MATCH', $store->outcome($key));
        self::assertTrue($store->wasNotified($key), 'a promoted listing is notified — that is the miss Q35 recovers');
    }

    public function testARowWithNoStoredEvidenceIsSkippedAndCountedNeverJudgedOnLess(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'B-2',
            // The title alone reads as an eligible intermediate listing. The evidence that made this
            // row UNKNOWN — a structured field saying PLS — is exactly what a pre-v7 row no longer
            // has, so judging it on this title is how a social listing becomes a match.
            title: 'Logement intermédiaire T4',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');
        $this->stripSnapshot($root, $key);

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('sans instantané', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key), 'an evidence-less row keeps its verdict — it is not re-judged on less');
        self::assertFalse($store->wasNotified($key), 'and it is certainly never notified');
    }

    public function testACorruptSnapshotIsReportedWithoutVoidingTheRun(): void
    {
        $root = $this->tempRoot();
        $damaged = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'C-3',
            title: 'T4 Antony',
            rentCc: 1300,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');
        $this->corruptSnapshot($root, $damaged);

        $healthy = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'C-4',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        // Loud AND scoped. One damaged row voiding the whole run is the blast-radius mistake detail
        // hydration already made once: a single bad page must not stop every other listing being
        // judged.
        self::assertStringContainsString('illisible', $result['out'] . $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($damaged), 'the damaged row keeps its verdict untouched');
        self::assertSame('MATCH', $store->outcome($healthy), 'and the healthy one is still judged');
    }

    public function testTodaysCriteriaDecideTheOutcomeSoATightenedCeilingRejects(): void
    {
        // Re-judging means TODAY's criteria, not the ones in force when the row was stored. A flat
        // whose tenure now resolves cleanly can still fail a ceiling that has since been lowered —
        // and it must record REJECT rather than be notified on a stale rule.
        $root = $this->tempRoot(['max_rent_cc' => 1200]);
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'D-4',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('REJECT', $store->outcome($key));
        self::assertFalse($store->wasNotified($key), 'a rejection is recorded silently, never announced');
    }

    public function testAMemberThatWasNeverJudgedGetsAVerdictButNoOutcome(): void
    {
        // Every dedup MEMBER is classified; only the SURVIVOR is judged. `NULL` outcome is precisely
        // what distinguishes "never judged" from "judged and rejected", and reclassify must not
        // manufacture an outcome for a row the criteria engine never saw.
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'E-5',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: null);

        $this->scout($root, ['reclassify']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertNull($store->outcome($key), 'an unjudged member stays unjudged');
        self::assertFalse($store->wasNotified($key));
    }

    public function testAListingWhoseSourceNoLongerExistsIsJudgedFailClosed(): void
    {
        // A source removed from sources.json leaves its listings behind. With no profile the
        // classifier must assume mixed tenure and no default — the digest-biased direction — never
        // the reverse, which would let a vanished landlord's stock resolve as eligible by omission.
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'a_landlord_since_removed',
            externalId: 'F-6',
            title: 'T4 sans mention de régime',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key), 'no signal and no profile digests, it never matches');
        self::assertFalse($store->wasNotified($key));
    }

    public function testDryRunChangesNothing(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'G-7',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify', '--dry-run']);

        self::assertSame(0, $result['code'], $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key));
        self::assertFalse($store->wasNotified($key));
    }

    public function testSinceIsRefusedRatherThanGivenInventedSemantics(): void
    {
        // Q35 names `--since`, and its staleness mechanism is a stored classifier version that does
        // not exist. Answering it against `last_seen_at` would fake the ruling's mechanism with a
        // different one; refusing says so out loud, and re-running the whole bin costs seconds.
        $root = $this->tempRoot();

        $result = $this->scout($root, ['reclassify', '--since=2026-08-01']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('--since', $result['err']);
    }

    // ── harness ───────────────────────────────────────────────────────────────────────────────────

    private function seed(string $root, RawListing $listing, string $tenure, ?string $outcome): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, $tenure, 0, ['aucun signal de régime'], $listing);
        if ($outcome !== null) {
            $store->recordOutcome($sighting->dedupKey, $outcome);
        }

        return $sighting->dedupKey;
    }

    private function stripSnapshot(string $root, string $key): void
    {
        $this->pdo($root)
            ->prepare('UPDATE listings SET evidence_json = NULL WHERE dedup_key = :key')
            ->execute(['key' => $key]);
    }

    private function corruptSnapshot(string $root, string $key): void
    {
        $this->pdo($root)
            ->prepare('UPDATE listings SET evidence_json = :json WHERE dedup_key = :key')
            ->execute(['json' => '{not json at all', 'key' => $key]);
    }

    private function pdo(string $root): \PDO
    {
        $pdo = new \PDO('sqlite:' . $root . '/state/rent-watch.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scout(string $root, array $argv): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        putenv('RENT_WATCH_DB=' . $root . '/state/rent-watch.sqlite3');

        $code = (new Scout($root, $out, $err, self::NOW))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /** @param array<string,mixed> $criteria */
    private function tempRoot(array $criteria = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-recl-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        $this->roots[] = $root;

        file_put_contents($root . '/config/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'min_surface_m2' => 50,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/sources.json', json_encode([
            'sources' => [
                'demo' => [
                    'enabled' => false,
                    'family' => 'institutional',
                    'type' => 'fixture',
                    'mixed_tenure' => true,
                    'fixture' => 'tests/fixtures/fixture_demo/search.json',
                    'items_path' => 'results.items',
                    'map' => ['ref' => 'id', 'title' => 'title'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
