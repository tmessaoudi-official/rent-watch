<?php

declare(strict_types=1);

namespace Scout\Cli;

use Scout\Adapters\FeedDate;
use Scout\Adapters\Source;
use Scout\Adapters\SourceError;
use Scout\Config\Criteria;
use Scout\Core\Classification;
use Scout\Core\CriteriaEngine;
use Scout\Core\Dedup;
use Scout\Core\Notify\Formatter;
use Scout\Core\Notify\Notifier;
use Scout\Core\Outcome;
use Scout\Core\RawListing;
use Scout\Core\Tenure;
use Scout\Core\TenureSignal;
use Scout\Core\TenureClassifier;
use Scout\Core\Verdict;
use Scout\Enrich\CommutePlanner;
use Scout\Store\Store;

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
        /**
         * Optional, and `null` is the shipped default — no key, no destination, no enrichment.
         *
         * Injected rather than constructed here so the pipeline never learns that the network
         * exists, exactly as `PacedSource` keeps it from learning that time does. Every test in the
         * tree therefore runs enrichment OFF unless it says otherwise.
         */
        private ?CommutePlanner $commute = null,
    ) {}

    /**
     * This listing plus its commute, or this listing unchanged.
     *
     * Never throws and never rejects: a planner is contractually required to answer `null` rather
     * than raise, and `null` is UNKNOWN rather than far (hard rule 9). The `try` is belt-and-braces
     * around a third-party implementation — one bad lookup must not void a pass that has already
     * fetched real listings.
     */
    private function enrich(RawListing $listing): RawListing
    {
        if ($this->commute === null || $listing->commuteMinutes !== null) {
            return $listing;
        }

        try {
            $minutes = $this->commute->minutesFrom($listing->commune, $listing->postcode);
        } catch (\Throwable) {
            return $listing;
        }

        if ($minutes === null) {
            return $listing;
        }

        return new RawListing(
            sourceName: $listing->sourceName,
            externalId: $listing->externalId,
            title: $listing->title,
            description: $listing->description,
            fields: $listing->fields,
            url: $listing->url,
            commune: $listing->commune,
            postcode: $listing->postcode,
            rentCc: $listing->rentCc,
            rentHc: $listing->rentHc,
            charges: $listing->charges,
            surfaceM2: $listing->surfaceM2,
            rooms: $listing->rooms,
            bedrooms: $listing->bedrooms,
            floor: $listing->floor,
            hasElevator: $listing->hasElevator,
            detailRead: $listing->detailRead,
            commuteMinutes: $minutes,
        );
    }

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
                // The feed date is read only on the SUCCESS path, and only after `fetch()` — before
                // one there is nothing to report, and on a failure the mailbox never got far enough
                // to see a message. Writing a stale value into a failed run would let the previous
                // pass's freshness vouch for a pass that fetched nothing.
                $feedNewestAt = FeedDate::of($source);
                // Kept on ONE line on purpose. `tests/sabotage-check.sh` matches this call by its
                // literal prefix up to `true`, and a multi-line reformat silently rots that
                // expression into matching nothing — the exact way a `markNotified()` signature
                // change rotted one on 2026-08-24, reporting coverage the ledger no longer had.
                $this->store->recordRun($source->name(), count($listings), true, null, $nowIso, $durationMs, $feedNewestAt);
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

        // ENRICHMENT, BEFORE CLUSTERING AND THEREFORE BEFORE EVERYTHING.
        //
        // Placed here rather than beside `judge()` for two independent reasons, and either alone
        // would decide it. **Hard rule 8:** a disqualifier applied before enrichment rejects on a
        // field enrichment would have filled, and silent over-rejection is invisible because nothing
        // arrives. **And the v7 snapshot** is written from the member exactly as the classifier
        // consumed it, so a listing enriched after that point would be re-judged by
        // `scout reclassify` without its commute and silently score lower the second time.
        //
        // Clustering is downstream of it because `$observed` is keyed on OBJECT IDENTITY: enriching
        // afterwards would replace member objects that the survivor is a second reference to, and
        // the cluster bookkeeping would quietly stop matching.
        $harvested = array_map(
            fn (array $row): array => ['listing' => $this->enrich($row['listing']), 'family' => $row['family']],
            $harvested,
        );

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
        /** @var array<int, array{sighting: \Scout\Store\Sighting, classification: \Scout\Core\Classification}> $observed */
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

        $digestOverflow = 0;

        // CONFIRMED DELIVERIES, which is not `$matches`. See `RunResult::$notified` — `$matches` is
        // incremented when the engine judges, before the already-announced gate and before the
        // channel confirms, so in steady state it is the standing match count while the pass sends
        // nothing at all.
        $notified = 0;
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
            // THE PERSISTED GROUP, not this pass's harvest. `Dedup` clusters what was fetched
            // right now and never consults the store, so the veto used to be a function of who
            // happened to be fetched together — while `assignGroup()` returns before any UPDATE for
            // a single member, so the row KEEPS the group it earned when the excluded sibling was
            // present. A failed source fetch, a `--source=<name>` run, or the sibling delisting was
            // enough to launder the flat into a match on the very next pass, and that pass
            // overwrote the stored REJECT with MATCH while the survivor's own tenure resolved —
            // putting the row outside `staleVerdicts()` AND `pendingDigest()`, beyond the reach of
            // either repair command.
            $judged = $this->clusterClassification(
                $classification,
                $this->store->groupExcludedTenure($sighting->dedupKey),
            );

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

                    if ($this->notifier->delivered($failures)) {
                        ++$notified;
                    } else {
                        ++$undelivered;
                    }
                }
            }

            // **THE MATCH PATH IS DELIBERATELY UNCAPPED**, and that decision is written here because
            // a review panel found it stated nowhere while the three announcement paths beside it
            // were all capped this session. It IS the biggest measured burst in the tree — 92
            // notified rows in one live pass on 2026-08-22 after Q1-Q3 widened three filters in a
            // day, as 92 separate pushes.
            //
            // The digest's reasoning does not transfer, and the difference is the whole argument.
            // A digest is ONE all-or-nothing send whose failure marks nothing, so the batch that
            // failed came back strictly LARGER every pass: self-perpetuating growth, and the cap
            // turns it into a drain. A match is sent per listing and marked per listing, so a burst
            // is bounded by how many new flats exist and a failure retries exactly one of them.
            // Nothing compounds.
            //
            // Against that, capping costs the thing the product is for — the brief says a
            // notification "within minutes of publication", and a capped match waits a full Q37
            // interval for no benefit. Reverses by wrapping this send the way the digest branch is
            // wrapped, if a channel is ever observed rate-limiting a real burst.
            //
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
                ++$notified;
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
                        // as doubts would make the PIPELINE announce each one as a match the moment
                        // its tenure resolved — which for a mixed source with a `detail_map` is
                        // most of the catalogue, arriving over the following passes.
                        //
                        // **The cost this used to state was false.** It said "a genuine promotion
                        // inside the seeded set is never announced"; `scout reclassify` announces
                        // it, because `staleVerdicts()` filters on neither `notified_at` nor
                        // `outcome` and `announcePromotions()` never consults `wasNotifiedAs()`. A
                        // review panel demonstrated it on 2026-08-24. So the real cost is narrower:
                        // the PIPELINE will not re-announce a seeded row, and a deliberate
                        // `scout reclassify` still can — which is the right split, since that
                        // command is run on purpose rather than every fifteen minutes.
                        $this->store->markNotified($memberKey, $nowIso, 'MATCH');
                    }
                }
            } else {
                // Emitted at the end of any run that produced NEW entries (Q34), rather than once a
                // day. A third of the corpus routes here, it is the fail-closed rule's only landing
                // zone, and a daily cadence turns "one glance, later" into "gone".
                //
                // **CAPPED, like the manual drain.** `scout digest` was bounded first and this was
                // not — and this is the path that runs unattended, so the reasoning applied here
                // with more force: an unbounded all-or-nothing send whose failure marks nothing
                // comes back next pass with MORE rows in it, and §1's only landing zone hardens
                // into permanent undeliverability while stderr promises a retry into a log nobody
                // reads. Measured by a review panel on 2026-08-24 at 120 entries and 20.9 KB in one
                // send — 4.4x the batch this project had just decided was safe.
                //
                // The remainder is NOT lost: it keeps `outcome = 'DIGEST' AND notified_at IS NULL`,
                // so the next pass re-collects it and `scout digest` can drain it now. Capping
                // makes the backlog shrink; leaving it uncapped made it grow.
                $batch = \array_slice($digestEntries, 0, Store::DIGEST_BATCH);

                // ASSIGNED BEFORE THE SEND, not inside the success branch. It sat there until round
                // 7, so on a FAILED batch send the remainder read `0` — a 500-entry backlog whose
                // 50-entry batch was rejected printed "1 notification(s) non délivrée(s)" and no
                // remainder line at all. The pass summary's `à vérifier` is what was JUDGED, never
                // what is pending, so nothing anywhere named it. The number is a property of the
                // BACKLOG and the cap, not of whether the channel accepted this batch — and it
                // reading healthy on the one pass it should not is the shape of every defect this
                // round found.
                $digestOverflow = max(0, \count($digestEntries) - \count($batch));

                $failures = $this->notifier->send($this->formatter->digest($batch));

                if ($this->notifier->delivered($failures)) {
                    // Marked only AFTER the channel confirmed. Marking first would lose the whole
                    // batch permanently on a failed send — and a digest entry, unlike a match, has
                    // no second chance: nothing else will ever surface it.
                    foreach ($batch as $entry) {
                        $this->store->markNotified($entry['key'], $nowIso, 'DIGEST');
                    }

                    // THE REMAINDER IS NAMED, like both sibling caps. `Formatter::digest()` titles
                    // on the BATCH, so a 120-entry backlog pushed as "À vérifier : 50 annonce(s)"
                    // and the pass summary's `à vérifier` is what was JUDGED this pass, never what
                    // is still pending — so nothing anywhere said a remainder existed. The claim
                    // that the next pass re-collects it holds only while the ad is still published,
                    // and the delisted case is exactly what `scout digest` was built to rescue.
                    $notified += \count($batch);
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
            notified: $notified,
            digestOverflow: $digestOverflow,
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
     * **THAT SENTENCE WAS FALSE OF EVERY PASS BUT THE FIRST**, and it took a sixth review round to
     * catch. The check USED to read a `$members` parameter — what `Dedup` clustered out of THIS
     * pass's harvest, the store never consulted — while `assignGroup()` returns before any UPDATE
     * for a single member, so a survivor that clusters alone keeps the `group_key` it earned when
     * the excluded sibling was there. A failed source fetch, a `--source=<name>` run or the sibling
     * delisting was enough to make the flat a match on the next pass. `$groupTenure` — read from
     * the PERSISTED group — is what answers now.
     *
     * There is no in-pass scan any more, and this docblock described one for a round after it was
     * deleted, with a reason that was also wrong in the direction this review keeps finding: it
     * said a first-ever sighting "has no group yet". It does. `assignGroup()` runs in the recording
     * loop at the top of `runOnce()`, before any judging — which is precisely WHY the scan was dead
     * code and why disabling it left the whole suite green.
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
     * **STATED COST, and it is a real one: an OVER-MERGE now costs both flats, not one.** `Dedup`'s
     * documented failure mode is merging two different flats on two corroborating facts; before
     * this rule that hid the absorbed one and still notified the survivor, and now an eligible LLI
     * merged with a stranger carrying `PLS` is silently rejected with it. Demonstrated by a review
     * panel on 2026-08-24 (same commune, same rooms, rents within `Dedup::RENT_TOLERANCE_EUR`, one
     * surface unstated). The direction is the one §1 requires — a missed listing is annoying, a
     * social-housing false positive is not — but silent over-rejection is invisible by definition,
     * so it is written here rather than left to be discovered.
     *
     * **And since the veto now reads the PERSISTED group, that cost is PERMANENT.** `group_key` is
     * never cleared — `grep "SET group_key"` finds only the two `assignGroup()` UPDATEs — so a flat
     * once mis-merged with an excluded stranger is rejected for the rest of the store's life, even
     * on passes where the two no longer cluster. Deliberate: the alternative is a veto that lapses
     * the moment a source has a bad day, which is the hole this durable read was added to close. If
     * it ever bites, the repair is to unpick the group in the store, not to weaken the rule.
     *
     * **ONE MECHANISM, not two.** This began as a scan over the members clustered in THIS pass,
     * and the durable read was added beside it in round 6. The sabotage ledger then showed the
     * in-pass scan was DEAD: `assignGroup()` runs in the recording loop, before any judging, so
     * for every cluster of two or more the persisted group is already up to date when this is
     * called — disabling the in-pass scan left the whole suite green. Dead safety code is worse
     * than none: it reads as a second line of defence and is not one. Removed rather than kept.
     */
    private function clusterClassification(Classification $survivor, ?Tenure $groupTenure): Classification
    {
        // No re-check that `$groupTenure` is excluded: `Store::groupExcludedTenure()` returns
        // nothing else, and the caller-side re-check that used to sit here was unreachable — a
        // sabotage case written against it went undetected because no input can reach it.
        if ($survivor->tenure->isExcluded() || $groupTenure === null) {
            return $survivor;
        }

        $tenure = $groupTenure;

        return new Classification(
            tenure: $tenure,
            // CARRIED FOR THE RECORD, not for the decision. `CriteriaEngine` branches on
            // `$classification->outcome` alone — it never reads tenure or confidence — so this
            // number decides nothing, and an earlier version of this comment claimed it had to
            // "clear the fail-closed floor on its own". It does not; that was an invented
            // justification, and the sabotage case written to pin it correctly went undetected.
            // 100 is what a stored, explicit tenure on a sibling is worth to `scout dump` and to
            // anyone reading the row.
            confidenceBp: 100,
            signals: [new TenureSignal(
                tier: 1,
                tenure: $tenure,
                reason: 'régime exclu (' . $tenure->value . ') relevé sur un doublon de cette annonce',
                evidence: $tenure->value,
            )],
            outcome: Outcome::REJECT,
        );
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
    private function profileFor(array $sources, RawListing $listing): \Scout\Core\SourceProfile
    {
        foreach ($sources as $source) {
            if ($source->name() === $listing->sourceName) {
                return $source->profile();
            }
        }

        // Unreachable in practice — the listing came from one of these sources. Fail CLOSED anyway:
        // `mixedTenure: true` with no default means a listing with no signal digests rather than
        // matching, which is the direction §1 requires when we do not know what we are looking at.
        return new \Scout\Core\SourceProfile($listing->sourceName, 'institutional', null, true);
    }
}
