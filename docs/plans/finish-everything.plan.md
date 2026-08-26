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
- [2026-08-23 23:10] BUILT: **A2 `scout digest`** (`47cdfb8`). It reads the STORE rather than the pass, per the 22:10 entry. One rule was added while building it and is worth carrying: **a row with no snapshot is announced, never skipped.** ~~Every row in the standing backlog predates v7~~ — **that reason is REFUTED; see the 2026-08-24 correction below. The rule stands, on a different reason:** `pendingDigest()` filters on `outcome`, itself a v7 column that is not backfilled, so a genuine pre-v7 row has `outcome = NULL` and is never returned at all. A snapshot-less row in that backlog is therefore a listing whose own payload could not be ENCODED — a live source fault, not an old row — and skipping it would skip exactly what the command exists to surface, while reporting *"aucune annonce en attente"*. That is deliberately NOT reclassify's rule — digest ANNOUNCES a verdict already formed, reclassify FORMS one, and only the second can promote a listing into a match.
- [2026-08-23 23:10] FIXED, found by the test and not by review: `Formatter::headline()` printed `commune inconnue` while discarding a real title, so every rescued pre-v7 row — which holds a title and no commune — announced itself unidentifiably. **A placeholder must not outrank a stated fact.** The counterweight is asserted too: In'li ships no title, so the placeholder survives when there genuinely is nothing. (The phrase *"every rescued pre-v7 row"* above is the same refuted premise — the rows this actually rescues are encode failures. The fix and its test are unaffected: what they are about is a placeholder beating a real title, whatever put the row there.)
- [2026-08-23 23:40] REFINED, and it narrows the 22:10 mechanics rather than reversing them (the 19:38 NOTED entry licenses exactly this): **`reclassify` runs on the v7 snapshot ALONE and does NOT merge `listing_detail.fields_json` on top.** The 22:10 entry called that merge safe because evidence only grows; measured against `Pipeline.php:124-136`, it also buys nothing — every pass rewrites the snapshot with the member exactly as the classifier consumed it, AFTER any detail merge, so a page fetched in pass N is already inside the snapshot written in pass N. What the merge would add is a re-mapping step, making a stored verdict depend on `ListingMapper` code and a `detail_map` that have since changed — precisely the drift the snapshot column exists to escape. `evidence ⊇ original` still holds, with equality.
- [2026-08-23 23:40] DEVIATION, recorded rather than left silent: **`--since` is REFUSED, not implemented.** Q35 names it, and its staleness mechanism is a classifier version stored with the verdict — a column that does not exist. Answering `--since` against `last_seen_at` would substitute a different mechanism for the ruled one while looking like it had been honoured. Re-running the whole `UNKNOWN` bin costs seconds post-A1, so nothing is lost; the refusal says why, and the flag is out of `help`. Reversing this means adding the version column (a schema bump) and then implementing the flag against it.
- [2026-08-23 23:40] BUILT: **A3 `scout reclassify`**. Re-JUDGES rather than merely re-classifying, because Q35's promotion test is on `Outcome` and only the criteria engine produces one — so it runs against TODAY's criteria, and a row whose tenure now resolves cleanly can still record `REJECT` against a ceiling lowered since it was stored. Notifies DIGEST → MATCH only; a demotion is recorded silently. An unjudged dedup member gets a verdict and NO outcome, preserving the distinction between *never judged* and *judged and rejected*. A vanished source falls back to `mixedTenure: true` with no default. A damaged snapshot is loud AND scoped — one bad row must not void the run, which is the blast-radius mistake detail hydration already made once.
- [2026-08-23 23:55] AGREED (developer, asked at the milestone boundary as § Sequence step 3 prescribes): **A4 runs at MAXIMAL** — all three lenses, two consecutive fully-clean rounds, any finding resets the counter, cap 5 → then ask. Frozen at `77a7567`. The recommendation given was MAXIMAL because `reclassify` is a §1 surface: it can promote a stored verdict into a notification, and no passing test proves that safe by itself.

- [2026-08-24 23:30] **P0, round 7 lens B: `console` counted as a delivered notification.** Both
  `Notifier` and `ConsoleChannel` said in prose that it does not; the constructor never implemented
  it. `ConsoleChannel::check()` returns `null`, so console landed in `$usable`, and `delivered()`
  asked whether fewer channels FAILED than were usable — so one print to a container log satisfied
  every "did it reach the user" gate in the tree: `markNotified()`, the 24 h alert cooldown, the
  heartbeat marker and `test-notify`'s exit code. A transient ntfy outage therefore announced a flat
  to a log, wrote `notified_as = 'MATCH'`, and suppressed it permanently once the network returned.
  The sting is that `RunResult::notified` was added EARLIER THE SAME SESSION as the fix for a number
  that read healthy while delivery was broken; it counted console prints.
