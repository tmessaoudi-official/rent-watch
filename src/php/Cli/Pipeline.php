<?php

declare(strict_types=1);

namespace RentWatch\Cli;

use RentWatch\Adapters\Source;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\Criteria;
use RentWatch\Core\Classification;
use RentWatch\Core\CriteriaEngine;
use RentWatch\Core\Dedup;
use RentWatch\Core\Notify\Formatter;
use RentWatch\Core\Notify\Notifier;
use RentWatch\Core\Outcome;
use RentWatch\Core\RawListing;
use RentWatch\Core\TenureClassifier;
use RentWatch\Core\Verdict;
use RentWatch\Store\Store;

/**
 * One pass: fetch every enabled source, classify, filter, dedup, store, notify.
 *
 * THE ORDER IS THE DESIGN, and each step is where it is for a reason that cost something to learn:
 *
 * 1. **Fetch, and record the run whatever happens.** A failed fetch is a recorded failure, never an
 *    empty result — `CLAUDE.md` hard rule 3, and the thing source health is derived from.
 * 2. **Classify, then apply criteria.** The classifier owns `REJECT`/`DIGEST` so §1's fail-closed
 *    rule lives in exactly one place.
 * 3. **Dedup across sources, before storing.** Deduping after would have already recorded two
 *    sightings of one flat and manufactured a price history for a listing that does not exist.
 * 4. **Store, THEN notify, and only mark notified when a channel confirmed.** The reverse order
 *    loses a listing on a crash between the two; marking optimistically loses it on a send failure.
 */
