<?php

declare(strict_types=1);

namespace RentWatch\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Source;
use RentWatch\Adapters\SourceError;
use RentWatch\Cli\Pipeline;
use RentWatch\Config\ConfigLoader;
use RentWatch\Config\Criteria;
use RentWatch\Core\Notify\Channel;
use RentWatch\Core\Notify\ChannelError;
use RentWatch\Core\Notify\Notification;
use RentWatch\Core\Notify\NotificationKind;
use RentWatch\Core\Notify\Notifier;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\SourceStatus;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * The run loop's behaviour, driven through a source and a channel the test controls.
 *
 * WHY THIS FILE EXISTS, stated plainly: `ScoutTest` asserts on the CLI's real stdout, which is the
 * right evidence for the CLI — and a sabotage run then showed it proved almost nothing about the
 * loop underneath. Eleven separate regressions left that suite green. Among them: a fetch that
 * failed and went unrecorded; `item_count` counting matches rather than parsed items; an ignored
 * alert cooldown; a verdict that was not persisted at all; a `STALE` status made unreachable; and a
 * scraping gate someone deleted. Every one is silent by construction, and all are now pinned here.
 *
 * Categories: **run log** (hard rules 2 and 3) · **notification gating** (Q28) · **health alerting**
 * (Q29) · **cooldown** (Q29) · **verdicts** (Q24) · **scoring inputs** (S7).
 */