- [2026-08-24 23:30] AGREED: **console-only is NOT fatal at startup — the fix is scoped to
  `delivered()`.** Refusing was tried first and reverted: it broke `NotifyPolicy`'s shipped default
  (`['console']`), broke the `run --once` demonstrability README and CLAUDE.md both assert, and
  contradicted `hasRemoteChannel()` / `doctor`'s `console seulement`, which have treated console-only
  as a running state since Q28. The harm chain — console print → `markNotified()` → permanent
  suppression — is fully closed by `delivered()` alone, and the deployment case that motivated the
  refusal is already caught by `test-notify`, which now exits 1 on a console-only config. **The
  one-line reversal is making `run --watch` refuse when `hasRemoteChannel()` is false** — a
  verb-split, if a headless deployment ever needs a harder gate than a warning.
  **Stated cost:** a console-only run marks nothing notified, so it re-announces every listing every
  pass and never writes the heartbeat marker, which means the beat fires each pass. That is the
  documented one-beat-too-many bias, and it is loud rather than silent. No action needed.
- [2026-08-24 23:30] **Two existing assertions were CORRECTED, not weakened, and the evidence is
  here** because `CLAUDE.md` requires it. `NotifyTest::testConsoleAloneIsNotARemoteChannel` asserted
  `assertNull($notifier->fatalProblem())` alongside its real subject, and
  `testOneChannelFailingDoesNotPreventTheOtherFromDelivering` asserted `delivered()` was TRUE with a
  failing ntfy and a working console. That second one IS the P0, pinned as correct behaviour — which
  is why six rounds of a green suite said nothing. Both now use a genuinely remote survivor. Four CLI
  test classes ran console-only for the same reason and now get `email` over the file transport
  (`SMTP_TRANSPORT=file`), so they exercise a real delivery path instead of a log write.
- [2026-08-24 23:30] NOTED: `test-notify`'s test read the REPO root, whose channel list comes from
  the gitignored `config/criteria.local.json`. It therefore passed here and would have gone red in
  CI. Verified empirically by hiding that file and re-running the whole suite — green both ways now.

### Round 7 (2026-08-24, frozen at `a6c20ec`): 23 FINDINGS — 1 P0, 7 P1

Sixth reset. Every finding is the same class the standing conclusion names, and the P0 is its
purest instance yet: **a rule stated in TWO docblocks that the constructor never implemented.**

- [2026-08-24] **P0 — `console` counted as a delivered notification.** Recorded above in its own
  entry. The detail worth keeping here is that `RunResult::notified` was introduced EARLIER THE
  SAME SESSION as the fix for a number that read healthy while delivery was broken — and it counted
  console prints. A fix landing on top of the defect it was fixing.
- [2026-08-24] **Seven P1s, each proved by reproducing the reviewer's own mutation.** The surface
  matrix could not grow (its expansion guard's second clause was always false, so the §1 structural
  control eight rounds produced was inert); group-scoped suppression was forbidden by ruling and
  guarded by nothing, on BOTH delivery paths; two `isLoopback` implementations disagreed, the
  private one admitting `mailhog`/`mailpit` so `SMTP_SECURITY=none` put AUTH LOGIN on a compose
  network in the clear; `Offline` — the single funnel for all five egress points — had no test of
  its own, so a substring search passed; `Dedup`'s empty-commune clause, which this session's own
  `communeKey()` change made load-bearing; the beat's own failure could replace the pass's
  diagnosis; and the pipeline's digest remainder was the only one of three caps whose operator line
  could be deleted silently.
- [2026-08-24] **`Offline`'s new suite found a divergence nobody reported**: `parse_url('//::1')`
  yields the host `:`, so a bare IPv6 loopback was refused while `isLoopbackHost('::1')` said true.
  Fail-closed, never a leak — and the same two-predicates-disagreeing shape as the finding it was
  written for. That is the argument for testing a predicate rather than only its callers.
- [2026-08-24] **The Q34 gate now has a test that NAMES it.** `the digest re-emits everything on
  every pass (Q34)` was the one case the nightly ledger found undetected at `9591545`, and it is
  detected at HEAD — but nothing this session targeted it. The cover was INCIDENTAL, contributed by
  tests written for the digest cap and the per-path counters, and incidental cover evaporates when
  those tests change for their own reasons. Named directly now.
