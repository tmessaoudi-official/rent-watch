# Finish everything except phorj

> **Written 2026-08-23** on the developer's instruction: *"let's finish everything! except for phorj!
> because phorj is not ready."* This file is the durable record of what "everything" means, because
> the instruction arrived immediately before a `/compact` and a scope held only in context is a
> scope that does not survive one.

## What is already finished (do not redo)

Spec milestones **1–5 are done and in production**: the pure core, the tenure classifier, In'li,
health + `doctor`, and CDC Habitat. Milestone **7 is measured out** — 8 of 15 catalogued landlords
are recorded NOT POLLABLE with evidence in `docs/SOURCES.md`, and all four worth building are live
(In'li, CDC Habitat, Cityloger, Logirep). Detail hydration (phases 2 and 2b) is built, certified and
**deployed**; production ran v4 → v6 on 2026-08-23 and converged to `478 annonce(s) · 82
correspondance(s), 10 à vérifier` with In'li at 167/168 detail pages cached.

`src/phorj/` is **OUT OF SCOPE by this instruction** — not deprioritised into a later phase of this
plan, simply not in it. `docs/PHORJ-REQUIREMENTS.md` stays the record of what it would need.

## The two buckets, and why the split is the whole plan

**Everything remaining divides on one question: does it need something only the developer can
supply?** That is not a scheduling convenience — it is the difference between work that can proceed
autonomously tonight and work that cannot start at all. Bucket A is done first *because* it needs
nobody; Bucket B is written down now so the inputs can be gathered in parallel rather than
discovered one at a time.

### Bucket A — unblocked, no inputs required

| # | Item | Size | Where it is recorded |
|---|---|---|---|
| A1 | Classifier performance (~155 ms/listing) | S | `milestone-1-pipeline.plan.md` — the table's one explicitly unblocked row; the `src/` freeze that deferred it is lifted |
| A2 | `scout digest` on demand | S–M | Q34 **RULED** it real: on-demand emission, "only what is new since the last successful emission", entries marked emitted only after the channel confirms delivery, unsent digest retried next run |
| A3 | `scout reclassify [--since]` | M | Q35 **RULED** it real. Its stub names raw-text storage as the blocker — **schema v6 `listing_detail.fields_json` now stores exactly that** for hydrated listings, so the stated blocker is half gone |
| A4 | Final MAXIMAL certification round | M | Three lenses, two consecutive clean rounds, against a frozen commit |

### Bucket B — blocked on four inputs, each supplying something no default can invent

| # | Input needed | Unlocks | Size |
|---|---|---|---|
| B1 | IMAP credentials **and one real portal alert email** | The first `email_alert` portal, and with it all eleven Tier B portals — spec milestone 6 | M |
| B2 | `plafonds de ressources` figures, **both halves** (LLI *and* PLAI/PLUS/PLS, per zone and household, with their year) | Classifier tier 4. The rung, the injectable table and a guard test are already wired; `PlafondBands` ships empty by policy | S |
| B3 | IDFM/PRIM API key | `Enrich/transit` + `Enrich/geo` — the only spec layer with no code at all | M |
| B4 | An authenticated DevTools cURL capture of AL'in | A4 AL'in, the ONLY route to the Action Logement ESH stock (A5, A6 and A8 all dead-end there) | M |

**Hard rule 1 is why B2 and B4 are inputs rather than tasks.** An endpoint or a plafond figure
written from memory is forbidden here, and a plausible invented number is worse than an absent tier
because it fails silently in the direction of a wrong verdict.

## Sequence

1. **Bucket A, in order A1 → A2 → A3.** Each is independently shippable; commit and push each on its
   own so a red build names one change.
2. **Ask for the Bucket B inputs once**, as a single question with all four named — not four
   questions spread over four sessions.
3. **A4 last, against a frozen commit**, covering everything that landed. A round run on a moving
   tree cannot count toward the two-clean requirement, and two panels for one milestone is the waste
   that rule exists to prevent.

## Standing constraints that govern every item here

- **§1 is not negotiable.** No item below may add a config key, flag, env var or default that can
  re-enable `PLAI`/`PLUS`/`PLS`/`ANRU`/`ANAH`. A2 and A3 both touch the digest, which is §1's only
  landing zone — a `reclassify` that can flip a verdict is a §1 surface, not a convenience command.
- **TDD, and the test must be seen red for the stated reason** before the implementation exists.
- **The sabotage ledger must gain a case for any new guarantee**, and a green suite is not evidence
  the guarantee is watched.
- Conventional commits; `master` only; plain `git push`; no `Co-Authored-By`, no `Claude-Session`.

## Decisions Log

- [2026-08-23 17:30] AGREED: finish everything remaining EXCEPT `src/phorj/`, which is not ready and is out of scope for this plan.
- [2026-08-23 17:30] AGREED: the work splits into Bucket A (no inputs, proceeds autonomously) and Bucket B (four developer-supplied inputs), and Bucket A runs first precisely because it needs nobody.
- [2026-08-23 17:30] AGREED: the four Bucket B inputs are asked for ONCE, together, rather than discovered one per session.
- [2026-08-23 17:30] NOTED: `scout reclassify`'s recorded blocker is half retired — schema v6's `listing_detail.fields_json` persists the raw fields the stub says `listings` does not keep. Verify before sizing A3.
- [2026-08-23 22:10] CORRECTED, and it reverses the NOTED entry above: **`scout reclassify`'s blocker is NOT half retired — it is intact for every row.** `listing_detail.fields_json` holds only what a `detail_map` SELECTED, on 2 of 4 sources, subject to `detail_budget_per_pass`. The listing's own CARD fields — what `classify()` and `judge()` actually read — are persisted nowhere. The earlier note read a table name and inferred its contents.
- [2026-08-23 22:10] AGREED: that makes A3 a **§1 surface, not an unfinished feature**. Re-running the classifier on LESS evidence than the original saw can flip `UNKNOWN` → `LLI` → notify: a card whose field says `PLS` while its title says *logement intermédiaire* classifies `UNKNOWN` today by conflict, and re-run on the title alone it becomes a match. The feature meant to reduce misses would introduce the one breach §1 forbids.
- [2026-08-23 22:10] AGREED: the invariant that kills it is **reclassify runs on evidence ⊇ original, never ⊂**. Three mechanics carry it: schema v7 persists the RawListing snapshot the classifier consumed (never the pre-map payload, whose re-reading would depend on mapper code drift); pre-v7 rows are **NOT backfilled**, matching the precedent set by `tenure` and `group_key` — there is no honest value to write; and reclassify **SKIPS an evidence-less row, loudly counted**, never degrading to title-only. Merging `listing_detail.fields_json` on top at reclassify time is safe because evidence only GROWS, and a conflict pushes toward `UNKNOWN`, which is the fail-closed direction.
- [2026-08-23 22:10] AGREED: A2's automatic half is **already built and correct** (`Pipeline.php` implements Q34's new-since-last-successful-emission, mark-only-after-confirm and retry-next-run). What is missing is only the on-demand command, and it must read the STORE rather than the last run — the pipeline's retry works only while the listing stays published, so a digest entry whose ad is delisted between passes is silently lost today.
- [2026-08-23 22:10] AGREED: build order is **A1 → v7 → A2 → A3 → A4**, one commit each. v7 is not a new decision — Q35 already ruled *"using the persisted raw fields"* and the stub already named the storage as the blocker.
- [2026-08-23 19:38] AGREED (developer, asked once as this plan's § Sequence step 2 prescribes): the Bucket B inputs to gather are **B1 (IMAP credentials + one real portal alert email) and B2 (the `plafonds` figures, both halves, per zone and household, with their year)**. B3 and B4 are not dropped — they stay recorded above for a later session. The recommendation given was B1 first because it unlocks all eleven Tier B portals at once and with them the `LIBRE` track, which is the largest pool of listings in the tree and the one hard rule 4 names as the PRIMARY path; B2 was paired with it purely because it is the cheapest input to supply and gates classifier tier 4.
- [2026-08-23 19:38] NOTED: the three entries above are stamped `22:10` on a day whose clock read `19:38` when this line was written, so their timestamps are ahead of real time and cannot be read as an ordering. They are the prior session's own engineering conclusions logged as AGREED, not developer rulings — sound, fail-closed and followed here, but a later session may refine their mechanics without that counting as reversing a ruling. This is the failure the memory note *"a stuck session can log its own recommendation as a ruling"* warns about, caught rather than inherited.
- [2026-08-23 23:10] BUILT: **A2 `scout digest`** (`47cdfb8`). It reads the STORE rather than the pass, per the 22:10 entry. One rule was added while building it and is worth carrying: **a row with no snapshot is announced, never skipped.** Every row in the standing backlog predates v7, so skipping evidence-less rows would skip exactly the backlog the command was ruled to rescue, while reporting *"aucune annonce en attente"*. That is deliberately NOT reclassify's rule — digest ANNOUNCES a verdict already formed, reclassify FORMS one, and only the second can promote a listing into a match.
- [2026-08-23 23:10] FIXED, found by the test and not by review: `Formatter::headline()` printed `commune inconnue` while discarding a real title, so every rescued pre-v7 row — which holds a title and no commune — announced itself unidentifiably. **A placeholder must not outrank a stated fact.** The counterweight is asserted too: In'li ships no title, so the placeholder survives when there genuinely is nothing.
- [2026-08-23 23:40] REFINED, and it narrows the 22:10 mechanics rather than reversing them (the 19:38 NOTED entry licenses exactly this): **`reclassify` runs on the v7 snapshot ALONE and does NOT merge `listing_detail.fields_json` on top.** The 22:10 entry called that merge safe because evidence only grows; measured against `Pipeline.php:124-136`, it also buys nothing — every pass rewrites the snapshot with the member exactly as the classifier consumed it, AFTER any detail merge, so a page fetched in pass N is already inside the snapshot written in pass N. What the merge would add is a re-mapping step, making a stored verdict depend on `ListingMapper` code and a `detail_map` that have since changed — precisely the drift the snapshot column exists to escape. `evidence ⊇ original` still holds, with equality.
- [2026-08-23 23:40] DEVIATION, recorded rather than left silent: **`--since` is REFUSED, not implemented.** Q35 names it, and its staleness mechanism is a classifier version stored with the verdict — a column that does not exist. Answering `--since` against `last_seen_at` would substitute a different mechanism for the ruled one while looking like it had been honoured. Re-running the whole `UNKNOWN` bin costs seconds post-A1, so nothing is lost; the refusal says why, and the flag is out of `help`. Reversing this means adding the version column (a schema bump) and then implementing the flag against it.
- [2026-08-23 23:40] BUILT: **A3 `scout reclassify`**. Re-JUDGES rather than merely re-classifying, because Q35's promotion test is on `Outcome` and only the criteria engine produces one — so it runs against TODAY's criteria, and a row whose tenure now resolves cleanly can still record `REJECT` against a ceiling lowered since it was stored. Notifies DIGEST → MATCH only; a demotion is recorded silently. An unjudged dedup member gets a verdict and NO outcome, preserving the distinction between *never judged* and *judged and rejected*. A vanished source falls back to `mixedTenure: true` with no default. A damaged snapshot is loud AND scoped — one bad row must not void the run, which is the blast-radius mistake detail hydration already made once.
- [2026-08-23 23:55] AGREED (developer, asked at the milestone boundary as § Sequence step 3 prescribes): **A4 runs at MAXIMAL** — all three lenses, two consecutive fully-clean rounds, any finding resets the counter, cap 5 → then ask. Frozen at `77a7567`. The recommendation given was MAXIMAL because `reclassify` is a §1 surface: it can promote a stored verdict into a notification, and no passing test proves that safe by itself.

## A4 — the MAXIMAL round

**Round 1 (2026-08-24, frozen at `e41240c`): FINDINGS — 15 across three lenses, 3 of them P0.**
Counter reset to zero. All fixed and pushed in five commits (`feb416d`, `299b817`, `a7984a1`,
`47b5295`, plus this entry). What the round bought, in the order it matters:

- [2026-08-24] **THE GATE ITSELF WAS BROKEN, AND HAD BEEN FOR A MONTH.** The sabotage ledger's
  scratch copy omitted `.env.example`, so `DotEnvTest` failed in every scratch run; the detection
  assertion is `Failures: [1-9]` and one failure satisfies it. Every case reported `ok` whether or
  not the suite noticed anything, `fail` could never increment, the nightly stayed green — and per
  CLAUDE.md a green nightly CLOSES open ledger issues, so it was retracting real alarms too. **Every
  "verified red individually" claim made between 2026-08-22 and now, including tonight's twelve, was
  worthless.** The ledger now runs a SECOND baseline over an unsabotaged scratch copy;
  `tests/test-sabotage-baseline.sh` is the sabotage test for that guard and pins the two copy lists
  together. With the harness honest, 3 in-range cases were genuinely undetected.
- [2026-08-24] **`evidence ⊇ original` was FALSE for as long as schema v7 existed.**
  `ListingSnapshot::decode()` kept a field only `if (is_scalar())`, dropping exactly the values the
  classifier raises its tier-1 doubt on. Driven through the real CLI: `UNKNOWN`/`DIGEST` →
  `LLI`/`MATCH` → pushed. The reflection guard could not catch it — it checks that every constructor
  PARAMETER is encoded, and `fields` was; it was the value TYPE nothing exercised.
  `TenureSnapshotEvidenceTest` now states the invariant as a test: classify twice, once through the
  round trip, require the same verdict.
- [2026-08-24] **`reclassify` wrote before it sent.** A promoted row leaves `staleVerdicts()` AND
  `pendingDigest()` at once and there is no third selector, so a failed send left a MATCH nobody was
  told about and nothing could reach — while the warning promised a retry. Every write now happens
  after the channel confirms; the notifier is built before the loop so a deploy with no channel
  refuses instead of consuming the backlog. `REJECT → MATCH` is announced too.
- [2026-08-24] **One unreadable listing took the whole pass with it**, twice over (the snapshot
  encoder, and `Criteria::excludedBy()` one loop later). Both are now scoped; the verdict is stored
  without a snapshot and the pass says so.
- [2026-08-24] **CORRECTED: a true rule with an invented cause.** *"Every row in the standing
  backlog predates v7, so skipping evidence-less rows would skip the backlog"* — the premise fails
  the other way. `outcome` is a v7 column too and is not backfilled, so a pre-v7 row has
  `outcome = NULL` and `pendingDigest()` never returns it. Widening the query is REFUSED: nothing
  stored tells a pre-v7 digest apart from a pre-v7 rejection, and selecting on tenure would put
  rejected listings into §1's landing zone. The rule is live for an unrelated reason — an
  unencodable listing now produces exactly that row shape.
- [2026-08-24] **Q34 is PARTIALLY BUILT and now says so.** `digest_hour` is parsed, printed by
  `doctor`, and read by no scheduler, so on a day with nothing new no rollup is emitted. `doctor`
  claimed `digest à 8h`; it now states the cadence that runs and names the gap. Route to closing it
  recorded in Q35's neighbour.
- [2026-08-24] **The offline tripwire missed `NtfyChannel`**, which drives libcurl directly. Moved
  to `Core\Offline` so one implementation serves both paths. Loopback stays allowed, with its own
  test and sabotage case.
- [2026-08-24] NOTED, and worth more than the fix it came with: **a mutation that does not mutate
  makes a verdict about itself.** `catch (MalformedText)` narrowed to `catch (\RuntimeException)`
  applied cleanly, parsed, changed the file, and was a no-op — `MalformedText extends
  RuntimeException`. The ledger reported `undetected` when nothing was.