final readonly class Pipeline
{
    public function __construct(
        private Criteria $criteria,
        private Store $store,
        private Notifier $notifier,
        private TenureClassifier $classifier = new TenureClassifier(),
        private ?CriteriaEngine $engine = null,
        private Dedup $dedup = new Dedup(),
        private Formatter $formatter = new Formatter(),
    ) {}

    /**
     * @param list<Source> $sources
     * @param bool         $seedOnly populate the seen-set without notifying — `scout run --seed`,
     *                               the way through a freshly created database (Q36)
     */
    public function runOnce(array $sources, string $nowIso, bool $seedOnly = false): RunResult
    {
        $engine = $this->engine ?? new CriteriaEngine($this->criteria);

        $sourcesRun = 0;
        $sourcesFailed = 0;
        $itemsParsed = 0;
        $errors = [];

        /** @var list<array{listing: RawListing, family: string}> $harvested */
        $harvested = [];

        foreach ($sources as $source) {
            ++$sourcesRun;
            $startedAt = hrtime(true);

            try {
                $listings = $source->fetch();
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

                // `item_count` is what the ADAPTER PARSED, before criteria (Q30). Counting matches
                // would make source health a measure of the rental market rather than of the
                // adapter, and a drifted selector on a source whose matches are usually zero would
                // become undetectable — which is the exact failure §8 exists for.
                $this->store->recordRun($source->name(), count($listings), true, null, $nowIso, $durationMs);
                $itemsParsed += count($listings);

                foreach ($listings as $listing) {
                    $harvested[] = ['listing' => $listing, 'family' => $source->family()];
                }
            } catch (SourceError $e) {
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                ++$sourcesFailed;
                $errors[] = $e->getMessage();
                // Recorded, not swallowed. This row is what `SourceStatus::BROKEN` is derived from,
                // and a failure that leaves no trace is indistinguishable from a quiet market.
                $this->store->recordRun($source->name(), 0, false, $e->getMessage(), $nowIso, $durationMs);
            } catch (\Throwable $e) {
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                ++$sourcesFailed;
                // An adapter throwing something it did not declare is still a source failure, and it
                // must not abort the pass — the remaining sources have not been tried yet.
                $wrapped = new SourceError($source->name(), $e->getMessage(), $e);
                $errors[] = $wrapped->getMessage();
                $this->store->recordRun($source->name(), 0, false, $wrapped->getMessage(), $nowIso, $durationMs);
            }
        }

        $clustered = $this->dedup->cluster($harvested);
        $duplicates = count($harvested) - count($clustered);

        // EVERY member is recorded and classified here, not just the survivor the loop below
        // iterates. Until schema v4 the pipeline clustered first and recorded only survivors, so an
        // absorbed duplicate had no row at all — and `listings.group_key` on top of that would only
        // ever describe groups of one, shipping inert while looking finished.
        //
        // Classified, not merely recorded: `Store::staleVerdicts()` selects `tenure IS NULL` and
        // that value already means "stored before schema v3, deliberately not backfilled". Leaving
        // members NULL would give it a second meaning and silently enlarge what `scout reclassify`
        // re-announces.
        //
        // Recorded once and reused below, keyed on object identity, so the survivor is not stored
        // twice in one pass.
        /** @var array<int, array{sighting: \RentWatch\Store\Sighting, classification: \RentWatch\Core\Classification}> $observed */
        $observed = [];
        /** @var array<int, list<string>> $clusterKeys survivor object id -> every member's dedup key */
        $clusterKeys = [];
        $unencodable = 0;

        foreach ($clustered as $cluster) {
            $memberKeys = [];

            foreach ($cluster['members'] as $member) {
                $classification = $this->classifier->classify($member, $this->profileFor($sources, $member));
                $sighting = $this->store->record($member, $member->effectiveRentCc(), $nowIso);
                $captured = $this->store->recordVerdict(
                    $sighting->dedupKey,
                    $classification->tenure->value,
                    $classification->confidenceBp,
                    $classification->reasons(),
                    // Schema v7: the member EXACTLY as the classifier just consumed it — after
                    // mapping and after any detail merge, which is why `$member` is passed rather
                    // than anything re-derived. `scout reclassify` re-runs on this and must never
                    // run on less, so it is written in the same statement as the verdict it
                    // produced and cannot drift from it.
                    $member,
                );

                if (!$captured) {
                    // A listing whose PAYLOAD cannot be encoded — malformed UTF-8 anywhere in it,
                    // not necessarily its prose. A structured field alone will do it, on a listing
                    // whose title and description are clean, which then classifies normally and can
                    // be a NOTIFIED MATCH that silently lost its evidence.
                    //
                    // Its verdict is stored without a snapshot, which is honest: nothing can
                    // re-judge what was never captured. But "reclassify will skip it" — what an
                    // earlier version of this comment said — is only true of an UNKNOWN row.
                    // `staleVerdicts()` selects `tenure IS NULL OR tenure = 'UNKNOWN'`, so a MATCH
                    // row is not skipped, it is INVISIBLE to that command. `scout doctor` reports
                    // the standing count for exactly that reason. It used to THROW instead, from
                    // outside the per-source try/catch, and took the whole pass with it.
                    ++$unencodable;
                }

                $observed[spl_object_id($member)] = ['sighting' => $sighting, 'classification' => $classification];
                $memberKeys[] = $sighting->dedupKey;
            }

            // A cluster of one is not a group and `assignGroup()` returns null for it, leaving
            // `group_key` NULL — which is what keeps "never clustered" distinguishable from
            // "clustered, and the others have since delisted".
            $this->store->assignGroup($memberKeys);
            $clusterKeys[spl_object_id($cluster['listing'])] = $memberKeys;
        }

        $matches = 0;
        $digested = 0;
        $rejectedCount = 0;
        $rentDrops = 0;
        $undelivered = 0;
        $rejected = [];

        /** @var list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $digestEntries */
        $digestEntries = [];

        foreach ($clustered as $cluster) {
            $listing = $cluster['listing'];
            $observation = $observed[spl_object_id($listing)];
            $sighting = $observation['sighting'];
            $classification = $observation['classification'];

            // Freshness (score component S7) is measured from FIRST seen, not from this sighting.
            // `null` means "new right now" and earns the bonus outright; an existing listing earns
            // it only while it is still inside the window. Passing `null` unconditionally — which an
            // earlier draft did, with both branches of a ternary returning null — would have given
            // every listing the freshness bonus forever, quietly flattening the one component that
            // is supposed to separate a flat published this hour from one that has sat for a week.
            // §1 ACROSS THE WHOLE CLUSTER, not just the survivor. Every member is classified above
            // and each verdict stored, but only the survivor was ever JUDGED — so the same flat
            // published on a pure-LLI portal and on a mixed one that says `PLS` outright was a
            // match or a reject depending on which source happened to be polled first, and
            // `Core\Pacer` shuffles source order every pass. A coin-flip, not a stable answer.
            // The store then held that `PLS` under the same `group_key` as the row it had just
            // pushed as a match, and the notification's own `reasons[]` named the sibling.
            //
            // It inverts the documented dedup trade-off too: over-merging is supposed to cost a
            // hidden second flat, and here it LAUNDERED an excluded tenure into a notification.
            $judged = $this->clusterClassification($cluster['members'], $observed, $classification);

            $verdict = $engine->judge($listing, $judged, $this->ageSeconds($sighting->dedupKey, $nowIso));

            // Schema v7. Recorded for ALL THREE outcomes and BEFORE the branches below, because
            // both of them `continue` — writing it inside the digest branch alone would leave a
            // listing promoted DIGEST -> MATCH still carrying `DIGEST`, and `scout digest` would go
            // on announcing as doubtful something the pipeline had already notified as a match.
            //
            // Only the SURVIVOR reaches here. An absorbed member keeps `outcome` NULL, which is the
            // truth — it was recorded and classified but never judged — and that NULL is what stops
            // `pendingDigest()` from mistaking an unjudged member for a digested one.
            $this->store->recordOutcome($sighting->dedupKey, $verdict->outcome->value);

            if ($verdict->outcome === Outcome::REJECT) {
                ++$rejectedCount;
                $rejected[] = $listing->sourceName . ':' . $listing->externalId . ' — ' . (string) $verdict->disqualifier;
                continue;
            }

            if ($verdict->outcome === Outcome::DIGEST) {
                ++$digested;

                // ONLY WHAT IS NEW SINCE THE LAST SUCCESSFUL EMISSION (Q34). Without this the
                // digest repeats its whole contents every pass, which is the alert fatigue it was
                // designed to avoid — and a digest the developer has learned to skip costs the
                // fail-closed rule its only landing zone.
                //
                // `notified_at` answers "has this listing been announced at all", which is the
                // right question HERE: being told a flat is a match certainly covers being told it
                // is doubtful, so a match is never re-announced as a doubt.
                //
                // The reverse is NOT true, and this comment used to claim `scout reclassify` closed
                // that gap. It did not — the pipeline's own re-judgement removes the row from
                // `staleVerdicts()` before reclassify can ever see it. Schema v8's `notified_as` is
                // what closes it, and the match path below asks `wasNotifiedAs(..., 'MATCH')`.
                if (!$this->store->wasNotified($sighting->dedupKey)) {
                    $digestEntries[] = [
                        'listing' => $listing,
                        'verdict' => $verdict,
                        'key' => $sighting->dedupKey,
                        // Every member, for `--seed` only — see the seeding branch below.
                        'keys' => $clusterKeys[spl_object_id($listing)],
                    ];
                }

                continue;
            }

            ++$matches;

            if ($seedOnly) {
                // MARKED NOTIFIED WITHOUT SENDING, and that is the whole point of the mode.
                // Recording the sighting alone was not enough: `notified_at` stayed NULL, so the
                // very next run notified all of them and the flood simply moved one run later —
                // which is precisely what Q36 exists to prevent. `--seed` means "treat everything
                // currently published as already seen AND already told about"; only what appears
                // after it is news.
                //
                // EVERY MEMBER, not just the survivor. The contract is "everything currently
                // published is already seen AND already told about", and an absorbed member is
                // currently published — so the first later pass whose shuffle flipped survivorship
                // would notify it, which is the flood moved one run later rather than prevented.
                // This is not group-scoped suppression: it marks listings that were seen and seeded,
                // and it would mark them identically if they had clustered with nothing.
                foreach ($clusterKeys[spl_object_id($listing)] as $memberKey) {
                    $this->store->markNotified($memberKey, $nowIso, 'MATCH');
                }

                continue;
            }

            // A rent that fell on a listing we already knew is its own event — and one that crosses
            // the ceiling from above is a NEW MATCH whatever its size (Q33), which is why the size
            // thresholds are not consulted in that case.
            $crossedCeiling = $sighting->previousRentCc !== null
                && $this->criteria->maxRentCc !== null
                && $sighting->previousRentCc > $this->criteria->maxRentCc
                && ($listing->effectiveRentCc() ?? PHP_INT_MAX) <= $this->criteria->maxRentCc;

            if ($sighting->isPriceDrop && $sighting->previousRentCc !== null && $listing->effectiveRentCc() !== null) {
                $notable = $crossedCeiling
                    || $this->criteria->notify->isNotableDrop($sighting->previousRentCc, (int) $listing->effectiveRentCc());

                if ($notable) {
                    ++$rentDrops;
                    $failures = $this->notifier->send($this->formatter->rentDrop(
                        $listing,
                        $sighting->previousRentCc,
                        (int) $listing->effectiveRentCc(),
                        $crossedCeiling,
                    ));

                    if (!$this->notifier->delivered($failures)) {
                        ++$undelivered;
                    }
                }
            }

            // ASKED AS A MATCH, not merely "was this listing announced". A delivered digest sets
            // `notified_at`, so the plain question suppressed the match notification of any listing
            // promoted DIGEST -> MATCH — while `++$matches` above still counted it, so the pass
            // reported a match the operator never received. The same pass overwrites `tenure` and
            // `outcome`, taking the row out of `staleVerdicts()` and `pendingDigest()`, so neither
            // `scout reclassify` nor `scout digest` could reach it afterwards. There is no third
            // selector. Found by a review panel on 2026-08-24, against a docblock in this file that
            // asserted `reclassify` covered exactly this population.
            if ($this->store->wasNotifiedAs($sighting->dedupKey, 'MATCH')) {
                continue;
            }

            $failures = $this->notifier->send($this->formatter->match($listing, $verdict, $cluster['duplicates']));

            if ($this->notifier->delivered($failures)) {
                $this->store->markNotified($sighting->dedupKey, $nowIso, 'MATCH');
            } else {
                // Left un-notified ON PURPOSE, so the next run retries. Marking it notified here is
                // the hole Q28 closes: the run reports success, the listing is recorded as sent, and
                // the flat is gone with nothing anywhere saying so.
                ++$undelivered;
            }
        }

        if ($digestEntries !== []) {
            if ($seedOnly) {
                // Every member, for the same reason the match path seeds every member: an absorbed
                // duplicate is currently published, so seeding only the survivor leaves it to be
                // announced by the first pass that reshuffles source order.
                foreach ($digestEntries as $entry) {
                    foreach ($entry['keys'] as $memberKey) {
                        // SEEDED AS 'MATCH', not 'DIGEST', though these are digest entries.
                        // `--seed` means "nothing currently published is news", and seeding them
                        // as doubts would announce each one as a match the moment its tenure
                        // resolved — which for a mixed source with a `detail_map` is most of the
                        // catalogue, arriving over the following passes. STATED COST: a genuine
                        // promotion inside the seeded set is never announced. That is the quiet
                        // direction, and it is what the operator asked for by seeding.
                        $this->store->markNotified($memberKey, $nowIso, 'MATCH');
                    }
                }
            } else {
                // Emitted at the end of any run that produced NEW entries (Q34), rather than once a
                // day. A third of the corpus routes here, it is the fail-closed rule's only landing
                // zone, and a daily cadence turns "one glance, later" into "gone".
                $failures = $this->notifier->send($this->formatter->digest($digestEntries));

                if ($this->notifier->delivered($failures)) {
                    // Marked only AFTER the channel confirmed. Marking first would lose the whole
                    // batch permanently on a failed send — and a digest entry, unlike a match, has
                    // no second chance: nothing else will ever surface it.
                    foreach ($digestEntries as $entry) {
                        $this->store->markNotified($entry['key'], $nowIso, 'DIGEST');
                    }
                } else {
                    ++$undelivered;
                }
            }
        }

        if (!$seedOnly) {
            $undelivered += $this->alertOnHealth($sources, $nowIso);
        }

        return new RunResult(
            sourcesRun: $sourcesRun,
            sourcesFailed: $sourcesFailed,
            itemsParsed: $itemsParsed,
            matches: $matches,
            digested: $digested,
            rejectedCount: $rejectedCount,
            duplicates: $duplicates,
            rentDrops: $rentDrops,
            undelivered: $undelivered,
            unencodable: $unencodable,
            errors: $errors,
            rejected: $rejected,
        );
    }

    /**
     * Alert on EVERY status where `isAlerting()` is true, deduplicated per `(source, status)`.
     *
     * Ruled 2026-08-07 (Q29). The routing table originally named `SOURCE_BROKEN` alone, while six
     * statuses alert — so `NEVER_PRODUCED`, added precisely because it hid behind `OK`, and `STALE`,
     * which catches the schedule itself having stopped, would have been derived, stored and never
     * sent. An alert that is computed and never sent is worse than none (hard rule 2).
     *
     * @param list<Source> $sources
     */
    private function alertOnHealth(array $sources, string $nowIso): int
    {
        $undelivered = 0;
        $cooldown = $this->criteria->notify->sourceBrokenCooldownHours;

        foreach ($sources as $source) {
            $health = $source->health($nowIso);

            if (!$health->status->isAlerting()) {
                // A source that WAS alerting and is now OK gets exactly one recovery notice, and
                // clearing the keys is what makes a second, different breakage that day audible.
                if ($this->store->clearAlerts($source->name())) {
                    $failures = $this->notifier->send($this->formatter->sourceRecovered($health));
                    if (!$this->notifier->delivered($failures)) {
                        ++$undelivered;
                    }
                }

                continue;
            }

            if (!$this->store->shouldAlert($source->name(), $health->status->value, $nowIso, $cooldown)) {
                continue;
            }

            $failures = $this->notifier->send($this->formatter->sourceHealth($health));

            if ($this->notifier->delivered($failures)) {
                $this->store->markAlerted($source->name(), $health->status->value, $nowIso);
            } else {
                // NOT marked, so the alert is retried. A cooldown that starts on a failed send would
                // silence the alert for a day on the strength of a delivery that never happened.
                ++$undelivered;
            }
        }

        return $undelivered;
    }

    /**
     * The classification the CLUSTER is judged on: an excluded member vetoes, nothing else does.
     *
     * §1 is a property of the FLAT, and a cross-portal cluster is one flat seen twice. So if any
     * member carries an excluded tenure, that member's classification is what the engine judges —
     * its reasons travel with it, so the rejection says which portal stated what.
     *
     * **An UNKNOWN sibling deliberately does NOT veto.** Absence of a signal is not evidence, and
     * most search cards state no tenure at all — In\'li\'s entire card text is four facts and no
     * label — so vetoing on doubt would digest nearly every clustered match in the tree. Over-
     * rejection is the invisible failure here, because nothing arrives to notice. The excluded set
     * is closed and explicit; doubt is already handled by the fail-closed threshold on the
     * survivor\'s own classification.
     *
     * Ties go to the highest confidence, and the survivor wins an exact tie by being the default —
     * both are excluded in that case, so the choice only affects which reasons are shown.
     *
     * @param list<RawListing>                                                                            $members
     * @param array<int, array{sighting: \RentWatch\Store\Sighting, classification: \RentWatch\Core\Classification}> $observed
     */
    private function clusterClassification(array $members, array $observed, Classification $survivor): Classification
    {
        if (!$survivor->tenure->isExcluded() && \count($members) > 1) {
            foreach ($members as $member) {
                $memberClassification = $observed[spl_object_id($member)]['classification'] ?? null;

                if ($memberClassification === null || !$memberClassification->tenure->isExcluded()) {
                    continue;
                }

                if ($survivor->tenure->isExcluded() && $survivor->confidenceBp >= $memberClassification->confidenceBp) {
                    continue;
                }

                $survivor = $memberClassification;
            }
        }

        return $survivor;
    }

    /**
     * How long ago this listing was first seen, or `null` if it is new to the store right now.
     *
     * A listing with no snapshot is new. An UNDATEABLE `first_seen_at` returns `null` rather than
     * throwing: the store's own migration treats an undateable row as the oldest thing on record
     * rather than refusing to open the database, and refusing to score a listing over a bad
     * timestamp would be a harsher response to the same corruption.
     */
    private function ageSeconds(string $dedupKey, string $nowIso): ?int
    {
        $snapshot = $this->store->snapshot($dedupKey);
        if ($snapshot === null) {
            return null;
        }

        try {
            $first = new \DateTimeImmutable($snapshot->firstSeenAt);
            $now = new \DateTimeImmutable($nowIso);
        } catch (\Exception) {
            return null;
        }

        return max(0, $now->getTimestamp() - $first->getTimestamp());
    }

    /**
     * @param list<Source> $sources
     */
    private function profileFor(array $sources, RawListing $listing): \RentWatch\Core\SourceProfile
    {
        foreach ($sources as $source) {
            if ($source->name() === $listing->sourceName) {
                return $source->profile();
            }
        }

        // Unreachable in practice — the listing came from one of these sources. Fail CLOSED anyway:
        // `mixedTenure: true` with no default means a listing with no signal digests rather than
        // matching, which is the direction §1 requires when we do not know what we are looking at.
        return new \RentWatch\Core\SourceProfile($listing->sourceName, 'institutional', null, true);
    }
}