- [2026-08-24] **`scout replay` was a THREE-WAY disagreement** — README and spec said
  `<fixture>`, the code is an alias of `dump` taking a source NAME, and `scout help` did not list
  the verb at all. A documented verb absent from `help` is how a drift like that survives. Now
  listed, documented as it behaves, and the unbuilt half (replaying an arbitrary fixture file
  through a network source's field map) recorded as outstanding in the spec rather than dropped.
- [2026-08-24] **Two counts corrected here because history is immutable.** `a6c20ec` says
  "the full ~416-case ledger" and `42cd9e1` says "412 expressions / ~412-case ledger": 416 was the
  EXPRESSION count and cases were 412. Expressions and cases are different numbers — a case may
  carry several expressions, which is exactly why `test-sabotage-applies.sh` checks them
  individually. Today: 424 cases, 428 expressions.
- [2026-08-24] **A plan sentence cited a ruling that existed nowhere.**
  `milestone-1-pipeline.plan.md:1697` said the survivor-only marking was "the SAME trade already
  documented for matches"; `git grep` found that phrase citing only itself, with no comment at the
  match-marking site and no test either way. Same shape as the "predates schema v7" sentence this
  project has now corrected six times. Both paths are pinned rather than cross-referenced now.

### Round 8 (2026-08-25, frozen at `896fde5`): 23 FINDINGS — 1 P0, 3 P1. Seventh reset.

**The P0 was created by the round-7 fix, and it is the same defect one door along.** Round 7 found
`console` satisfying every "did it reach the user" gate and filtered it out BY NAME. `email` over
`SMTP_TRANSPORT=file` writes an `.eml` into a directory `compose.yaml` does not mount — the
container destroys it on rebuild — and it is not called `console`, so it voted: `hasRemoteChannel()`
true, the new startup warning suppressed, `doctor` reporting a remote channel, `markNotified(...,
'MATCH')` written permanently, the heartbeat marker written, and **`test-notify` exiting 0 for a
message that went to a file**. Worse, the fix's own tests pinned it: four CLI classes had been moved
to file-transport email AS their remote channel, so any correct fix broke them.

- [2026-08-25] AGREED: **a delivery is a CAPABILITY, not a name.** `Channel::reachesRecipient()` is
  on the interface; `MailTransport` carries it too and `EmailChannel` delegates, because the answer
  is a property of the CONFIGURATION (`email` counts over SMTP and sendmail, not over file). The
  name filter and its constant are DELETED rather than kept alongside — two mechanisms answering one
  question is the shape that produced both this P0 and the `isLoopback` P1 the same round.
- [2026-08-25] AGREED: **the test seam is an injected double, not a configured file transport.**
  `Scout` takes an optional `Notifier`, the same kind of seam as its `HttpClient`. No offline
  CONFIGURATION can play the part of a delivering channel, and reaching for the nearest thing that
  looked like one is what made sixteen assertions pass for the wrong reason. Tests about channel
  BUILDING must not inject, and that is stated where the seam is declared.
- [2026-08-25] **`EmailChannel::describe()` was documented "For `doctor`" and called by nothing** —
  it was not even on the `Channel` interface, so `doctor` could not have called it polymorphically.
  It is the one diagnostic that would have shown a file transport standing in for a real channel.
  `doctor` now lists every channel, what it is, and whether it counts.
- [2026-08-25] **Round 7's SMTP narrowing replaced a refusal with NOTHING.** Gating
  `SMTP_SECURITY=none` on the credential rather than the host is right — with no `SMTP_USER` there
  is no credential to expose — but the MESSAGE still crosses the internet in clear and no surface
  said so, while `.env.example` still promised a blanket refusal. `describe()` now carries
  `⚠ EN CLAIR vers un hôte distant`, asserted in both directions.
- [2026-08-25] **`Prose::elevator()`'s trailing-denial reader closed a proper subset of its class,**
  found independently by two lenses: it required the denial to END the phrase, so `non renseigné`,
  `non communiqué` and `non disponible` — the commonest French "not stated" values — fell through
  to `true`. Replaced by a STRUCTURAL rule: an adjacent trailing denial is never `true`; bare
  terminal → `false`, `non <word>` → `null`. Vocabulary would have been the wrong fix, since the
  list is open and `non conforme` is not in it. **This flips round 7's `non conforme → true` pin to
  `null`**, and that pin was justified by reasoning rather than a captured payload while two lenses
  showed the design it protected minting wrong `true`s — which is the evidence the correction rule
  requires.
- [2026-08-25] **A claim that the window was load-bearing turned out to be FALSE, and is recorded
  rather than pinned.** `LIFT_TRAILING_WINDOW` widened 16 → 200 changes nothing: the adjacency rule
  already excludes anything with a word in it. Writing a case to make it look load-bearing would be
  the dead-safety-code mistake this repo has removed twice.
- [2026-08-25] **The two `isLoopback` predicates still disagreed.** Round 7 bracketed bare IPv6
  inside `refusalForHost()` — a workaround at one call site — so `isLoopbackHost('::1')` said true
  while `isLoopback('//::1')` said false, and the equivalence test written to catch exactly that was
  given the BRACKETED form. Normalisation now lives at the single place the parse happens, the test
  carries bare `::1`, and a host carrying credentials (`user:pw@localhost`, which `parse_url` reads
  as userinfo plus a loopback host) fails closed.
- [2026-08-25] **`Pinned in the ledger now.` was false when it was written**, in the commit that
  fixed eight overclaimed guarantees. The ledger only mutates `src/`, never test files, so it could
  not have acquired a case for the surface-matrix guard by accident. The real pin is a src-side case
  that adds a constructor parameter to `RawListing`.
- [2026-08-25] Also: the startup warning lived only on `run`, while `digest` and `reclassify` have
  the identical property and printed a retry promise that is unconditionally false in that state;
  `delivered()`'s by-name rule was unpinned (the discriminating case is a FAILING channel that does
  not count); hard rule 4's opt-in read `$_SERVER['argv']`, a different source of truth from every
  other flag, so its PERMITTING branch was unreachable through the test seam and all three existing
  cases asserted refusal; a docblock sat above an unrelated test; `CLAUDE.md` cited `Scout.php:466`
  for a line that had moved twice (cited by SYMBOL now); and the tests leaked temp roots — **21446
  of them on this machine** — because `@rmdir` cannot remove a non-empty tree and fails silently.