final class PipelineRunTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string NOW = '2026-08-07T12:00:00+02:00';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->dbPath . $suffix);
            }
            $this->dbPath = null;
        }
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-run-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }

    private function criteria(): Criteria
    {
        return ConfigLoader::loadCriteria(self::ROOT . '/config/criteria.json');
    }

    private function listing(string $id = 'a1', array $o = []): RawListing
    {
        return new RawListing(
            sourceName: $o['source'] ?? 'fake',
            externalId: $id,
            title: 'T4 Sartrouville - logement intermediaire',
            description: $o['description'] ?? '4 pieces de 88 m2, LLI, ascenseur.',
            fields: $o['fields'] ?? ['financement' => 'LLI'],
            url: 'https://example.test/' . $id,
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: $o['rentCc'] ?? 1450,
            surfaceM2: 88.0,
            rooms: 4,
            floor: 1,
            hasElevator: true,
        );
    }

    // ---------------------------------------------------------------- run log

    public function testAFailedFetchIsRECORDEDAsAFailedRun(): void
    {
        // Hard rule 2: a source that stops working must be detectable, and `SourceStatus::BROKEN` is
        // derived entirely from these rows. A failure that leaves no trace in the run log is
        // indistinguishable from a quiet market — which is the whole reason the subsystem exists.
        $store = $this->store();
        $source = new FakeSource('fake', throw: new SourceError('fake', 'the endpoint moved'));

        $result = $this->pipeline($store)->runOnce([$source], self::NOW);

        self::assertSame(1, $result->sourcesFailed);

        $rows = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT ok, item_count, error FROM source_runs WHERE source = 'fake'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(1, $rows, 'the failed run must be in the log, not merely counted in memory');
        self::assertSame(0, (int) $rows[0]['ok']);
        self::assertStringContainsString('the endpoint moved', (string) $rows[0]['error']);
        // A single source prefix, not two. The generic `\Throwable` arm below it is a safety net and
        // would re-wrap an already-wrapped SourceError into `fake: fake: …`; the specific arm exists
        // to stop that, and without this assertion the two arms are indistinguishable.
        self::assertSame(1, substr_count((string) $rows[0]['error'], 'fake:'));
    }

    public function testAnAdapterThrowingSomethingUndeclaredIsStillJustOneFailedSource(): void
    {
        // It must not abort the pass — the remaining sources have not been tried yet, and losing
        // them because one adapter threw the wrong class is a silent loss of everything after it.
        $store = $this->store();
        $bad = new FakeSource('bad', throw: new \RuntimeException('something nobody anticipated'));
        $good = new FakeSource('good', listings: [$this->listing('g1', ['source' => 'good'])]);

        $result = $this->pipeline($store)->runOnce([$bad, $good], self::NOW);

        self::assertSame(2, $result->sourcesRun);
        self::assertSame(1, $result->sourcesFailed);
        self::assertSame(1, $result->matches, 'the source after the failure was still processed');
    }

    public function testItemCountRecordsWhatWasPARSEDNotWhatMatched(): void
    {
        // Q30. Counting matches would make source health a measure of the Île-de-France rental
        // market rather than of the adapter — and a drifted selector on a source whose matches are
        // usually zero would become undetectable, which is the exact failure §8 exists for.
        $store = $this->store();
        $source = new FakeSource('fake', listings: [
            $this->listing('a1'),
            // Rejected on commune, so it is parsed but never matched.
            new RawListing(sourceName: 'fake', externalId: 'a2', commune: 'Nanterre', postcode: '92000', rooms: 4, surfaceM2: 88.0),
        ]);

        $result = $this->pipeline($store)->runOnce([$source], self::NOW);

        self::assertSame(2, $result->itemsParsed);
        self::assertSame(1, $result->matches);

        $count = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT item_count FROM source_runs WHERE source = 'fake'")
            ->fetchColumn();

        self::assertSame(2, (int) $count, 'item_count is the parsed count, not the matched count');
    }

    public function testTheRunDurationIsMeasuredAndStored(): void
    {
        // Q25: spec §8 specifies `doctor` reports timing, and for a long time it was the one column
        // of four that was aspirational.
        $store = $this->store();
        $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [$this->listing()])], self::NOW);

        $duration = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT duration_ms FROM source_runs WHERE source = 'fake'")
            ->fetchColumn();

        self::assertNotNull($duration, 'null would mean nobody measured');
        self::assertGreaterThanOrEqual(0, (int) $duration);
    }

    // ---------------------------------------------------------------- notification gating

    public function testAMatchIsNotMarkedNotifiedWhenNoChannelConfirmed(): void
    {
        // The hole Q28 closes. Marking optimistically means the run reports success, the listing is
        // recorded as sent, and the flat is gone with nothing anywhere saying so.
        $store = $this->store();
        $pipeline = $this->pipeline($store, new Notifier([new FailingChannel()]));

        $result = $pipeline->runOnce([new FakeSource('fake', listings: [$this->listing()])], self::NOW);

        self::assertSame(1, $result->matches);
        self::assertSame(1, $result->undelivered);
        self::assertTrue($result->hasProblems());

        $key = $store->dedupKey($this->listing());
        self::assertFalse($store->wasNotified($key), 'it must stay un-notified so the next run retries');
    }

    public function testAMatchIsMarkedNotifiedOnceAChannelConfirms(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce([new FakeSource('fake', listings: [$this->listing()])], self::NOW);

        self::assertTrue($store->wasNotified($store->dedupKey($this->listing())));
        self::assertCount(1, $channel->sent);
        self::assertSame(NotificationKind::MATCH, $channel->sent[0]->kind);
    }

    public function testASecondRunDoesNotReNotifyTheSameListing(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        $source = new FakeSource('fake', listings: [$this->listing()]);

        $pipeline->runOnce([$source], self::NOW);
        $pipeline->runOnce([$source], '2026-08-07T12:15:00+02:00');

        self::assertCount(1, array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::MATCH,
        ), '"a listing is new exactly once" is the store\'s most basic guarantee');
    }

    // ---------------------------------------------------------------- health alerting (Q29)

    public function testEveryAlertingStatusIsRoutedNotJustBroken(): void
    {
        // Q29. The 1c routing table named SOURCE_BROKEN alone while six statuses alert, so
        // NEVER_PRODUCED — added precisely because it hid behind OK — and STALE, which catches the
        // schedule itself having stopped, would have been derived, stored and never sent.
        foreach ([SourceStatus::NEVER_PRODUCED, SourceStatus::STALE, SourceStatus::WARN_FLAKY] as $status) {
            $store = $this->store();
            $channel = new RecordingChannel();
            $source = new FakeSource('fake', listings: [], health: new SourceHealth(
                sourceName: 'fake',
                status: $status,
                detail: 'détail',
            ));

            $this->pipeline($store, new Notifier([$channel]))->runOnce([$source], self::NOW);

            $alerts = array_filter(
                $channel->sent,
                static fn (Notification $n): bool => $n->kind === NotificationKind::SOURCE_HEALTH,
            );

            self::assertCount(1, $alerts, $status->value . ' produced no alert');
            $this->tearDown();
        }
    }

    public function testAnOkSourceProducesNoHealthAlert(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $source = new FakeSource('fake', listings: [$this->listing()], health: new SourceHealth('fake', SourceStatus::OK));

        $this->pipeline($store, new Notifier([$channel]))->runOnce([$source], self::NOW);

        self::assertSame([], array_values(array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::SOURCE_HEALTH,
        )));
    }

    // ---------------------------------------------------------------- cooldown (Q29)

    public function testTheSameAlertIsNotRepeatedWithinTheCooldown(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $source = new FakeSource('fake', listings: [], health: new SourceHealth('fake', SourceStatus::BROKEN, 'cassée'));
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce([$source], self::NOW);
        $pipeline->runOnce([$source], '2026-08-07T12:15:00+02:00');
        $pipeline->runOnce([$source], '2026-08-07T13:00:00+02:00');

        self::assertCount(1, $this->healthAlerts($channel), 'a source broken for a week must not push once a run');
    }

    public function testTheCooldownExpires(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $source = new FakeSource('fake', listings: [], health: new SourceHealth('fake', SourceStatus::BROKEN, 'cassée'));
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce([$source], self::NOW);
        $pipeline->runOnce([$source], '2026-08-08T13:00:00+02:00');

        self::assertCount(2, $this->healthAlerts($channel), 'after 25 hours the alert is due again');
    }

    public function testAnESCALATIONIsNotSwallowedByTheEarlierQuieterAlert(): void
    {
        // Why the cooldown keys on (source, status) rather than on source alone. A WARN_DROP that
        // becomes BROKEN is a different fact, and keying on the source would silence the louder
        // alert for a whole day on the strength of the quieter one.
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce(
            [new FakeSource('fake', listings: [], health: new SourceHealth('fake', SourceStatus::WARN_DROP, 'baisse'))],
            self::NOW,
        );
        $pipeline->runOnce(
            [new FakeSource('fake', listings: [], health: new SourceHealth('fake', SourceStatus::BROKEN, 'cassée'))],
            '2026-08-07T12:15:00+02:00',
        );

        self::assertCount(2, $this->healthAlerts($channel));
    }

    public function testARecoveredSourceSendsExactlyOneRecoveryNotice(): void
    {
        // Without it, a developer who fixes a field map sees nothing and has no confirmation the fix
        // took — and the next, different breakage that day would also be silent, because the old
        // alert's cooldown would still be running.
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce(
            [new FakeSource('fake', listings: [], health: new SourceHealth('fake', SourceStatus::BROKEN, 'cassée'))],
            self::NOW,
        );
        $ok = new FakeSource('fake', listings: [$this->listing()], health: new SourceHealth('fake', SourceStatus::OK));
        $pipeline->runOnce([$ok], '2026-08-07T12:15:00+02:00');
        $pipeline->runOnce([$ok], '2026-08-07T12:30:00+02:00');

        $recoveries = array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::SOURCE_RECOVERED,
        );

        self::assertCount(1, $recoveries, 'exactly one — the third run must not re-announce it');
    }

    public function testAFailedAlertSendDoesNotStartTheCooldown(): void
    {
        // A cooldown that began on a failed send would silence the alert for a day on the strength
        // of a delivery that never happened.
        $store = $this->store();
        $source = new FakeSource('fake', listings: [], health: new SourceHealth('fake', SourceStatus::BROKEN, 'cassée'));

        $this->pipeline($store, new Notifier([new FailingChannel()]))->runOnce([$source], self::NOW);

        self::assertTrue(
            $store->shouldAlert('fake', SourceStatus::BROKEN->value, '2026-08-07T12:15:00+02:00', 24),
            'the alert must still be due, because it was never actually delivered',
        );
    }

    // ---------------------------------------------------------------- verdicts (Q24)

    public function testTheTenureVerdictIsPersistedWithTheListing(): void
    {
        // Q24. A listing stored under an old classifier cannot be re-evaluated or explained without
        // re-fetching it, and by then the source may have removed the ad.
        $store = $this->store();
        $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [$this->listing()])], self::NOW);

        $row = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query('SELECT tenure, confidence_bp, signals_json FROM listings')
            ->fetch(\PDO::FETCH_ASSOC);

        self::assertSame(Tenure::LLI->value, $row['tenure']);
        self::assertGreaterThan(0, (int) $row['confidence_bp']);
        self::assertNotEmpty(json_decode((string) $row['signals_json'], true), 'the reasons are stored so the verdict can be explained later');
    }

    public function testAStoredVerdictOfUnknownIsSelectableForReclassification(): void
    {
        $store = $this->store();
        $noSignal = new RawListing(
            sourceName: 'fake',
            externalId: 'u1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [$noSignal], mixedTenure: true)], self::NOW);

        $stale = $store->staleVerdicts(['UNKNOWN']);
        self::assertCount(1, $stale);
        self::assertSame('UNKNOWN', $stale[0]['tenure']);
    }

    // ---------------------------------------------------------------- scoring inputs

    public function testFreshnessIsMeasuredFromFIRSTSeenNotFromEverySighting(): void
    {
        // S7. Passing "new" unconditionally would give every listing the freshness bonus forever,
        // flattening the one component that separates a flat published this hour from one that has
        // sat for a week — and it would look like the scoring was working.
        $store = $this->store();
        $channel = new RecordingChannel();
        $source = new FakeSource('fake', listings: [$this->listing()]);
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce([$source], self::NOW);
        $first = $channel->sent[0]->score;

        // Same listing, seen again a day later, and the seen-set is cleared of the notified flag so
        // it is re-announced — the only way to observe the second score.
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec('UPDATE listings SET notified_at = NULL');
        $channel->sent = [];
        $pipeline->runOnce([$source], '2026-08-08T12:00:00+02:00');

        $later = $channel->sent[0]->score;

        self::assertNotNull($first);
        self::assertNotNull($later);
        self::assertGreaterThan(
            (int) $later,
            (int) $first,
            'a day-old listing must score BELOW the same listing when it was new',
        );
    }

    // ---------------------------------------------------------------- schema

    public function testTheSchemaVersionIsThree(): void
    {
        // A bare constant assertion, and it earns its place: lowering `SCHEMA_VERSION` makes
        // `migrate()` return early on an EXISTING database, so an older one opens cleanly and then
        // throws `no such column` on the first write. A fresh database hides it entirely, because
        // `CREATE TABLE IF NOT EXISTS` always writes the current DDL.
        self::assertSame(3, Store::SCHEMA_VERSION);
        self::assertSame(3, $this->store()->schemaVersion());
    }

    public function testAVersionOneDatabaseIsUpgradedToCarryTheV3Columns(): void
    {
        // The path a fresh database cannot exercise. The seen-set cannot be rebuilt from anywhere,
        // so an upgrade that silently skipped its columns would be discovered as a runtime error on
        // the one dataset that matters.
        $store = $this->store();
        $pdo = new \PDO('sqlite:' . (string) $this->dbPath);
        $pdo->exec('DROP TABLE listings');
        $pdo->exec('CREATE TABLE listings (dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL,
            external_id TEXT NOT NULL, url TEXT, title TEXT NOT NULL, rent_cc INTEGER,
            first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, notified_at TEXT)');
        $pdo->exec("UPDATE schema_meta SET value = '1' WHERE key = 'schema_version'");
        unset($pdo, $store);

        $reopened = Store::open((string) $this->dbPath);
        self::assertSame(3, $reopened->schemaVersion());

        $columns = array_column(
            (new \PDO('sqlite:' . (string) $this->dbPath))->query('PRAGMA table_info(listings)')->fetchAll(\PDO::FETCH_ASSOC),
            'name',
        );

        foreach (['seen_epoch', 'tenure', 'confidence_bp', 'signals_json'] as $column) {
            self::assertContains($column, $columns, "the v1 -> v3 upgrade did not add `{$column}`");
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<Notification> */
    private function healthAlerts(RecordingChannel $channel): array
    {
        return array_values(array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::SOURCE_HEALTH,
        ));
    }

    private function pipeline(Store $store, ?Notifier $notifier = null): Pipeline
    {
        return new Pipeline(
            $this->criteria(),
            $store,
            $notifier ?? new Notifier([new RecordingChannel()]),
        );
    }
}

/** A source the test drives: fixed listings, a fixed failure, or a fixed health verdict. */
final readonly class FakeSource implements Source
{
    /** @param list<RawListing> $listings */
    public function __construct(
        private string $name,
        private array $listings = [],
        private ?\Throwable $throw = null,
        private ?SourceHealth $health = null,
        private bool $mixedTenure = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return 'institutional';
    }

    /** No network in this fake, so no host — and therefore no Q37 pacing. */
    public function host(): ?string
    {
        return null;
    }

    public function defaultTenure(): ?Tenure
    {
        return $this->mixedTenure ? null : Tenure::LLI;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile($this->name, 'institutional', $this->defaultTenure(), $this->mixedTenure);
    }

    public function fetch(): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->listings;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->health ?? new SourceHealth($this->name, SourceStatus::OK);
    }
}

/** A channel that records what it was asked to deliver. */
final class RecordingChannel implements Channel
{
    /** @var list<Notification> */
    public array $sent = [];

    public function name(): string
    {
        return 'recording';
    }

    public function check(): ?string
    {
        return null;
    }

    public function send(Notification $notification): void
    {
        $this->sent[] = $notification;
    }
}

/** A channel that always fails, for the delivery-gating tests. */
final class FailingChannel implements Channel
{
    public function name(): string
    {
        return 'failing';
    }

    public function check(): ?string
    {
        return null;
    }

    public function send(Notification $notification): void
    {
        throw new ChannelError($this->name(), 'the network went away');
    }
}
