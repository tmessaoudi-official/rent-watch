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

    protected function setUp(): void
    {
        // A REMOTE channel that needs no network and no credential: `email` over the file
        // transport writes `.eml` into <root>/var/outbox. Before round 7 these tests ran
        // console-only, and console then satisfied `Notifier::delivered()` — so every assertion
        // about a listing being marked notified passed for a reason that was itself the P0.
        putenv('SMTP_TO=watcher@example.test');
        putenv('SMTP_TRANSPORT=file');
    }

    protected function tearDown(): void
    {
        putenv('SMTP_TRANSPORT');
        putenv('SMTP_TO');
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
        // THE TENURE, not just the outcome. `mixedTenure: true` is inert while `defaultTenure` is
        // null — tier 5 is the only sub-floor tier and it needs a non-null default, and every other
        // route to "eligible below the floor" is forced to UNKNOWN by the conflict rule — so the
        // outcome assertion above cannot see the half of the fail-closed profile that actually
        // bites. A default of LLI would make this row `LLI`, and `LLI` plus a non-mixed source is a
        // MATCH. Asserting the stored tenure is what makes that flip visible.
        self::assertSame('UNKNOWN', $this->tenureOf($root, $key));
    }

    public function testNothingIsWrittenWhenAPromotionCannotBeDelivered(): void
    {
        // The retry the warning promises has to be REAL. Writing the verdict before the send removes
        // the row from `staleVerdicts()` (its tenure resolves) AND from `pendingDigest()` (its
        // outcome is no longer DIGEST), and there is no third selector — so a failed send left a
        // MATCH nobody was told about that no command could reach again. The population is exactly
        // the one that cannot be rescued by the pipeline either: a still-published listing is
        // re-judged next pass, but these commands exist for the listing that has since delisted.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('NTFY_TOPIC=rent-watch-test');
        putenv('NTFY_SERVER=http://127.0.0.1:1');

        $key = $this->seed($root, $this->intermediateListing('H-8'), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(1, $result['code'], 'an undelivered promotion must exit non-zero');

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key), 'the outcome must not move before the channel confirms');
        self::assertSame('UNKNOWN', $this->tenureOf($root, $key), 'nor the tenure — that is what keeps it in staleVerdicts()');
        self::assertFalse($store->wasNotified($key));

        // The actual retry, exercised rather than asserted about: a second run with a working
        // channel must find and announce it. That channel has to be a REMOTE one — it was
        // `console` until round 7, which delivered nothing and proved the retry worked anyway.
        putenv('NTFY_TOPIC');
        putenv('NTFY_SERVER');
        $second = $this->scout(
            $this->reconfigure($root, ['notify' => ['channels' => ['console', 'email']]]),
            ['reclassify'],
        );

        self::assertSame(0, $second['code'], $second['err']);
        self::assertStringContainsString('1 promotion', mb_strtolower($second['out']));
        self::assertSame('MATCH', $store->outcome($key));
        self::assertTrue($store->wasNotified($key));
    }

    public function testAnUnusableChannelRefusesBeforeAnyRowIsTouched(): void
    {
        // The refusal has to come FIRST. Built after the loop — as it was until 2026-08-24 — a
        // deploy whose NTFY_TOPIC is not yet filled in re-judged everything, rewrote every verdict,
        // and only then discovered it had nowhere to send: one run consumed the entire promotable
        // backlog while printing a message about an environment variable. `run`, `digest` and
        // `test-notify` all refuse before doing the work; this was the one verb that did not.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('NTFY_TOPIC');
        putenv('NTFY_SERVER');

        $key = $this->seed($root, $this->intermediateListing('K-1'), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(2, $result['code'], 'no usable channel is a startup refusal');
        self::assertStringNotContainsString(
            're-jugée(s)',
            $result['out'],
            'the refusal must precede the work, not report it',
        );

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key));
        self::assertSame('UNKNOWN', $this->tenureOf($root, $key));
    }

    public function testDryRunNeedsNoChannelAtAll(): void
    {
        // The counterweight to the refusal above. `--dry-run` sends nothing, so demanding a working
        // channel would refuse the one command whose whole purpose is to look before touching
        // anything — and would do it on the machine least likely to have a channel configured yet.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('NTFY_TOPIC');
        putenv('NTFY_SERVER');

        $this->seed($root, $this->intermediateListing('K-2'), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify', '--dry-run']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('re-jugée(s)', $result['out']);
    }

    public function testAListingThatWasRejectedAndNowQualifiesIsAnnounced(): void
    {
        // REJECT -> MATCH is not a demotion, and it is reachable the moment the criteria widen —
        // Q1-Q3 widened three filters in one day. `docs/OPEN-QUESTIONS.md` already rules that a
        // listing which was disqualified and now qualifies IS a new match; recording it silently
        // stranded it exactly like an undelivered promotion.
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('I-9'), tenure: 'UNKNOWN', outcome: 'REJECT');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 promotion', mb_strtolower($result['out']));

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('MATCH', $store->outcome($key));
        self::assertTrue($store->wasNotified($key));
    }

    public function testTheUnreadableCountIsReportedNotJustThePerRowWarning(): void
    {
        // The per-row warning says WHICH row; the count says HOW MANY, and only the count survives
        // a run with hundreds of rows scrolling past. A database quietly losing snapshots is
        // otherwise indistinguishable from one with nothing to re-judge.
        $root = $this->tempRoot();
        foreach (['J-1', 'J-2'] as $id) {
            $this->corruptSnapshot($root, $this->seed(
                $root,
                new RawListing(sourceName: 'demo', externalId: $id, title: 'T4'),
                tenure: 'UNKNOWN',
                outcome: 'DIGEST',
            ));
        }

        $result = $this->scout($root, ['reclassify']);

        self::assertStringContainsString('2 instantané(s) illisible(s)', $result['out']);
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

    /** The listing every promotion test starts from: eligible, and eligible for a stated reason. */
    /**
     * A listing its CLUSTER vetoed must not be resurrected by `reclassify`.
     *
     * **This is the round-4 cluster veto being undone by a shipped command.** The pipeline judges a
     * cluster on its most restrictive member, but stores each member's OWN tenure and OWN snapshot
     * — so a vetoed survivor whose card states no tenure is left `tenure = 'UNKNOWN'`,
     * `outcome = 'REJECT'`. `staleVerdicts()` selects on `tenure` alone, so `reclassify` picked it
     * up and re-judged it on its own snapshot, in which the sibling's `PLS` cannot appear.
     *
     * That is the invariant this command exists to hold, read exactly: **evidence ⊇ original,
     * never ⊂.** The cluster's evidence is part of the original. A review panel drove it end to end
     * on 2026-08-24 through the real pipeline and the real commands.
     */
    public function testAListingItsClusterVetoedIsNotResurrected(): void
    {
        $root = $this->tempRoot();

        // The survivor: a card stating no tenure at all, rejected by its cluster rather than by
        // anything in its own text.
        $survivor = new RawListing(
            sourceName: 'demo',
            externalId: 'VETO-1',
            title: 'T4 lumineux',
            description: 'Quatre pieces, 88 m2, ascenseur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $sibling = new RawListing(
            sourceName: 'other',
            externalId: 'VETO-2',
            title: 'T4 lumineux',
            description: 'Financement PLS, commission d attribution.',
            fields: ['financement' => 'PLS'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $key = $this->seed($root, $survivor, 'UNKNOWN', 'REJECT');
        $siblingKey = $this->seed($root, $sibling, 'PLS', null);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $store->assignGroup([$key, $siblingKey]);

        $r = $this->scout($root, ['reclassify']);

        self::assertSame(0, $r['code']);
        self::assertStringNotContainsString(
            'promotion(s) vers MATCH.' . PHP_EOL,
            str_replace('0 promotion(s) vers MATCH.', '', $r['out']),
            'a vetoed listing must never be promoted',
        );
        self::assertSame(
            'REJECT',
            $this->pdo($root)
                ->query("SELECT outcome FROM listings WHERE dedup_key = '" . $key . "'")
                ->fetchColumn(),
            'the cluster veto must survive a re-judgement that cannot see the evidence which caused it',
        );
        self::assertStringContainsString(
            'écartée(s) par un doublon',
            $r['out'],
            'the skip must be counted out loud — a silent skip is indistinguishable from a bug',
        );
    }

    /**
     * The counterweight: a clustered listing whose siblings are ELIGIBLE is still re-judged.
     *
     * Without this the veto above is one character from skipping every clustered row in the store
     * — and over-rejection is the invisible direction, because nothing arrives to notice. The
     * sabotage ledger proved the gap: reading the group's tenures as excluded regardless of what
     * they say left the whole suite green.
     */
    public function testAClusteredListingWithEligibleSiblingsIsStillRejudged(): void
    {
        $root = $this->tempRoot();

        $listing = $this->intermediateListing('PAIR-1');
        $sibling = new RawListing(
            sourceName: 'other',
            externalId: 'PAIR-2',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $key = $this->seed($root, $listing, 'UNKNOWN', 'DIGEST');
        $siblingKey = $this->seed($root, $sibling, 'LLI', null);

        Store::open($root . '/state/rent-watch.sqlite3')->assignGroup([$key, $siblingKey]);

        $r = $this->scout($root, ['reclassify']);

        self::assertSame(0, $r['code']);
        self::assertStringContainsString(
            '1 annonce(s) re-jugée(s)',
            $r['out'],
            'an eligible sibling is not evidence against anything — the row must still be judged',
        );
        self::assertStringNotContainsString('écartée(s) par un doublon', $r['out']);
    }

    /**
     * The promotion announcements are CAPPED, and the cap is pinned.
     *
     * Round 5 capped three announcement paths in one commit; two got a named test and this one got
     * none — deleting the `array_slice` left the whole suite green, and no ledger case touched it
     * either. The population it bounds is the worst case the cap was added for: `staleVerdicts()`
     * after a `--seed` is everything published at seed time, at one push per promotion. Found by a
     * review panel on 2026-08-24.
     */
    public function testPromotionAnnouncementsAreCappedAndTheRemainderSurvives(): void
    {
        $root = $this->tempRoot();
        $over = Store::DIGEST_BATCH + 4;

        for ($i = 0; $i < $over; $i++) {
            $this->seed($root, $this->intermediateListing('PROMO-' . $i), 'UNKNOWN', 'DIGEST');
        }

        $first = $this->scout($root, ['reclassify']);

        self::assertSame(0, $first['code']);
        self::assertStringContainsString(
            Store::DIGEST_BATCH . ' promotion(s) vers MATCH.',
            $first['out'],
            'one batch, capped',
        );
        self::assertStringContainsString(
            '4 promotion(s) au-delà du lot',
            $first['out'],
            'the remainder must be named, or the operator believes the backlog is drained',
        );

        // Nothing is written for an un-announced promotion, so the remainder is still reachable.
        $second = $this->scout($root, ['reclassify']);

        self::assertStringContainsString(
            '4 promotion(s) vers MATCH.',
            $second['out'],
            'capping may not silently drop a promotion — the next run must reach it',
        );
    }

    private function intermediateListing(string $id): RawListing
    {
        return new RawListing(
            sourceName: 'demo',
            externalId: $id,
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
    }

    private function tenureOf(string $root, string $key): ?string
    {
        $statement = $this->pdo($root)->prepare('SELECT tenure FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $key]);
        /** @var string|false|null $tenure */
        $tenure = $statement->fetchColumn();

        return is_string($tenure) ? $tenure : null;
    }

    /** @param array<string,mixed> $criteria */
    private function reconfigure(string $root, array $criteria): string
    {
        file_put_contents($root . '/config/criteria.json', json_encode($criteria + [
            'notify' => ['channels' => ['console', 'email']],
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'min_surface_m2' => 50,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

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
            'notify' => ['channels' => ['console', 'email']],
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