- [2026-08-25] **Two of round 7's three commit messages miscounted their own ledger additions**
  (`bbc98d8` said "5 new + 2 retargeted" for 6 new + 1; `896fde5` said "3 new + 3 retargeted, 4/4"
  where 3+3 contradicts its own 4/4). A reviewer re-ran all 16 and got 16/16 detected, so the
  coverage was real and the evidence lines were not. Corrected here because history is immutable —
  the second time in two rounds, so the rule is now explicit: **count new and retargeted cases from
  `git diff` of the ledger file, never from memory.**

### A4 IS CLOSED — developer ruling, 2026-08-25, with the residual stated

**Asked at the eighth reset, and the answer was `Redeploy and stop certifying`.** MAXIMAL wants two
consecutive fully-clean rounds; it got eight rounds, eight resets, zero clean. The data put to the
developer, and the reason the ruling is sound rather than fatigue:

- **The P0s changed KIND.** Round 4's were §1 breaches — social housing reaching a notification.
  Rounds 7 and 8 were delivery accounting, and both were in code written during this session:
  round 8's P0 was CREATED by round 7's fix, one door along from the hole it closed.
- **The reviewed surface is now mostly session-generated churn**, not the milestone work the round
  was convened for. Each round reviews the previous round's repairs, so the loop was not converging
  on the code — it was converging on itself.

**THE RESIDUAL, stated plainly rather than buried:** the round-8 fix is UNREVIEWED. It is the
largest single change of the sequence — a new method on `Channel` and on `MailTransport` implemented
across eight classes, a new `Notifier` seam in `Scout`'s constructor, sixteen CLI tests moved onto an
injected double, and a structural rewrite of `Prose::elevator()`. It is covered by 1879 green tests
and 6/6 targeted sabotage detections, and by nothing adversarial. **If a future session wants one
more round, that commit — `f6dfa43` — is the thing to point it at.**

Bucket A continues. What is NOT closed by this ruling: the deploy, and the two Bucket B inputs.

## A4 — the MAXIMAL round

**Round 1 (2026-08-24, frozen at `e41240c`): FINDINGS — 15 across three lenses, 3 of them P0.**
Counter reset to zero. All fixed and pushed in five commits (`feb416d`, `299b817`, `a7984a1`,
`47b5295`, plus this entry). What the round bought, in the order it matters:

- [2026-08-24] **THE GATE ITSELF WAS BROKEN** (and this entry first said "for a month" — see the
  round-3 correction below; the real window was ~27 hours, and the magnitude was invented).** The sabotage ledger's
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
  **CLOSED [2026-08-26, `8c24cb2`]** — the daily floor is built and `doctor` states it as running.
  Full reasoning, the silent-empty-day ruling and the corrected zone story:
  `docs/plans/q34-digest-daily-floor.plan.md` and `docs/OPEN-QUESTIONS.md` § Q34.
- [2026-08-24] **The offline tripwire missed `NtfyChannel`**, which drives libcurl directly. Moved
  to `Core\Offline` so one implementation serves both paths. Loopback stays allowed, with its own
  test and sabotage case.
- [2026-08-24] NOTED, and worth more than the fix it came with: **a mutation that does not mutate
  makes a verdict about itself.** `catch (MalformedText)` narrowed to `catch (\RuntimeException)`
  applied cleanly, parsed, changed the file, and was a no-op — `MalformedText extends
  RuntimeException`. The ledger reported `undetected` when nothing was.

**Round 2 (2026-08-24, frozen at `f51b6d5`): FINDINGS — 17 across three lenses, 1 P0.** Counter reset
again. Round 2's job was to refute ROUND 1's fixes, and it did, twice in the same shape:

- [2026-08-24] **The malformed-text fix covered two surfaces of three.** `Criteria::communeKey()` was
  left unguarded and is reached twice per pass — `rankOf()` inside `score()`, and
  `Dedup::duplicateReason()` inside `cluster()` — neither inside the per-source try/catch. One
  `&#039;` or one cp1252 byte in a COMMUNE still aborted the pass, `ok = 1`, health green, nothing
  notified; the Dedup one before anything was stored. Accented commune names are ubiquitous here.
- [2026-08-24] **And that fix's own first draft repeated the class**: both folds in one `try`
  disabled `exclude_title_patterns` on a READABLE title whenever the description was unfoldable, and
  `Text` refuses any undecoded HTML entity — commoner in a scraped payload than cp1252.
- [2026-08-24] **`Core\Offline` claimed to refuse "every outbound request" while guarding two of
  FOUR.** SMTP, IMAP and sendmail all escaped; IMAP is the PRIMARY ingestion path under hard rule 4.
  The ntfy refusal also leaked the topic whenever `rawurlencode` touched it, or when it was under
  four characters — `Redact` cannot mask either. It names the SERVER now: not putting a secret in
  the string beats masking it afterwards.
- [2026-08-24] **The heartbeat was inside the pass's try/catch** while a comment two lines up said it
  was "outside by construction" — so a throwing pass silenced the one signal that says the watcher
  is alive, next to a defect that made exactly that reachable.
- [2026-08-24] **Three operator-facing causes were wrong**, all the same error the round-1 commit
  message had just named: `digest` blamed the migration for the one cause impossible there,
  `reclassify` named only the migration where both causes are reachable, and `unencodable` said
  *texte illisible* when the encoder refuses three things — one of which yields a NOTIFIED MATCH
  with perfectly clean prose.
- [2026-08-24] **The test for the guard that failed silently was executed by nothing** —
  no CI step, no `CLAUDE.md` entry, so `test-ci-workflow.sh` could never pin it. That loop was
  self-sealing.
- [2026-08-24] **All 57 cases added since the harness broke were re-run through the corrected gate.**
  55 detected; 2 were real gaps the ~27 hours of unconditional `ok` had hidden — `Prose::floor`'s `\b`
  (`en 4 étages` is a triplex, not the 4th floor) and the hydration cache test, which asserted a
  description was `!== ''`, something the CARD already satisfies.

**Round 3 (2026-08-24, frozen at `9591545`): FINDINGS — 19 across three lenses.** Counter reset
again. Landed as `cc64160` and `5626488`.

- [2026-08-24] **The `finally` that was supposed to make the beat unskippable made the channel lie.**
  `++$passes` sat inside the `try`, so a failing pass beat with `$passes === 0`, which renders as
  *"démarrage de la surveillance"* — a beat announcing a healthy start, sent because the pass had
  just failed. Found independently by two lenses.
- [2026-08-24] **Two commune tests never touched the guard they were written for.** The `listing()`
  helper hardcoded `commune`, so both passed input that never reached a `RawListing` — the SECOND
  time this helper trap was hit in one session, the first being `title`. A test asserting a
  guarantee about input it does not supply is a guarantee nobody holds.
- [2026-08-24] **"For a month" was invented, in the entries warning about invented magnitudes.**
  The real window was ~27 hours (`4403e9d` 2026-08-22 20:43 → `feb416d` 2026-08-23 23:31), with at
  most one nightly inside it. Eight sites were corrected — there were **nine**; the ninth is
  corrected above, by round 4.
- [2026-08-24] **A red nightly named a run URL and nothing else.** No tally, no case names, no
  reproduce command, and the job logs need admin rights. An alert that cannot be acted on is the
  retraction failure read forwards.

**Round 4 (2026-08-24, frozen at `5626488`): FINDINGS — 10 across three lenses, 2 of them P0.**
Counter reset a third time.

- [2026-08-24] **§1 was judged on the SURVIVOR of a dedup cluster alone (P0).** Every member was
  classified and each verdict stored, and only the survivor was judged — so the same flat published
  on a pure-LLI portal and on a mixed one stating `PLS` was a MATCH or a REJECT depending on which
  source was polled first, and `Pacer` shuffles that order every pass. The store then held `PLS` at
  confidence 99 under the same `group_key` as the row it had just pushed as a 75/100 match. Fixed:
  an excluded member vetoes the cluster; an UNDETERMINED one deliberately does not, and that
  counterweight is its own test.
