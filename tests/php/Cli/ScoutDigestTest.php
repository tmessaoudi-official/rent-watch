<?php

declare(strict_types=1);

namespace RentWatch\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RentWatch\Cli\Scout;
use RentWatch\Core\RawListing;
use RentWatch\Store\Store;

/**
 * `scout digest` — the ON-DEMAND half of Q34.
 *
 * The automatic half has worked since the pipeline was written: any pass producing new digest
 * entries emits them, marks them only after the channel confirms, and retries next run. This
 * command exists for the case that retry CANNOT reach — the pipeline re-offers an undelivered entry
 * only while the ad is still published, so a digest entry whose listing is delisted between two
 * passes is lost with nothing anywhere saying so. Reading the STORE rather than the pass is the
 * whole difference.
 *
 * **The load-bearing test in this file is {@see testARowWithoutASnapshotIsAnnouncedNeverSkipped}.**
 * Every row this command was ruled to rescue predates schema v7, so its `evidence_json` is `NULL`
 * by design — not backfilled, because an invented snapshot is indistinguishable from a real
 * capture. A digest that skipped evidence-less rows would therefore skip precisely the backlog it
 * exists for, and would do it silently.
 *
 * That is NOT the rule `scout reclassify` follows, and the asymmetry is deliberate rather than an
 * inconsistency: reclassify FORMS a verdict, so running it on less evidence than the original saw
 * is the §1 breach schema v7 was built to prevent. This command only ANNOUNCES a verdict already
 * formed and already stored. Announcing a stored `DIGEST` from the stored title cannot promote
 * anything into a match.
 */
final class ScoutDigestTest extends TestCase
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
        putenv('NTFY_TOPIC');
        putenv('NTFY_SERVER');
    }

    public function testNothingPendingIsSaidOutLoudAndSendsNothing(): void
    {
        $root = $this->tempRoot();

        $result = $this->scout($root, ['digest']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('aucune annonce', mb_strtolower($result['out']));
    }

    public function testAStoredVerdictIsAnnouncedFromItsSnapshotAndMarkedOnlyAfterDelivery(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'A-1',
            title: 'Appartement T4',
            description: 'Logement conventionné',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ));

        $result = $this->scout($root, ['digest']);

        self::assertSame(0, $result['code'], $result['err']);
        // Everything on this line comes from the SNAPSHOT, not from the `listings` columns — which
        // hold neither commune, nor postcode, nor surface. If the snapshot stopped being read, the
        // entry would degrade to "commune inconnue" and still pass a laxer assertion.
        self::assertStringContainsString('Sartrouville 78500', $result['out']);
        self::assertStringContainsString('88', $result['out']);
        self::assertStringContainsString('1450 € CC', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotified($key), 'a delivered entry must be marked');

        // And it must not repeat: a digest that re-announces its whole contents every invocation is
        // the alert fatigue Q34 was ruled to avoid.
        $second = $this->scout($root, ['digest']);
        self::assertStringContainsString('aucune annonce', mb_strtolower($second['out']));
    }

    public function testARowWithoutASnapshotIsAnnouncedNeverSkipped(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'inli',
            externalId: 'B-2',
            title: 'T3 à Longjumeau',
            commune: 'Longjumeau',
            rentCc: 1005,
        ));
        $this->stripSnapshot($root, $key);

        $result = $this->scout($root, ['digest']);

        self::assertSame(0, $result['code'], $result['err']);
        // Announced from the columns `listings` does hold. This is the entire point of the command.
        self::assertStringContainsString('T3 à Longjumeau', $result['out']);
        self::assertStringContainsString('1005 € CC', $result['out']);
        // And counted, so a backlog announced without its full detail says so rather than looking
        // like a source that publishes nothing but titles.
        self::assertStringContainsString('sans instantané', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotified($key));
    }

    public function testACorruptSnapshotIsAnnouncedAndLoudlyCounted(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'cityloger',
            externalId: 'C-3',
            title: 'T4 Antony',
            rentCc: 1300,
        ));
        $this->corruptSnapshot($root, $key);

        $result = $this->scout($root, ['digest']);

        // Loud, because a corrupt snapshot is data damage rather than an expected pre-v7 shape —
        // but the entry is still announced, because dropping it would lose the listing entirely and
        // nothing else will ever surface it.
        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('T4 Antony', $result['out']);
        self::assertStringContainsString('illisible', $result['out'] . $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotified($key));
    }

    public function testNothingIsMarkedWhenNoChannelAccepts(): void
    {
        // `ntfy` against a closed loopback port: `check()` passes, `send()` fails at the socket. No
        // third-party host is contacted, which the offline guarantee requires.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('NTFY_TOPIC=rent-watch-test');
        putenv('NTFY_SERVER=http://127.0.0.1:1');

        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'inli',
            externalId: 'D-4',
            title: 'T3 Massy',
            rentCc: 1100,
        ));

        $result = $this->scout($root, ['digest']);

        self::assertSame(1, $result['code'], 'an undelivered digest must exit non-zero');

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse(
            $store->wasNotified($key),
            'marking before the channel confirms loses the whole batch permanently — a digest entry has no second chance',
        );
        // Still pending, so the next invocation retries it.
        self::assertCount(1, $store->pendingDigest());
    }

    public function testDryRunAnnouncesNothingAndMarksNothing(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'inli',
            externalId: 'E-5',
            title: 'T3 Palaiseau',
            rentCc: 1080,
        ));

        $result = $this->scout($root, ['digest', '--dry-run']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('T3 Palaiseau', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse($store->wasNotified($key), '--dry-run must not consume the backlog');
    }

    // ── harness ───────────────────────────────────────────────────────────────────────────────────

    /** Writes one listing judged DIGEST and never delivered, and returns its dedup key. */
    private function seedDigestRow(string $root, RawListing $listing): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, 'UNKNOWN', 0, ['aucun signal de régime'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'DIGEST');

        return $sighting->dedupKey;
    }

    /** Turns a row into the pre-v7 shape: a verdict with no snapshot, exactly as production holds. */
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
        $root = sys_get_temp_dir() . '/rentwatch-digest-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        $this->roots[] = $root;

        file_put_contents($root . '/config/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/sources.json', json_encode([
            'sources' => [
                'fixture_demo' => [
                    'enabled' => false,
                    'family' => 'institutional',
                    'type' => 'fixture',
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
