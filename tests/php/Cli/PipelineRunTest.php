<?php

declare(strict_types=1);

namespace Scout\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Source;
use Scout\Adapters\SourceError;
use Scout\Cli\Pipeline;
use Scout\Config\ConfigLoader;
use Scout\Config\Criteria;
use Scout\Core\Notify\Channel;
use Scout\Core\Notify\ChannelError;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\RawListing;
use Scout\Core\SourceHealth;
use Scout\Core\SourceProfile;
use Scout\Core\SourceStatus;
use Scout\Core\Tenure;
use Scout\Enrich\CommutePlanner;
use Scout\Store\Store;

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
        return ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/criteria/pipeline.json');
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
            // HONOURS AN OVERRIDE, and this line is why. It was hardcoded, so two tests written to
            // pin the malformed-commune guard passed a bad byte that never reached a `RawListing`
            // — they asserted a guarantee about input they did not supply, and the guard could be
            // deleted with the whole suite staying green. Found by a review panel on 2026-08-24.
            //
            // The sibling helper in NotifyTest carries the same warning for the same reason, and
            // this file had ALREADY been caught by it once this session, on `title`. A helper that
            // quietly ignores what a test asked for makes every test using it prove something else.
            commune: array_key_exists('commune', $o) ? $o['commune'] : 'Sartrouville',
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

    public function testAListingWhoseTextIsNotUtf8DoesNotAbortThePass(): void
    {
        // cp1252 under a UTF-8 declaration is an anticipated real input here — `Text` has a test for
        // it and the classifier has a branch that turns it into UNKNOWN naming the encoding. The
        // STORE then threw: `ListingSnapshot::encode()` uses JSON_THROW_ON_ERROR, and the throw came
        // from the per-listing loop, which sits OUTSIDE the per-source try/catch. So one badly
        // encoded listing aborted the whole pass — every later listing unclassified and unnotified —
        // and left the offending row in the seen-set with `tenure = NULL`, a value whose documented
        // meaning is "stored before schema v3". `recordRun` had already committed `ok = 1`, so
        // health stayed green throughout: hard rule 2's silent shape.
        $store = $this->store();
        $result = $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [
            $this->listing('b1', ['description' => "conventionn\xE9 T4", 'source' => 'fake']),
            $this->listing('g1', ['source' => 'fake']),
        ])], self::NOW);

        self::assertSame(0, $result->sourcesFailed, 'a listing nobody can decode is not a broken source');
        self::assertSame(1, $result->matches, 'the listing AFTER the bad one was still judged and notified');
        self::assertSame(1, $result->unencodable, 'and the pass says a snapshot could not be taken');
    }

    public function testAnUnencodableListingIsStoredWithAVerdictAndNoSnapshot(): void
    {
        // The state it is left in has to be honest in both directions: a real verdict, so it is not
        // mistaken for a row stored before schema v3 and reported for ever as one — and no snapshot,
        // so `scout reclassify` skips it rather than re-judging text nothing can read.
        $store = $this->store();
        // `description`, not `title`: the helper hardcodes the title and silently ignores an
        // override, which is the "helper that quietly ignores what a test asked for" trap this
        // suite's sibling documents. A first draft used `title` and asserted against a listing that
        // was perfectly well-formed.
        $listing = $this->listing('b1', ['description' => "conventionn\xE9 T4", 'source' => 'fake']);

        $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [$listing])], self::NOW);

        $key = $store->dedupKey($listing);
        self::assertNull($store->evidence($key), 'no snapshot could be captured');
        self::assertNotNull($store->snapshot($key), 'but the listing IS in the seen-set');

        $stale = $store->staleVerdicts();
        self::assertCount(1, $stale);
        self::assertSame(
            'UNKNOWN',
            $stale[0]['tenure'],
            'a REAL verdict, not NULL — NULL means "stored before schema v3" and reclassify would report it as one for ever',
        );
    }

    public function testAMalformedCOMMUNEDoesNotAbortThePassEither(): void
    {
        // THE SURFACE THE FIRST FIX MISSED. `Criteria::excludedBy()` was hardened and
        // `Criteria::communeKey()` was not — and the latter is reached twice per pass, from
        // `rankOf()` inside `score()` and from `Dedup::duplicateReason()` inside `cluster()`,
        // neither of which sits in the per-source try/catch. `ListingMapper` takes `commune`
        // straight from `Payload::string()`, which validates neither UTF-8 nor HTML entities, and
        // accented commune names are ubiquitous in Île-de-France.
        //
        // Both tests below put the bad bytes in `commune` for that reason: the two that existed
        // put them in `description`, which is why a fix that covered one surface looked complete.
        $store = $this->store();
        $result = $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [
            $this->listing('b1', ['commune' => "Cr\xE9teil", 'source' => 'fake']),
            $this->listing('g1', ['source' => 'fake']),
        ])], self::NOW);

        self::assertSame(0, $result->sourcesFailed);
        // BOTH, and that is the correct answer rather than a lax one. The fixture criteria are
        // region mode, so the POSTCODE carries the filter and the commune only ranks — an
        // unfoldable commune therefore costs a score preference, not a match. The safe direction on
        // this path is "unranked", and the assertion that matters is that the pass finished at all.
        self::assertSame(2, $result->matches, 'the listing after the unfoldable commune was still judged and notified');
    }

    public function testACommuneCarryingAnUndecodedEntityDoesNotAbortThePass(): void
    {
        // The commoner trigger, and the one no cp1252 test would have caught: `Text` refuses any
        // undecoded HTML entity, which a scraped payload produces far more often than a bad byte.
        // `Dedup::cluster()` folds the commune BEFORE anything is stored, so this shape aborted the
        // pass with zero rows written and both sources still reported healthy.
        $store = $this->store();
        $result = $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [
            $this->listing('b1', ['commune' => "L&#039;Hay-les-Roses", 'source' => 'fake']),
            $this->listing('g1', ['source' => 'fake']),
        ])], self::NOW);

        self::assertSame(0, $result->sourcesFailed);
        self::assertSame(2, $result->matches);
        self::assertCount(2, $store->staleVerdicts(['UNKNOWN', 'LLI']), 'and both listings reached the store');
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

    /**
     * The pipeline must WRITE the feed date it just read from the source.
     *
     * **Found by `tests/sabotage-check.sh`, not by review or by any other test.** Replacing the
     * `$feedNewestAt = …` line with `null` left the whole suite green: `FeedFreshnessTest` proves
     * the source hands a date to the store when called directly, and `StoreFeedSilenceTest` proves
     * the store judges such rows correctly — but nothing proved the pipeline is the thing that
     * carries one to the other. That is one link of a five-link chain, and the certification panel
     * had already shown the other four were cuttable the same way.
     *
     * Column-level, deliberately: asserting the verdict would pass on a row whose date came from
     * anywhere, and it is the WRITE that was deletable.
     */
    public function testThePipelineRecordsTheFeedDateItReadFromTheSource(): void
    {
        $store = $this->store();
        $this->pipeline($store)->runOnce(
            [new FreshFakeSource('fresh', '2026-08-26T05:33:06Z', [$this->listing()])],
            self::NOW,
        );

        $recorded = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT feed_newest_at FROM source_runs WHERE source = 'fresh'")
            ->fetchColumn();

        self::assertSame('2026-08-26T05:33:06Z', $recorded);
    }

    /** A source that reports no freshness writes NULL — unknown is not old (hard rule 9). */
    public function testASourceWithoutFreshnessRecordsNoFeedDate(): void
    {
        $store = $this->store();
        $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [$this->listing()])], self::NOW);

        $recorded = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT feed_newest_at FROM source_runs WHERE source = 'fake'")
            ->fetchColumn();

        self::assertNull($recorded);
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

    // ---------------------------------------------------------------- the observation time (2026-08-29)

    /**
     * THE PHANTOM-DROP LOOP, replayed exactly. Bien'ici sent one Ozoir flat on 26 August at 1122 €
     * and again on 27 August at 1146 €. Both messages stay inside the IMAP window, so every pass
     * re-reads both — newest first, then the older one — and with both stamped at the PASS time the
     * store recorded "1146, then 1122: a drop" every fifteen minutes: 429 history rows, 128 emails.
     *
     * With each listing carrying its message's date, the older card is a SUPERSEDED observation
     * (the store's own word for it), whatever order the adapter yields it in.
     */
    public function testAReSentOlderCardIsNotARentDropHoweverManyTimesItIsReRead(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        $older = $this->dated('ozoir', 1122, '2026-08-26T18:31:09Z');
        $newer = $this->dated('ozoir', 1146, '2026-08-27T13:34:25Z');

        $pipeline->runOnce([new FakeSource('bienici', [$older])], '2026-08-26T19:00:00Z');
        foreach (['2026-08-27T14:00:00Z', '2026-08-27T14:15:00Z', '2026-08-27T14:30:00Z'] as $now) {
            $pipeline->runOnce([new FakeSource('bienici', [$newer, $older])], $now);
        }
        // And the other order — a mailbox that yields oldest first must reach the same state.
        $pipeline->runOnce([new FakeSource('bienici', [$older, $newer])], '2026-08-27T14:45:00Z');

        self::assertCount(0, $this->ofKind($channel, NotificationKind::RENT_DROP), '1122 was the rent BEFORE 1146, not after it');
        self::assertSame([1122, 1146], $store->priceHistory($store->dedupKey($newer)), 'changes only: one rise, no oscillation');
    }

    /**
     * THE HOP THE FIRST FIX MISSED. Commute enrichment is ON only in production, and `enrich()`
     * rebuilt the listing field by field — dropping `observedAt`, so the deployed fix fired the
     * same phantom drop on its first live pass while every test (commute OFF) stayed green. This
     * replays the loop WITH a planner, on the path production takes.
     */
    public function testTheLoopStaysClosedWhenCommuteEnrichmentIsOn(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]), new FixedPlanner(42));
        $older = $this->dated('bussy', 1122, '2026-08-26T18:31:09Z');
        $newer = $this->dated('bussy', 1146, '2026-08-27T13:34:25Z');

        $pipeline->runOnce([new FakeSource('bienici', [$older])], '2026-08-26T19:00:00Z');
        $pipeline->runOnce([new FakeSource('bienici', [$newer, $older])], '2026-08-27T14:00:00Z');
        $pipeline->runOnce([new FakeSource('bienici', [$newer, $older])], '2026-08-27T14:15:00Z');

        self::assertCount(0, $this->ofKind($channel, NotificationKind::RENT_DROP));
        self::assertSame([1122, 1146], $store->priceHistory($store->dedupKey($newer)));
    }

    /**
     * And the structural guard: enrichment changes `commuteMinutes` and NOTHING else, asserted over
     * every constructor parameter by reflection — so the next property added to `RawListing`
     * cannot be dropped on this hop either. Every parameter is given a non-default value first;
     * a guard that compares defaults to defaults asserts nothing.
     */
    public function testEnrichmentPreservesEveryOtherPropertyByReflection(): void
    {
        $args = [];
        foreach ((new \ReflectionClass(RawListing::class))->getConstructor()?->getParameters() ?? [] as $p) {
            $type = (string) $p->getType();
            $args[$p->getName()] = match (true) {
                $p->getName() === 'commuteMinutes' => null,
                str_contains($type, 'array') => ['k' => 'v-' . $p->getName()],
                str_contains($type, 'string') => 'v-' . $p->getName(),
                str_contains($type, 'float') => 7.5,
                str_contains($type, 'int') => 7,
                str_contains($type, 'bool') => true,
                default => self::fail('unhandled constructor parameter type ' . $type . ' for ' . $p->getName()),
            };
        }
        $listing = new RawListing(...$args);
        $pipeline = $this->pipeline($this->store(), null, new FixedPlanner(42));

        $enriched = (new \ReflectionMethod(Pipeline::class, 'enrich'))->invoke($pipeline, $listing);

        self::assertInstanceOf(RawListing::class, $enriched);
        self::assertSame(42, $enriched->commuteMinutes);
        foreach (array_keys($args) as $name) {
            if ($name === 'commuteMinutes') {
                continue;
            }
            self::assertSame($listing->$name, $enriched->$name, 'enrichment dropped RawListing::$' . $name);
        }
    }

    /** The counterweight: a genuinely NEWER lower rent is still the event this project exists for. */
    public function testAGenuinelyNewerLowerRentIsStillADrop(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce([new FakeSource('bienici', [$this->dated('ozoir', 1300, '2026-08-26T18:31:09Z')])], '2026-08-26T19:00:00Z');
        $pipeline->runOnce([new FakeSource('bienici', [$this->dated('ozoir', 1100, '2026-08-27T13:34:25Z')])], '2026-08-27T14:00:00Z');

        self::assertCount(1, $this->ofKind($channel, NotificationKind::RENT_DROP));
    }

    /** A listing with no observation time is observed NOW — the polling adapters' case, unchanged. */
    public function testAnUndatedListingIsObservedAtThePassTime(): void
    {
        $store = $this->store();
        $pipeline = $this->pipeline($store);

        $pipeline->runOnce([new FakeSource('fake', [$this->listing()])], '2026-08-07T12:00:00+02:00');

        $stored = $store->priceHistory($store->dedupKey($this->listing()));
        self::assertSame([1450], $stored);
    }

    // ---------------------------------------------------------------- two tracks, ONE push (2026-08-29)

    /**
     * Developer ruling, 2026-08-29: a flat on both tracks is two findings and ONE push. Identities
     * and histories stay per track (`Dedup::duplicateReason()` still refuses across families); the
     * push names both routes and carries both links, and the twin is marked notified rather than
     * pushed. Measured before the ruling: 43 flats pushed twice.
     */
    public function testAFlatOnBothTracksIsPushedOnceNamingBothRoutes(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        [$direct, $agency] = $this->twins();

        $pipeline->runOnce([
            new FakeSource('cdc_habitat', [$direct]),
            new FakeSource('seloger', [$agency], family: 'private'),
        ], '2026-08-07T12:00:00+02:00');

        $matches = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $matches, 'one flat, one push');
        self::assertSame('cdc_habitat', $matches[0]->sourceName, 'the direct route is the one pushed when both are in hand');
        self::assertStringContainsString('seloger', implode("\n", $matches[0]->reasons), 'the push names the other route');
        self::assertStringContainsString('https://seloger.test/s1', implode("\n", $matches[0]->reasons), 'and carries its link');
        self::assertTrue($store->wasNotifiedAs($store->dedupKey($agency), 'MATCH'), 'the twin is marked, not pushed');
    }

    /** Source ORDER must not decide which route is pushed — the direct route is preferred whichever came first in the pass. */
    public function testTheDirectRouteIsPreferredWhateverTheSourceOrder(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        [$direct, $agency] = $this->twins();

        $pipeline->runOnce([
            new FakeSource('seloger', [$agency], family: 'private'),
            new FakeSource('cdc_habitat', [$direct]),
        ], '2026-08-07T12:00:00+02:00');

        $matches = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $matches);
        self::assertSame('cdc_habitat', $matches[0]->sourceName);
    }

    /**
     * The one accepted second push: the direct route arriving AFTER the agency copy was already
     * pushed. Hiding it would hide the better route, which is what the 2026-08-06 ruling forbade;
     * it is pushed once, says it is the direct route of a flat already seen, and never again.
     */
    public function testTheDirectRouteArrivingLaterIsStillPushedOnceAsTheDirectRoute(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        [$direct, $agency] = $this->twins();
        $agencyOnly = [new FakeSource('seloger', [$agency], family: 'private')];
        $both = [new FakeSource('seloger', [$agency], family: 'private'), new FakeSource('cdc_habitat', [$direct])];

        $pipeline->runOnce($agencyOnly, '2026-08-07T12:00:00+02:00');
        $pipeline->runOnce($both, '2026-08-07T12:15:00+02:00');
        $pipeline->runOnce($both, '2026-08-07T12:30:00+02:00');

        $matches = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(2, $matches, 'agency first, then the direct route once — and never a third');
        self::assertSame(['seloger', 'cdc_habitat'], array_map(static fn (Notification $n): string => $n->sourceName, $matches));
        self::assertStringContainsString('voie directe', implode("\n", $matches[1]->reasons));
        self::assertStringContainsString('seloger', implode("\n", $matches[1]->reasons), 'and it says which push it follows');
    }

    /**
     * The other arrival order — and the branch the ledger found UNREACHED (2026-08-29): the direct
     * route was pushed alone, and the agency copy appears on a LATER pass. It must be marked and
     * not pushed; the same-pass test never reaches this branch because the twin is marked before
     * its turn comes. Both counters say so: no second push, one copy withheld.
     */
    public function testAnAgencyCopyArrivingAfterTheDirectRouteIsMarkedNotPushed(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        [$direct, $agency] = $this->twins();
        $both = [new FakeSource('cdc_habitat', [$direct]), new FakeSource('seloger', [$agency], family: 'private')];

        $pipeline->runOnce([new FakeSource('cdc_habitat', [$direct])], '2026-08-07T12:00:00+02:00');
        $second = $pipeline->runOnce($both, '2026-08-07T12:15:00+02:00');
        $pipeline->runOnce($both, '2026-08-07T12:30:00+02:00');

        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH), 'the agency copy of an announced flat is never pushed');
        self::assertTrue($store->wasNotifiedAs($store->dedupKey($agency), 'MATCH'), 'marked, so it cannot be pushed later either');
        self::assertSame(1, $second->twinsSuppressed, 'and the pass says a copy was withheld');
    }

    /** Positive evidence only: two flats that merely share a track pair are two pushes. */
    public function testTwoDifferentFlatsOnTwoTracksAreTwoPushes(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));
        [$direct, $agency] = $this->twins();
        $elsewhere = new RawListing(
            sourceName: 'seloger', externalId: 's9', title: 'Appartement T4', description: '4 pieces de 88 m2.',
            url: 'https://seloger.test/s9', commune: 'Houilles', postcode: '78800',
            rentCc: $agency->rentCc, surfaceM2: $agency->surfaceM2, rooms: $agency->rooms,
        );

        $pipeline->runOnce([
            new FakeSource('cdc_habitat', [$direct]),
            new FakeSource('seloger', [$elsewhere], family: 'private'),
        ], '2026-08-07T12:00:00+02:00');

        self::assertCount(2, $this->ofKind($channel, NotificationKind::MATCH));
    }

    /** @return array{RawListing, RawListing} the same Sartrouville T4 on the direct and the agency route */
    private function twins(): array
    {
        $direct = new RawListing(
            sourceName: 'cdc_habitat', externalId: 'c1', title: '4 pièces - 2ème étage - 88m²',
            description: '4 pieces de 88 m2, logement intermediaire.', fields: ['financement' => 'LLI'],
            url: 'https://cdc.test/c1', commune: 'Sartrouville', postcode: '78500',
            rentCc: 1450, surfaceM2: 88.0, rooms: 4,
        );
        $agency = new RawListing(
            sourceName: 'seloger', externalId: 's1', title: 'Appartement T4',
            description: 'Beau 4 pieces de 88 m2, proche gare.', fields: ['financement' => 'LLI'],
            url: 'https://seloger.test/s1', commune: 'Sartrouville', postcode: '78500',
            rentCc: 1450, surfaceM2: 88.0, rooms: 4,
        );

        return [$direct, $agency];
    }

    private function dated(string $id, int $rentCc, string $observedAt): RawListing
    {
        return new RawListing(
            sourceName: 'bienici', externalId: $id, title: 'Appartement 3 pièces 66 m²',
            description: '3 pieces de 66 m2.', fields: ['financement' => 'LLI'],
            url: 'https://bienici.test/' . $id, commune: 'Sartrouville', postcode: '78500',
            rentCc: $rentCc, surfaceM2: 88.0, rooms: 4, observedAt: $observedAt,
        );
    }

    /** @return list<Notification> */
    private function ofKind(RecordingChannel $channel, NotificationKind $kind): array
    {
        return array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === $kind));
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

    /**
     * Schema v7. The evidence stored is the listing the classifier actually consumed.
     *
     * Without this the pipeline could store a verdict with no snapshot beside it and nothing would
     * notice until `scout reclassify` skipped every row it was written for.
     */
    public function testTheEvidenceTheClassifierConsumedIsPersisted(): void
    {
        $store = $this->store();
        $this->pipeline($store)->runOnce([new FakeSource('fake', listings: [$this->listing()])], self::NOW);

        $stored = $store->evidence($store->dedupKey($this->listing()));

        self::assertNotNull($stored, 'no snapshot was written beside the verdict');
        self::assertEquals($this->listing(), $stored);
    }

    /**
     * Schema v7. The judged outcome is recorded for ALL THREE verdicts, not only the digested one.
     *
     * The placement is the guarantee: `recordOutcome()` runs BEFORE the REJECT and DIGEST branches,
     * both of which `continue`. Written inside the digest branch instead, a listing promoted from
     * DIGEST to MATCH on a later pass would keep its stale `DIGEST` for ever, and `scout digest`
     * would go on announcing as doubtful something already notified as a match.
     */
    public function testEveryJudgedOutcomeIsRecordedWhicheverWayItWent(): void
    {
        $store = $this->store();

        // A clean LLI match, an undetermined listing on a mixed source, and one the criteria reject
        // outright — one of each, in a single pass.
        $match = $this->listing('m1');
        $doubtful = new RawListing(
            sourceName: 'fake',
            externalId: 'd1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $rejected = $this->listing('r1', ['description' => '4 pieces de 88 m2, PLAI, ascenseur.', 'fields' => ['financement' => 'PLAI']]);

        $this->pipeline($store)->runOnce(
            [new FakeSource('fake', listings: [$match, $doubtful, $rejected], mixedTenure: true)],
            self::NOW,
        );

        self::assertSame('MATCH', $store->outcome($store->dedupKey($match)));
        self::assertSame('DIGEST', $store->outcome($store->dedupKey($doubtful)));
        self::assertSame(
            'REJECT',
            $store->outcome($store->dedupKey($rejected)),
            'a rejected listing must record its outcome too — recordOutcome() runs before the branches',
        );
    }

    // ---------------------------------------------------------------- promotion

    /**
     * A listing that was DIGESTED and is later judged a MATCH must be announced as one.
     *
     * **`notified_at` was answering a question nobody asked it.** Being carried in a delivered
     * digest set it, and the match branch's gate reads it as "already told about this listing" — so
     * a listing promoted DIGEST -> MATCH on a later pass hit `continue` and no match notification
     * was ever sent. The pass summary counted it (`matches=1`), which is the worst part: the
     * operator is told a match was produced and nothing arrives.
     *
     * Nothing could reach it afterwards either. The same pass overwrites `tenure` (leaving
     * `staleVerdicts()`, so `scout reclassify` cannot see it) and `outcome` (leaving
     * `pendingDigest()`, so `scout digest` cannot either). There is no third selector.
     *
     * This is production, not a constructed case: `cityloger` is `mixed_tenure` with no
     * `default_tenure` and a search card that carries no tenure at all, so every un-hydrated
     * listing digests — and its `detail_map` exists precisely to resolve them on a later pass.
     * Found by a review panel on 2026-08-24, against a docblock this session had written claiming
     * `scout reclassify` covered exactly this population.
     */
    public function testAListingPromotedFromDigestToMatchIsAnnouncedRatherThanSwallowed(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        // Pass 1: a mixed source, no tenure signal anywhere -> UNKNOWN -> DIGEST, and delivered.
        $doubtful = new RawListing(
            sourceName: 'fake',
            externalId: 'p1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $pipeline->runOnce([new FakeSource('fake', listings: [$doubtful], mixedTenure: true)], self::NOW);

        self::assertSame('DIGEST', $store->outcome($store->dedupKey($doubtful)));
        self::assertSame(
            [NotificationKind::DIGEST],
            array_map(static fn (Notification $n): NotificationKind => $n->kind, $channel->sent),
            'pass 1 must announce the doubt and nothing else',
        );

        // Pass 2: the SAME listing, its detail page now read, stating the tenure outright.
        $resolved = new RawListing(
            sourceName: 'fake',
            externalId: 'p1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2. Logement intermediaire (LLI).',
            fields: ['financement' => 'LLI'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $channel->sent = [];
        $result = $pipeline->runOnce([new FakeSource('fake', listings: [$resolved], mixedTenure: true)], '2026-08-08T12:00:00+02:00');

        self::assertSame(1, $result->matches, 'the pass must judge it a match');
        self::assertSame(
            [NotificationKind::MATCH],
            array_map(static fn (Notification $n): NotificationKind => $n->kind, $channel->sent),
            'a promotion the pass COUNTED as a match must reach the channel — being told a flat is '
            . 'doubtful is not being told it is a match, and no other command can reach the row again',
        );
    }

    // ---------------------------------------------------------------- cross-portal clusters

    /**
     * An excluded tenure on ANY member of a cluster must veto the whole cluster (§1).
     *
     * **Only the survivor was judged.** The pipeline classifies every member and stores each
     * verdict, then judges `$observed[...]` for the survivor alone — nothing read a sibling's
     * tenure. So the same flat, published on a pure-LLI portal and on a mixed one that states
     * `PLS` outright, was a MATCH or a REJECT depending purely on which source was polled first
     * — and `Core\Pacer` shuffles source order on every pass, so it was a fresh coin-flip each
     * time rather than a stable wrong answer.
     *
     * The store then held `PLS` at high confidence under the SAME `group_key` as the row it had
     * just pushed as a match, and the notification's own `reasons[]` named the excluded sibling.
     *
     * It also inverts the documented dedup trade-off: over-merging is supposed to cost a hidden
     * second flat, and here it LAUNDERED an excluded tenure into a notification. Found by a review
     * panel on 2026-08-24.
     *
     * An UNKNOWN sibling deliberately does NOT veto — absence of a signal is not evidence, and
     * most cards carry no tenure at all, so vetoing on doubt would digest nearly every clustered
     * match. Only the excluded set vetoes. The counterweight is the next test.
     */
    public function testAnExcludedTenureOnAnyClusterMemberVetoesTheMatchWhicheverSourceRanFirst(): void
    {
        // The same flat, twice: a card with no tenure text on a pure-LLI portal, and one on a mixed
        // portal that says PLS outright. Identical postcode, rent, surface and rooms, so they cluster.
        $bare = new RawListing(
            sourceName: 'inli',
            externalId: 'i-1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2, ascenseur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $excluded = new RawListing(
            sourceName: 'cdc',
            externalId: 'c-1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2. Commission d attribution, demande de logement social.',
            fields: ['financement' => 'PLS'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        foreach ([['inli-first', $bare, $excluded], ['cdc-first', $excluded, $bare]] as [$order, $first, $second]) {
            $store = $this->store();
            $channel = new RecordingChannel();

            $this->pipeline($store, new Notifier([$channel]))->runOnce(
                [
                    new FakeSource($first->sourceName, listings: [$first], mixedTenure: $first->sourceName === 'cdc'),
                    new FakeSource($second->sourceName, listings: [$second], mixedTenure: $second->sourceName === 'cdc'),
                ],
                self::NOW,
            );

            self::assertNotContains(
                NotificationKind::MATCH,
                array_map(static fn (Notification $n): NotificationKind => $n->kind, $channel->sent),
                $order . ': a cluster holding an explicit PLS member must never be notified as a match — '
                . 'whichever member happened to survive dedup',
            );
        }
    }

    /**
     * The counterweight: a merely UNDETERMINED sibling must not veto a match.
     *
     * Without this, the fix above is one line from digesting every clustered match in the tree —
     * most cards state no tenure at all, so the bare member of nearly every cross-portal pair
     * classifies UNKNOWN. Over-rejection is invisible, because nothing arrives.
     */
    public function testAnUndeterminedSiblingDoesNotVetoAMatch(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();

        $bare = new RawListing(
            sourceName: 'mixed',
            externalId: 'm-1',
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2, ascenseur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $this->pipeline($store, new Notifier([$channel]))->runOnce(
            [
                new FakeSource('inli', listings: [$this->listing('a1', ['source' => 'inli'])]),
                new FakeSource('mixed', listings: [$bare], mixedTenure: true),
            ],
            self::NOW,
        );

        self::assertContains(
            NotificationKind::MATCH,
            array_map(static fn (Notification $n): NotificationKind => $n->kind, $channel->sent),
            'a sibling that merely states nothing is not evidence against the match',
        );
    }

    /**
     * The pipeline's own digest emission is CAPPED, like the manual drain.
     *
     * `scout digest` was bounded first and this was not — and this is the path that runs
     * unattended, so the reasoning applies here with more force: an unbounded all-or-nothing send
     * whose failure marks nothing comes back next pass with MORE rows in it, and §1's only landing
     * zone hardens into permanent undeliverability while stderr promises a retry into a log nobody
     * reads. Measured by a review panel on 2026-08-24 at 120 entries and 20.9 KB in one send —
     * 4.4x the batch this project had just decided was safe.
     *
     * The remainder is not lost: it keeps `outcome = 'DIGEST' AND notified_at IS NULL`, so the next
     * pass re-collects it and `scout digest` can drain it now.
     */
    public function testThePipelineDigestIsCappedAndTheRemainderStaysPending(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();

        $over = Store::DIGEST_BATCH + 12;
        $listings = [];

        for ($i = 0; $i < $over; $i++) {
            $listings[] = new RawListing(
                sourceName: 'fake',
                externalId: 'D-' . $i,
                title: 'T4 Sartrouville',
                description: '4 pieces de 88 m2.',
                commune: 'Sartrouville',
                postcode: '78500',
                rentCc: 1450,
                surfaceM2: 88.0,
                rooms: 4,
            );
        }

        $result = $this->pipeline($store, new Notifier([$channel]))->runOnce(
            [new FakeSource('fake', listings: $listings, mixedTenure: true)],
            self::NOW,
        );

        self::assertSame($over, $result->digested, 'every one of them is judged doubtful');

        $digests = array_values(array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::DIGEST,
        ));
        self::assertCount(1, $digests, 'one digest notification per pass');
        self::assertCount(
            Store::DIGEST_BATCH,
            $digests[0]->reasons,
            'the unattended path must be bounded too — an all-or-nothing send that grows on every '
            . 'failure hardens the à vérifier bin into permanent undeliverability',
        );

        // The overflow is still pending, so the next pass or `scout digest` reaches it.
        self::assertCount(
            $over - Store::DIGEST_BATCH,
            $store->pendingDigest(1000),
            'the remainder must stay pending — capping may not silently drop a listing',
        );
    }

    /**
     * The cluster veto must survive the excluded sibling NOT BEING FETCHED.
     *
     * **It gated on `count($members) > 1`, where `$members` is this pass's harvest.** `Dedup` is fed
     * the listings fetched right now and never consults the store, so a survivor that clusters
     * alone on a later pass was judged alone — while `assignGroup()` returns before any UPDATE for
     * a single member, so the row KEEPS the `group_key` it earned when the excluded sibling was
     * present. The store still held `PLS` under that key at the moment the match was pushed.
     *
     * Three reachable triggers, all documented shapes: a source fetch that fails (caught per source,
     * pass continues, its listings simply absent), a `--source=<name>` run, and the excluded sibling
     * delisting while the other portal still publishes.
     *
     * And the same pass overwrites the stored `REJECT` with `MATCH` while the survivor's own tenure
     * resolves — putting the row outside `staleVerdicts()` AND `pendingDigest()`, so neither
     * `reclassify` nor `digest` can reach it afterwards. The "no third selector" argument this file
     * makes twice for other holes applies against this one.
     *
     * Round 5 taught `reclassify` to ask the store; the caller that runs every fifteen minutes was
     * not taught. Found by a review panel on 2026-08-24.
     */
    public function testTheClusterVetoSurvivesTheExcludedSiblingNotBeingFetched(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        // The survivor states an eligible tenure OUTRIGHT, so on its own it resolves to a match —
        // which is what makes the group the only thing standing between it and a push.
        $survivor = new RawListing(
            sourceName: 'inli',
            externalId: 'i-1',
            title: 'T4 Sartrouville',
            description: 'Logement intermédiaire (LLI), 4 pieces de 88 m2, ascenseur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $excluded = new RawListing(
            sourceName: 'cdc',
            externalId: 'c-1',
            title: 'T4 Sartrouville',
            description: 'Financement PLS. Commission d attribution, demande de logement social.',
            fields: ['financement' => 'PLS'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        // Pass 1: both present. The veto fires and the pair is grouped.
        $pipeline->runOnce(
            [
                new FakeSource('inli', listings: [$survivor], mixedTenure: true),
                new FakeSource('cdc', listings: [$excluded], mixedTenure: true),
            ],
            self::NOW,
        );

        self::assertSame('REJECT', $store->outcome($store->dedupKey($survivor)), 'pass 1 must veto');

        // Pass 2: the excluded source FAILS. Its listing is absent from the harvest, so the survivor
        // clusters alone — but the store still knows what it was clustered with.
        $channel->sent = [];
        $pipeline->runOnce(
            [
                new FakeSource('inli', listings: [$survivor], mixedTenure: true),
                new FakeSource('cdc', throw: new SourceError('cdc', 'timeout'), mixedTenure: true),
            ],
            '2026-08-08T12:00:00+02:00',
        );

        self::assertNotContains(
            NotificationKind::MATCH,
            array_map(static fn (Notification $n): NotificationKind => $n->kind, $channel->sent),
            'a flat whose GROUP holds an excluded tenure must not be notified because the sibling '
            . 'happened not to be fetched this pass — §1 is a property of the flat, not of the harvest',
        );
        self::assertSame(
            'REJECT',
            $store->outcome($store->dedupKey($survivor)),
            'and the stored rejection must not be overwritten by a re-judgement on less evidence',
        );
    }

    /**
     * Q34: the digest announces only what is NEW since the last successful emission.
     *
     * The ledger case for this — `the digest re-emits everything on every pass (Q34)` — was the ONE
     * case the nightly ledger found undetected at `9591545`, and it is detected at HEAD. But
     * nothing this session targeted it: the cover is INCIDENTAL, contributed by tests written for
     * the digest cap and the per-path `notified` counters, and incidental cover evaporates the
     * moment those tests change for their own reasons. This names the guarantee directly.
     *
     * The failure it guards is not a crash but a habit: a digest that re-lists the same doubtful
     * flats every fifteen minutes is one the developer learns to skip, and the fail-closed rule
     * loses its only landing zone.
     */
    public function testTheDigestAnnouncesOnlyWhatIsNewSinceTheLastSuccessfulEmission(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        // Distinct rents so the two are distinguishable in the digest LINE, which carries commune,
        // size and rent rather than the external id — and so Dedup cannot cluster them into one.
        $doubtful = static fn (string $id, int $rent = 1450): RawListing => new RawListing(
            sourceName: 'fake',
            externalId: $id,
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2, cuisine equipee.',
            url: 'https://example.test/' . $id,
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: $rent,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $first = $pipeline->runOnce(
            [new FakeSource('fake', listings: [$doubtful('q-1')], mixedTenure: true)],
            self::NOW,
        );
        self::assertSame(1, $first->digested, 'the premise: it reaches the digest');
        self::assertCount(1, $this->digestsIn($channel), 'and is announced once');

        // Pass 2: the SAME listing is still published, plus one genuinely new one.
        $channel->sent = [];
        $pipeline->runOnce(
            [new FakeSource('fake', listings: [$doubtful('q-1'), $doubtful('q-2', 1490)], mixedTenure: true)],
            '2026-08-08T12:00:00+02:00',
        );

        $digests = $this->digestsIn($channel);
        self::assertCount(1, $digests, 'one digest notification per pass');
        self::assertCount(
            1,
            $digests[0]->reasons,
            'ONLY the new listing. Re-listing q-1 every fifteen minutes is how a digest becomes '
            . 'furniture, and a digest nobody reads costs the fail-closed rule its landing zone',
        );
        self::assertStringContainsString('1490', $digests[0]->reasons[0], 'and it is the NEW one');
    }

    /** @return list<Notification> */
    private function digestsIn(RecordingChannel $channel): array
    {
        return array_values(array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::DIGEST,
        ));
    }

    /**
     * The remainder is a property of the BACKLOG, not of whether the channel accepted the batch.
     *
     * `$digestOverflow` was assigned inside the delivered branch, so a FAILED batch send reported
     * `0` — a 500-entry backlog whose 50-entry batch was rejected printed "1 notification(s) non
     * délivrée(s)" and no remainder line at all. `undelivered` does move, so this is small; it is
     * still a number that reads healthy on the exact pass it should not, which is the shape of
     * every defect round 7 found.
     */
    public function testTheDigestRemainderIsReportedEvenWhenTheBatchSendFAILED(): void
    {
        $store = $this->store();
        $over = Store::DIGEST_BATCH + 9;

        $listings = [];
        for ($i = 0; $i < $over; ++$i) {
            $listings[] = new RawListing(
                sourceName: 'fake',
                externalId: 'o-' . $i,
                title: 'T4 Sartrouville',
                description: '4 pieces, cuisine equipee.',
                url: 'https://example.test/o-' . $i,
                commune: 'Sartrouville',
                postcode: '78500',
                rentCc: 1400 + $i * 7,
                surfaceM2: 80.0 + $i,
                rooms: 4,
            );
        }

        // Every channel fails, so nothing is delivered and nothing is marked.
        $result = $this->pipeline($store, new Notifier([new FailingChannel()]))->runOnce(
            [new FakeSource('fake', listings: $listings, mixedTenure: true)],
            self::NOW,
        );

        // Against what was actually JUDGED doubtful, not against `$over` — Dedup may cluster a pair
        // out of a generated set, and pinning the generator rather than the invariant would make
        // this test fail for a reason that has nothing to do with the remainder.
        self::assertGreaterThan(0, $result->digested - Store::DIGEST_BATCH, 'the cap must bite');
        self::assertSame(
            $result->digested - Store::DIGEST_BATCH,
            $result->digestOverflow,
            'the remainder exists whether or not the batch was accepted — reporting 0 here says '
            . 'the backlog is drained on the one pass where none of it was even sent',
        );
        self::assertGreaterThan(0, $result->undelivered, 'and the failure is still counted');
    }

    /**
     * A DELIVERED match marks the survivor and nobody else.
     *
     * This is a recorded ruling — *"it must not be 'fixed' by marking members notified on delivery:
     * that is group-scoped suppression, and an over-merge would then hide a real flat permanently
     * and silently"* — and until round 7 nothing pinned it. A reviewer made exactly the forbidden
     * change, on this path and on the digest path separately, and the full suite stayed green both
     * times.
     *
     * It is also the change a future session is MOST likely to make, for two reasons both present
     * in this file: the `--seed` path twelve lines above does mark every member and IS pinned, so
     * the asymmetry reads as an oversight; and the live behaviour it would "fix" looks like a bug —
     * a survivorship flip really does push one grouped flat twice, which is the deliberate
     * under-merge-safe direction.
     *
     * `StoreGroupTest::testAnOverMergedGroupCannotSuppressANotification` does not cover this: it
     * asserts the STORE offers no group-scoped route, and the forbidden change is in the pipeline.
     * The failure guarded here is the invisible one — an over-merged pair whose absorbed member is
     * a genuinely different flat is then never notified, ever, and nothing arrives to say so
     * (hard rule 8).
     */
    public function testADeliveredMatchMarksOnlyTheSurvivorNeverTheWholeCluster(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();

        $a = $this->listing('m-1', ['source' => 'inli']);
        $b = $this->listing('m-2', ['source' => 'cdc']);

        $this->pipeline($store, new Notifier([$channel]))->runOnce(
            [
                new FakeSource('inli', listings: [$a]),
                new FakeSource('cdc', listings: [$b]),
            ],
            self::NOW,
        );

        $keyA = $store->dedupKey($a);
        $keyB = $store->dedupKey($b);
        self::assertNotSame($keyA, $keyB, 'two distinct rows, or this test proves nothing');
        self::assertSame(
            $keyA === $keyB ? 2 : 1,
            (int) $store->wasNotified($keyA) + (int) $store->wasNotified($keyB),
            'exactly ONE of the pair may be marked notified. Marking both is group-scoped '
            . 'suppression: if the merge was wrong, the other flat is silenced for ever',
        );
    }

    /**
     * The same ruling on the digest path, which a reviewer broke independently.
     *
     * A digest entry carries `keys` (every clustered member) as well as `key` (the survivor), so
     * the forbidden loop is even easier to write here than on the match path.
     */
    public function testADeliveredDigestMarksOnlyTheEntryKeyNeverEveryMember(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();

        // No tenure signal ANYWHERE — not in the title either, which is why these are built here
        // rather than through listing(), whose title says "logement intermediaire". A mixed source
        // with no signal is fail-closed UNKNOWN, so both go to the digest rather than to a match.
        $make = static fn (string $source, string $id): RawListing => new RawListing(
            sourceName: $source,
            externalId: $id,
            title: 'T4 Sartrouville',
            description: '4 pieces de 88 m2, ascenseur.',
            url: 'https://example.test/' . $id,
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $a = $make('inli', 'd-1');
        $b = $make('cdc', 'd-2');

        $result = $this->pipeline($store, new Notifier([$channel]))->runOnce(
            [
                new FakeSource('inli', listings: [$a], mixedTenure: true),
                new FakeSource('cdc', listings: [$b], mixedTenure: true),
            ],
            self::NOW,
        );

        self::assertGreaterThan(0, $result->digested, 'the pair must actually reach the digest');

        $keyA = $store->dedupKey($a);
        $keyB = $store->dedupKey($b);
        self::assertSame(
            1,
            (int) $store->wasNotified($keyA) + (int) $store->wasNotified($keyB),
            'a delivered digest marks the entry key, never every clustered member',
        );
    }

    /**
     * The counterweight: a persisted group holding only ELIGIBLE tenures must not veto.
     *
     * Without this the durable veto above is one character from rejecting every clustered listing
     * in the store — for ever, since `group_key` is never cleared. Over-rejection is the invisible
     * direction: nothing arrives to notice. The `reclassify` half of this rule shipped with exactly
     * this gap and the sabotage ledger caught it, so the pipeline half gets the same guard.
     */
    public function testAPersistedGroupOfEligibleSiblingsDoesNotVeto(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $a = $this->listing('p-1', ['source' => 'inli']);
        $b = $this->listing('p-2', ['source' => 'cdc']);

        $pipeline->runOnce(
            [
                new FakeSource('inli', listings: [$a]),
                new FakeSource('cdc', listings: [$b]),
            ],
            self::NOW,
        );

        self::assertNotNull($store->groupKey($store->dedupKey($a)), 'the pair must have clustered');

        // Second pass, one source absent — the survivor is alone, and its group says nothing bad.
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec('UPDATE listings SET notified_at = NULL, notified_as = NULL');
        $channel->sent = [];
        $pipeline->runOnce([new FakeSource('inli', listings: [$a])], '2026-08-08T12:00:00+02:00');

        self::assertContains(
            NotificationKind::MATCH,
            array_map(static fn (Notification $n): NotificationKind => $n->kind, $channel->sent),
            'a group of eligible siblings is not evidence against anything',
        );
    }

    // ---------------------------------------------------------------- what the pass announced

    /**
     * `notified` counts a delivered MATCH.
     *
     * Each of the three contributors is asserted SEPARATELY. Removing any one of them left the
     * suite green — only removing all three reddened anything, because the heartbeat test asserts
     * that *some* path counted. That is the round-4/round-5 defect one level down: aggregate is not
     * per-path, in the same way shape was not value. Found by a review panel on 2026-08-24.
     */
    public function testNotifiedCountsADeliveredMatch(): void
    {
        $store = $this->store();
        $result = $this->pipeline($store, new Notifier([new RecordingChannel()]))->runOnce(
            [new FakeSource('fake', listings: [$this->listing()])],
            self::NOW,
        );

        self::assertSame(1, $result->matches);
        self::assertSame(1, $result->notified, 'a delivered match is an announcement');
    }

    /** `notified` counts a delivered RENT DROP, which is a separate announcement from a match. */
    public function testNotifiedCountsADeliveredRentDrop(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();
        $pipeline = $this->pipeline($store, new Notifier([$channel]));

        $pipeline->runOnce([new FakeSource('fake', listings: [$this->listing('d1', ['rentCc' => 1450])])], self::NOW);

        // Same listing, notably cheaper. It is already notified, so the MATCH path is suppressed
        // and the rent drop is the only announcement the pass makes.
        $result = $pipeline->runOnce(
            [new FakeSource('fake', listings: [$this->listing('d1', ['rentCc' => 1200])])],
            '2026-08-08T12:00:00+02:00',
        );

        self::assertSame(1, $result->rentDrops);
        self::assertSame(
            1,
            $result->notified,
            'a rent drop reached the user even though the match itself was suppressed',
        );
    }

    /** `notified` counts every DELIVERED DIGEST ENTRY, not the notification that carried them. */
    public function testNotifiedCountsEveryDeliveredDigestEntry(): void
    {
        $store = $this->store();
        $doubtful = [];

        for ($i = 0; $i < 3; $i++) {
            $doubtful[] = new RawListing(
                sourceName: 'fake',
                externalId: 'u-' . $i,
                title: 'T4 Sartrouville',
                description: '4 pieces de 88 m2.',
                commune: 'Sartrouville',
                postcode: '78500',
                rentCc: 1450,
                surfaceM2: 88.0,
                rooms: 4,
            );
        }

        $result = $this->pipeline($store, new Notifier([new RecordingChannel()]))->runOnce(
            [new FakeSource('fake', listings: $doubtful, mixedTenure: true)],
            self::NOW,
        );

        self::assertSame(3, $result->digested);
        self::assertSame(
            3,
            $result->notified,
            'a digest-only pass announced three listings — reporting 0 would read as a mute watcher, '
            . 'and a mixed source before hydration is exactly that steady state',
        );
    }

    /** A capped digest says how many it did NOT send, on the pass that capped them. */
    public function testACappedPipelineDigestReportsItsRemainder(): void
    {
        $store = $this->store();
        $over = Store::DIGEST_BATCH + 9;
        $listings = [];

        for ($i = 0; $i < $over; $i++) {
            $listings[] = new RawListing(
                sourceName: 'fake',
                externalId: 'o-' . $i,
                title: 'T4 Sartrouville',
                description: '4 pieces de 88 m2.',
                commune: 'Sartrouville',
                postcode: '78500',
                rentCc: 1450,
                surfaceM2: 88.0,
                rooms: 4,
            );
        }

        $result = $this->pipeline($store, new Notifier([new RecordingChannel()]))->runOnce(
            [new FakeSource('fake', listings: $listings, mixedTenure: true)],
            self::NOW,
        );

        self::assertSame(
            9,
            $result->digestOverflow,
            'the notification titles on the BATCH and the pass summary counts what was JUDGED, so '
            . 'without this nothing anywhere says a remainder exists',
        );
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

    // ---------------------------------------------------------------- cross-portal group (v4)

    /**
     * EVERY harvested listing gets a row, not just the cluster survivor.
     *
     * Before schema v4 the pipeline clustered BEFORE recording and iterated survivors only, so a
     * duplicate member never reached `Store::record()` and had no row at all. A `group_key` on top
     * of that would only ever describe groups of one — the overlay would ship inert and look fine.
     */
    public function testEveryHarvestedListingIsRecordedNotJustTheSurvivor(): void
    {
        $store = $this->store();

        $this->pipeline($store)->runOnce([
            new FakeSource('alpha', [$this->listing('a1', ['source' => 'alpha'])]),
            new FakeSource('beta', [$this->listing('b1', ['source' => 'beta'])]),
        ], self::NOW);

        $rows = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query('SELECT source FROM listings ORDER BY source')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['alpha', 'beta'], $rows, 'the absorbed duplicate was never stored');
    }

    /** The members of one cluster share a group; the group is what the joined history reads. */
    public function testTheMembersOfAClusterShareAGroupKey(): void
    {
        $store = $this->store();

        $this->pipeline($store)->runOnce([
            new FakeSource('alpha', [$this->listing('a1', ['source' => 'alpha'])]),
            new FakeSource('beta', [$this->listing('b1', ['source' => 'beta'])]),
        ], self::NOW);

        $groups = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query('SELECT group_key FROM listings')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(2, $groups);
        self::assertNotNull($groups[0]);
        self::assertSame($groups[0], $groups[1], 'the two portals were not tied together');
    }

    /**
     * Members are CLASSIFIED, because `tenure IS NULL` already means something else.
     *
     * `Store::staleVerdicts()` selects `tenure IS NULL` and its docblock pins that to one meaning:
     * "stored before schema v3, deliberately not backfilled". Storing member rows unclassified would
     * give NULL a second meaning and silently enlarge the population `scout reclassify` re-announces.
     */
    public function testAnAbsorbedMemberIsClassifiedSoNullKeepsItsMeaning(): void
    {
        $store = $this->store();

        $this->pipeline($store)->runOnce([
            new FakeSource('alpha', [$this->listing('a1', ['source' => 'alpha'])]),
            new FakeSource('beta', [$this->listing('b1', ['source' => 'beta'])]),
        ], self::NOW);

        $tenures = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query('SELECT tenure FROM listings')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(2, $tenures);
        self::assertNotContains(null, $tenures, 'an absorbed member was left unclassified');
        self::assertSame([], $store->staleVerdicts(['UNKNOWN']), 'members leaked into reclassify');
    }

    /**
     * `--seed` marks EVERY member notified, not just the survivor.
     *
     * The seed contract is "everything currently published is already seen AND already told about".
     * An absorbed member is currently published. Before it had a row the gap could not be observed;
     * now it can, and the first pass whose shuffle flips survivorship would notify it.
     */
    public function testSeedMarksEveryMemberNotifiedNotOnlyTheSurvivor(): void
    {
        $store = $this->store();

        $this->pipeline($store)->runOnce([
            new FakeSource('alpha', [$this->listing('a1', ['source' => 'alpha'])]),
            new FakeSource('beta', [$this->listing('b1', ['source' => 'beta'])]),
        ], self::NOW, seedOnly: true);

        $unnotified = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query('SELECT COUNT(*) FROM listings WHERE notified_at IS NULL')->fetchColumn();

        self::assertSame(0, (int) $unnotified, 'a seeded member would be notified on a later pass');
    }

    /** Recording the members does not notify them — one flat is still one notification. */
    public function testAnAbsorbedMemberIsNotSeparatelyNotified(): void
    {
        $store = $this->store();
        $channel = new RecordingChannel();

        $this->pipeline($store, new Notifier([$channel]))->runOnce([
            new FakeSource('alpha', [$this->listing('a1', ['source' => 'alpha'])]),
            new FakeSource('beta', [$this->listing('b1', ['source' => 'beta'])]),
        ], self::NOW);

        self::assertCount(1, $channel->sent, 'the absorbed duplicate produced its own notification');
    }

    // ---------------------------------------------------------------- schema

    public function testTheSchemaVersionIsEleven(): void
    {
        // A bare constant assertion, and it earns its place: lowering `SCHEMA_VERSION` makes
        // `migrate()` return early on an EXISTING database, so an older one opens cleanly and then
        // throws `no such column` on the first write. A fresh database hides it entirely, because
        // `CREATE TABLE IF NOT EXISTS` always writes the current DDL.
        self::assertSame(11, Store::SCHEMA_VERSION);
        self::assertSame(11, $this->store()->schemaVersion());
    }

    public function testAVersionOneDatabaseIsUpgradedThroughEveryLaterStep(): void
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
        self::assertSame(11, $reopened->schemaVersion());

        // v5's table and v6's column are created by their own migration steps, not by the
        // fresh-database DDL, and this is the only path that proves the difference: a v1 database
        // never ran that DDL.
        $tables = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'listing_detail'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['listing_detail'], $tables, 'the v5 step did not run on an upgrade');

        $columns = array_column(
            (new \PDO('sqlite:' . (string) $this->dbPath))->query('PRAGMA table_info(listings)')->fetchAll(\PDO::FETCH_ASSOC),
            'name',
        );

        foreach (['seen_epoch', 'tenure', 'confidence_bp', 'signals_json', 'group_key', 'evidence_json', 'outcome', 'notified_as'] as $column) {
            self::assertContains($column, $columns, "the v1 -> v8 upgrade did not add `{$column}`");
        }

        // v6's ALTER runs against a table v5 has just created in the SAME migration, which is the
        // only ordering that can go wrong here and the only place it shows.
        $detailColumns = array_column(
            (new \PDO('sqlite:' . (string) $this->dbPath))->query('PRAGMA table_info(listing_detail)')->fetchAll(\PDO::FETCH_ASSOC),
            'name',
        );
        self::assertContains('map_fingerprint', $detailColumns, 'the v6 step did not run on an upgrade');
    }

    /**
     * Opening the same database twice must not fail on v6's ALTER.
     *
     * SQLite has no `ADD COLUMN IF NOT EXISTS`, so a bare ALTER throws `duplicate column name` the
     * second time — turning a re-entrant migration into a fatal one. Every other step here is
     * `CREATE TABLE IF NOT EXISTS` and re-runs harmlessly; this is the first that had to be guarded
     * by reading the column list, so it is the first that can regress.
     */
    public function testTheMigrationIsReRunnable(): void
    {
        $store = $this->store();
        $path = (string) $this->dbPath;
        unset($store);

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("UPDATE schema_meta SET value = '5' WHERE key = 'schema_version'");
        unset($pdo);

        self::assertSame(11, Store::open($path)->schemaVersion(), 'a re-run migration must not throw');
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

    private function pipeline(Store $store, ?Notifier $notifier = null, ?CommutePlanner $commute = null): Pipeline
    {
        return new Pipeline(
            $this->criteria(),
            $store,
            $notifier ?? new Notifier([new RecordingChannel()]),
            commute: $commute,
        );
    }
}

/** A source the test drives: fixed listings, a fixed failure, or a fixed health verdict. */
/** A planner that answers the same minutes for every commune — enough to make `enrich()` take its rebuild path. */
final readonly class FixedPlanner implements CommutePlanner
{
    public function __construct(private int $minutes) {}

    public function minutesFrom(?string $commune, ?string $postcode): ?int
    {
        return $this->minutes;
    }
}

/** A `FakeSource` that also reports feed freshness, so the pipeline's WRITE of it is observable. */
final readonly class FreshFakeSource implements \Scout\Adapters\FeedFreshness, Source
{
    /** @param list<RawListing> $listings */
    public function __construct(
        private string $name,
        private ?string $newest,
        private array $listings = [],
    ) {}

    public function newestFeedItemAt(): ?string
    {
        return $this->newest;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return 'private';
    }

    public function host(): ?string
    {
        return null;
    }

    public function defaultTenure(): ?Tenure
    {
        return null;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile($this->name, 'private', null, false);
    }

    public function fetch(): array
    {
        return $this->listings;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return new SourceHealth(sourceName: $this->name, status: SourceStatus::OK);
    }
}

final readonly class FakeSource implements Source
{
    /** @param list<RawListing> $listings */
    public function __construct(
        private string $name,
        private array $listings = [],
        private ?\Throwable $throw = null,
        private ?SourceHealth $health = null,
        private bool $mixedTenure = false,
        private string $family = 'institutional',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return $this->family;
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
        return new SourceProfile($this->name, $this->family === 'private' ? 'private' : 'institutional', $this->defaultTenure(), $this->mixedTenure);
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

    /**
     * YES — these doubles stand in for a real push channel.
     *
     * Deliberate: a double that could not reach a recipient would make every assertion about a
     * listing being marked notified pass for the wrong reason, which is exactly the round-8 P0
     * (`email` over a file transport voting as a delivery).
     */
    public function reachesRecipient(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'test double';
    }

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
    /**
     * YES — these doubles stand in for a real push channel.
     *
     * Deliberate: a double that could not reach a recipient would make every assertion about a
     * listing being marked notified pass for the wrong reason, which is exactly the round-8 P0
     * (`email` over a file transport voting as a delivery).
     */
    public function reachesRecipient(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'test double';
    }

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