- [2026-08-24] **A DIGEST → MATCH promotion was announced by nothing (P0).** A delivered digest sets
  `notified_at`, the match path read that as "already told about this listing", and the same pass
  overwrote `tenure` and `outcome` — so the row left `staleVerdicts()` and `pendingDigest()` in the
  same statement that suppressed its notification. `matches=1` on the pass summary, nothing sent,
  nothing able to reach it again. Cityloger's whole un-hydrated population takes this path. Fixed by
  schema **v8**'s `notified_as`; a pre-v8 row reads as MATCH so the historic backlog stays quiet.
- [2026-08-24] **The beat's "listings notified" was the literal `0` at both call sites.** The one
  number separating a producing watcher from a mute one was constant, and the test that named itself
  the Q27 contract asserted `\d+`, which matches `0`. Shape is not value.
- [2026-08-24] **`scout digest` sent the whole backlog as one all-or-nothing notification**, and each
  failure made the next attempt strictly larger — so a size-dependent rejection would harden §1's
  only landing zone into permanent undeliverability. Now capped, and the remainder is announced.
- [2026-08-24] **The pre-v7 premise, refuted in round 1, was still live in `CLAUDE.md`** — the file
  this repo declares wins on any conflict — and `RunResult`'s docblock still carried the claim
  round 3's own commit message says it fixed. Both corrected.
- [2026-08-24] **`test-sabotage-applies.sh` could not see a case rot one expression at a time.**
  It applied a case's expressions together and compared once, so this session's own `markNotified()`
  signature change silently voided half of an existing case while it still reported `ok` — found by
  the author, in the checker written to catch exactly this. Splitting a compound sed script needs
  sed's own syntax; this ledger's patterns contain semicolons.

**Round 5 (2026-08-24, frozen at `3079941`): FINDINGS — 8 across three lenses, 1 P0. THE CAP.**
Every finding but two was a refutation of a round-4 repair.

- [2026-08-24] **The cluster veto was undone by `scout reclassify` (P0).** The pipeline judges a
  cluster on its most restrictive member but stores each member's OWN tenure and OWN snapshot, so a
  vetoed survivor sits at `tenure = 'UNKNOWN'`, `outcome = 'REJECT'` — and `staleVerdicts()` selects
  on `tenure` alone. `reclassify` picked it up and re-judged it on a snapshot in which the sibling's
  `PLS` cannot appear, which is this command's own invariant read backwards. Driven end to end
  through the shipped pipeline and the shipped commands: the REJECT vanished after one run, and in
  the promotion case the row was PUSHED as a match while the store still held `PLS` under its own
  `group_key`. Fixed by `Store::groupExcludedTenure()`, checked before the classifier runs.
- [2026-08-24] **The beat's new number was wrong in the other direction.** Round 4 replaced a
  hard-coded `0` with `RunResult::matches` — which `Pipeline` increments when the engine JUDGES,
  before the already-announced gate and before `delivered()`. In steady state, the ordinary mode of
  a `--watch` deployment, every match hits `continue` and the pass sends nothing, so the beat
  claimed the full standing count, cumulatively: ~8000 "annonces notifiées" by day two having pushed
  none. It grew FASTEST when delivery was broken, since an unsent listing returns next pass and is
  re-counted. `RunResult::notified` now counts confirmed deliveries.
- [2026-08-24] **The digest cap landed on the manual drain and not on the unattended path.**
  `Pipeline`'s own digest emission was still unbounded and all-or-nothing — measured at 120 entries
  and 20.9 KB in one send, 4.4x the batch this project had just decided was safe. Capped, with the
  remainder left pending rather than dropped. `announcePromotions()` was uncapped too and is now
  bounded by the same constant.
- [2026-08-24] **Two of my own round-4 comments were false.** The `--seed` path's *"STATED COST: a
  genuine promotion inside the seeded set is never announced"* — `reclassify` announces it — and
  `DIGEST_BATCH`'s justification, which claimed 50 "clears the smallest limit this project's
  channels are documented to have" when no such limit is documented anywhere. An invented
  measurement dressed as one, in the commit that fixed an invented measurement.
- [2026-08-24] **The pre-v7 premise had a THIRD copy**, in `sabotage-check.sh` — the worst of the
  three, since that comment is where a future session reads to decide whether a red case should be
  retargeted or deleted.
- [2026-08-24] **The veto's over-rejection cost was stated nowhere.** An over-merge used to hide one
  flat and still notify the survivor; it now rejects both. §1-safe, and invisible, so it is written
  in the docblock.
- [2026-08-24] **`test-sabotage-applies.sh` was still blind to three cases** — every address-
  prefixed script (`/private function withDetail/,$ s%…%%`) failed to parse and fell back to
  whole-script comparison, silently, including the one genuinely compound case whose second command
  was therefore never checked alone. The scanner now reads addresses, the fallback announces itself,
  and the no-op self-substitution it exposed was removed.

- [2026-08-24 23:55] AGREED: **the cap is lifted for a sixth round** rather than closing A4 with a
  documented residual, narrowing to the §1 surface, or redefining the exit condition. Reasoning
  offered and accepted: round 5 added genuinely unreviewed code (`Store::groupExcludedTenure()`, the
  promotion cap, the deliveries counter), so round 6 is not a re-read of the same tree; and rounds 4
  and 5 each produced a §1 breach that a green suite said nothing about. Reverses by choosing any of
  the other three options at the next gate.

**THE CAP IS REACHED: five rounds, five resets, zero clean.** MAXIMAL requires two consecutive
fully-clean rounds and the protocol caps the loop at five, so continuing is not a decision this
session may take alone — `CLAUDE.md` § Certification ladder says to ask rather than silently
proceed.

**Round 6 (2026-08-24, frozen at `888a2d7`): FINDINGS — the same P0 from two lenses independently,
plus one P2 and one documented decision.**

- [2026-08-24] **The cluster veto was PER-PASS (P0, found independently by two lenses).** Round 5
  taught `reclassify` to read the persisted group; the pipeline — which runs unattended every
  fifteen minutes — was not taught. `clusterClassification()` scanned `$cluster['members']`, which
  is what `Dedup` clustered out of THIS pass's harvest, and `assignGroup()` returns before any
  UPDATE for a single member, so a survivor that clusters alone KEEPS the `group_key` it earned when
  the excluded sibling was present. A failed source fetch, a `--source=<name>` run, or the sibling
  delisting was enough to push the flat as a match on the next pass — and that pass overwrote the
  stored `REJECT` with `MATCH` while the survivor's own tenure resolved, putting the row outside
  `staleVerdicts()` AND `pendingDigest()`, beyond either repair command. Both lenses drove it end to
  end. The veto now reads `Store::groupExcludedTenure()`.
- [2026-08-24] **The in-pass scan then turned out to be DEAD CODE, and the ledger is what proved
  it.** `assignGroup()` runs in the recording loop, before any judging, so for every cluster of two
  or more the persisted group is already current — disabling the in-pass scan left the whole suite
  green. Removed rather than kept: dead safety code reads as a second line of defence and is not
  one. Its two sabotage cases were retargeted at guarantees that still exist (the group being
  recorded before judging, and the over-fire direction at the pipeline layer).
- [2026-08-24] **And a second sabotage case pinned nothing, for a reason I had invented one commit
  earlier.** The durable veto's synthetic `Classification` carries `confidenceBp: 100`, and the
  docblock claimed it "must clear the fail-closed floor on its own". `CriteriaEngine` branches on
  `$classification->outcome` alone — it never reads tenure or confidence — so the number decides
  nothing. Both the claim and the case are gone, with a note in the ledger so nobody re-adds it.
- [2026-08-24] **The match and rent-drop paths are the last uncapped announcements, and that is now
  a WRITTEN decision rather than an omission.** They carry the biggest measured burst in the tree
  (92 pushes in one live pass), but the digest's reasoning does not transfer: a digest is one
  all-or-nothing send whose failure re-sends a strictly larger batch, while a match is sent and
  marked per listing, so nothing compounds. Capping it would cost the thing the brief exists for.
  The reversal condition is recorded at the call site.

- [2026-08-24] **Round 6 also found five things nobody had pinned, and one number I inflated.**
  `announcePromotions()`'s cap had no test and no ledger case — deleting the slice left the whole
  suite green; each of `RunResult::notified`'s three contributors could be removed individually with
  the suite green, since the heartbeat test only asserts that SOME path counted (aggregate is not
  per-path, the same way shape was not value); the pipeline's digest cap truncated silently while
  both sibling caps named their remainder; a FOURTH live copy of the refuted "reclassify skips them
  for ever" premise sat directly above the operator line it explains; and `42cd9e1`'s message says
  "the 5 new cases 5/5 detected" when the commit adds **four** — the fifth was a retargeted case,
  not a new one. History is immutable, so it is corrected here rather than there.
- [2026-08-24] **And capping promotions inside `announcePromotions()` rebuilt the beat's own defect
  a third time**: the summary counted `$promotions` before the slice, so it said 54 while 50 were
  sent. Capped in the caller now, so both numbers describe the same set.

**Standing conclusion, and it is the session's main finding:** across all six rounds, every P0 was
*a correct rule with a reason nobody re-checked* — the invented cause, the unclaimed surface, the
overclaimed guarantee. A green suite never says a word about any of them. The rounds are not
converging on zero because each round reviews the previous round's repairs, and those repairs keep
reintroducing the same class of defect they fixed. **That is evidence about the process, not only
about the code:** the defects are getting narrower (round 4 found two §1 breaches, round 5 found one
plus five stale claims), but a rule that demands two consecutive clean rounds may not terminate
while every round is reviewing fresh work.

---

## Session of 2026-08-26 (evening): the four standing decisions, answered

The developer's instruction was *"if you have input you need from me ask them one by one and one
at a time! if not then continue to finish everything"*. Four decisions had been standing since the
private portals went live; all four were asked one at a time and answered, each after being
grounded in a measurement rather than in prose.

### Decisions Log

- [2026-08-26 20:00] AGREED (developer): **the §1 residual on the four private portals STANDS —
  `mixed_tenure` stays `false` on `seloger`, `bienici`, `leboncoin` and `pap`.** Grounded in the
  store before asking: every listing those four have ever pushed classifies `LIBRE` — 73 + 42 + 4 +
  1 = **120 of 120** — and `LIBRE` there does not mean *the ad said private market*, it means **no
  tenure signal was found and the source default was applied**. So arming the flag would send 100%
  of all four portals to the digest, which is the In'li lesson of 2026-08-23: *not §1 satisfied, it
  is the tool switched off*. What holds unchanged: an explicit `PLS`/`PLUS`/`PLAI`/`conventionné`
  on any card is still rejected at 0.90 by the tier-2 label rules, which never consult
  `mixed_tenure` — proven live by In'li's one `PLS` listing sitting in DIGEST rather than in a push.
  **Stated cost, and it is the whole residual:** a genuinely-PLS flat advertised on a commercial
  portal *without naming its financing* is pushed as a match. Narrow because PLS ads normally
  disclose their income ceilings. **Reversed by one line per source.**
- [2026-08-26 20:00] AGREED (developer): **`high_priority_score` 70 → 50.** This is the FIRST
  calibration this threshold has ever had — 70 predates commute and was not derived from anything.
- [2026-08-26 20:00] AGREED (developer): **the match path stays UNCAPPED.** Re-confirms the
  2026-08-24 written decision rather than reversing it, now against measured volume: 94 / 23 / 71 /
  104 pushes on 22, 24, 25 and 26 August. The 24th is the only day no new source went live, so
  **23/day is the quiet-day figure** and the high days are one-time backlog — three portals live in
  48 h, plus the `IMAP_MAX_MESSAGES` raise reaching five days of unread alerts. Steady state with
  all eight sources is expected 40–60/day. Left uncapped because a match is sent and marked PER
  LISTING, so nothing compounds the way an all-or-nothing digest does.
- [2026-08-26 20:00] AGREED (developer): **a VPS deploy kit is written and verified here, and
  nothing is deployed.** The watcher runs on the developer's own machine, so it stops when that box
  sleeps; the heartbeat already makes that loud rather than silent, so this is convenience, not a
  §1 or hard-rule-2 gap. Deploying needs a host, which is an input.

### The measurement that changed the second decision, and refuted two documented claims

`CLAUDE.md` said *"scores run 16–48, so `high_priority_score: 70` can never fire and the `!!` marker
is currently dead. Left alone on purpose"*. Re-judging all 256 stored v7 snapshots through the real
`CriteriaEngine`, with commute enabled from `criteria.local.json`, refutes **both halves**:

- Scores now run **0 – 70** (median 28, p90 40). Commute lifted the ceiling from 48; one listing
  actually reaches 70. The 16–48 figure is pre-commute and stale.
- **And `!!` still cannot fire — for a reason nobody had written down.** It needs score ≥ threshold
  **AND** `confidenceBp >= HIGH_PRIORITY_MIN_CONFIDENCE_BP` (80), and those two conditions are
  satisfied by **disjoint sets of listings**. The top scorers are all `conf 50` — private portals,
  whose tenure comes from the source default — while the listings that clear the confidence floor
  (CDC Habitat at `conf 99`) top out at **55**:

  ```
   70  conf 50  bienici       68  conf 50  bienici       66  conf 50  seloger
   56  conf 50  inli          55  conf 99  cdc_habitat   52  conf 99  cdc_habitat
  ```

  Confident listings: **47 of 256, running 13–55.** So at any threshold ≥ 60 the marker is
  unreachable **by construction**, not by luck. That is the finding that made 50 the answer rather
  than 55 or 60: at 50 it marks **3 of 47** confident listings (~6%), at 55 exactly one, at 60 zero.

**The confidence floor is deliberately NOT touched.** It is what stops a *"drop everything"* marker
appearing on a listing whose tenure is a guess — lowering the score threshold while leaving it in
place tightens the marker's meaning rather than loosening it.

> **A method note worth keeping.** The score is not a stored column, so the distribution was
> recovered by decoding every `evidence_json` v7 snapshot and re-judging it offline — no network, no
> live poll, and against the same evidence the original verdict was formed from. That is the cheap
> way to answer *"what would this criteria change do?"* without the `--seed` throwaway-database
> poll, and it exists only because schema v7 persists the snapshot.
