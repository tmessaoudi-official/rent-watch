# scout — UNIFIED EXECUTION PLAN (the single source of truth)

> SINGLE SOURCE OF TRUTH for all pending scout work (developer ruling, 2026-08-31).
> Supersedes every plan in docs/plans/archive/. Rulings land in this file's Decisions Log.

> **EXECUTION/REVIEW SPLIT (developer's workflow, 2026-08-31).** A DIFFERENT model executes this
> plan (bypass-permissions session); the reviewing model then certifies what was done. Two duties
> follow for the executor: (1) leave verifiable evidence for every track — paste real command
> output (test tallies, ledger results, doctor output, SQL results) into `var/claude/`
> execution notes and commit messages, never a narrative claim; (2) do not self-certify — the
> certification rounds this plan requires (Track 0 round 4, Track 1's MAXIMAL round, Step U's
> STANDARD pass, Track 4's own MAXIMAL) are run by the REVIEWING session against frozen commits,
> not by the executor declaring itself clean. The executor stops at each certification boundary
> and hands back.
>
> **HANDOFF SEQUENCE**: on approval, the planning session performs **Step U only** (install this
> plan into the repo, archive the 15 old plans, fix the citations, commit+push) and then STOPS.
> The developer switches models; the executing model starts from
> `docs/plans/scout-unified-execution.plan.md` at Track 0.

## What this document is

The developer asked (2026-08-31) for ONE unified plan that supersedes every divergent plan
document, detailed enough that **a different model with no session history can execute it without
misinterpretation**. It merges, with nothing lost:

1. `~/.claude/plans/heko-binary-scone.md` — the plan of record (advisor rounds 1–6: findings
   4,1,0,0,2,1; then a 3-lens plan-review panel, ~24 findings, all folded in).
2. `~/.claude/plans/let-s-go-through-the-fuzzy-wolf.md` — the 2026-08-31 12:40 re-verification
   delta (fresh primary evidence; 6 corrections).
3. Today's post-12:40 findings: the PAP template breakage (Track 1h, verified against the live
   store down to the exact pattern misses) and the four developer rulings received 2026-08-31
   (see Decisions Log).

**Review provenance**: the two source documents were heavily reviewed as described above; THIS
merged text was assembled 2026-08-31 and checked by `advisor()` in the assembling session. The
first executor should treat the per-track evidence lines as verified (each is reproducible
read-only) and the merged prose as one review deep.

**Executor briefing (fresh model, read first):**
- `CLAUDE.md` auto-loads and WINS on any conflict. Hard rules 1–10 and §1 (no social housing ever
  surfaced as a match) are non-negotiable. `docs/OPEN-QUESTIONS.md`, `docs/SOURCES.md`,
  `spec/PROJECT_BRIEF.md` are reference/rulings — kept, not superseded by this plan.
- `/bin/grep` on this machine is **ugrep 7.8.4**: in `SABOTAGE_FILTER`, join labels with a plain
  `|` inside double quotes — `\|` is LITERAL, matches nothing, and exits 0 (reads as a clean run;
  the script prints PARTIAL RUN — never accept that as a ledger result).
- The local PHP tracing JIT crashes long PHPUnit runs (exit 134). Run the ledger with
  `PHP_INI_SCAN_DIR="/stack/tools/phpbrew/php/php-8.5.9/var/db/cli:<dir containing opcache.jit=off>"`
  — KEEP the original scan dir or `iconv` disappears and the baseline reddens (18 errors, measured).
- Spawn reviewer subagents **UNNAMED** (a `name:` teammate's report is undeliverable here). Panel
  reviewers probe in pinned `git worktree`s, copy with `cp -a`, never symlink `vendor/`.
- Commit identity `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>`, no trailers;
  `master` only; plain `git push`. Verify `git config user.name`/`user.email` before the first commit.
- Gate state: all six gate-bypass sentinels for this project already exist on disk
  (`~/.claude/projects/-stack-projects-scout/state/*-bypass`, written 2026-08-31 00:40), and global
  permission-swap is armed. **Verify they are present; do not re-run `/gates-bypass`** — the
  predecessor plan's "Step 0" is already done.

## Verified state snapshot (2026-08-31, all read-only, reproducible)

- HEAD `b8a1687`, tree clean, deployed image `09:02:14Z` (current, not drifted).
- Milestone `b8a1687` is **mid-certification**: owed = filtered sabotage ledger (6 cases) + panel
  round 4; MAXIMAL's two-consecutive-clean bar not yet met (round 3 was clean at the prior commits).
- Rent store `state/rent-watch.sqlite3`: ~1,790 listings and growing; car store exists with the
  composed rent-store tables (`Store::SCHEMA_VERSION=12` vs `VehicleStore::SCHEMA_VERSION=1`).
- Sabotage filter for Track 0 selects **exactly 6 of 574** labels (verified by listing every
  `run_sabotage` label).
- Gmail (live): `car-watch` label = 0 messages; leboncoin's 5 car "vous propose" messages sit in
  plain INBOX (no filter routes them); AutoScout24 has sent **no real listing alert ever** (3
  messages: welcome, confirm-address, search-saved receipt — nothing per-listing).
  > **THE FIRST CLAUSE WENT STALE WITHIN THE DAY, and the way it misled is worth keeping.**
  > Re-measured 2026-08-31 evening: `car-watch/portails` is POPULATED — La Centrale twice daily,
  > Agorastore daily, AutoScout24's newsletters — and the first run of `tools/dump-eml.php`
  > searched INBOX, found neither, and printed `aucun message de <sender>`, which reads exactly
  > like a portal that has sent nothing. An alert routed to a label is ARCHIVED OUT of the inbox.
  > A mailbox census is a measurement with a folder in it; quoting one without the folder is the
  > same shape as quoting a yield without a date.

---

## Decisions Log (seeded — THIS file is now where rulings land)

> Supersedes the predecessor plan's persistence instruction to append rulings to
> `finish-everything.plan.md` / `car-domain-first-slice.plan.md` — those files are archived by
> Step U2. From now on, every ruling for pending work is appended HERE, in the same commit as the
> change it governs.

- [2026-08-31] AGREED: unification = archive + supersede — new plan at
  `docs/plans/scout-unified-execution.plan.md`; the 15 existing `docs/plans/*.plan.md` move to
  `docs/plans/archive/` with a SUPERSEDED banner each; reference docs (spec, SOURCES,
  OPEN-QUESTIONS, FILTERS, ALERT-CAPTURE, PHORJ-REQUIREMENTS, CLAUDE.md, README) are kept.
- [2026-08-31] AGREED: Track 1d brand mechanism = PENALTY (inverted), in-weights: a `brand` weight
  reserved out of the existing 100; listed brands score 0 of it, unlisted score all of it. Not
  `body_rank`'s shape (which would score listed brands higher). Details in 1d.
- [2026-09-01 12:20] AGREED: `brand_avoid` goes from 3 makes to **22** — the developer asked to
  penalise more makes ("i know those come with manufacture problems"), enumerated them, and ruled
  the full set: Stellantis' 14 marques (peugeot citroen ds opel vauxhall fiat abarth lancia alfa
  jeep dodge chrysler ram maserati) + ford and chevrolet (the developer's own, neither Stellantis)
  + the Renault-Nissan-Mitsubishi alliance (renault dacia nissan alpine mitsubishi, in because
  they share Renault mechanicals) + leapmotor (Stellantis-distributed). MECHANISM UNCHANGED — flat,
  equal, 10 of 100, still never a disqualifier. Reversed make by make in `config/car/criteria.json`.
- [2026-09-01 12:20] AGREED (same ruling, its enabling repair): an entry is a **STEM matched to a
  non-letter boundary**, not an exact string. Measured on the live store: the same marque is spelled
  `ds` by leboncoin and `ds automobiles` by autohero, and `in_array(..., true)` caught one and
  silently missed the other — a preference inert on a whole source, for 10 points of ordering, with
  nothing reading as a fault. Write the shortest unambiguous form (`alfa`, not `alfa romeo`): the
  gap runs both ways and a stem longer than the make misses just as quietly. Stated residual: a
  make written with no separator at all (`alfaromeo`) is not caught; under-matching is the safe
  direction and no source emits it.
- [2026-09-01] MEASURED, and it refutes what was predicted at the gate: widening the list makes the
  component MORE discriminating, not less. Re-judging all 160 stored snapshots through the real
  scorer — avoided median **58** vs unlisted **71** (13 points apart, against 9 for the previous
  three: 60 vs 69); 49 of 160 still clear `high_priority_score: 70`, so the marker stays reachable.
  60 % of the fleet is now penalised, which reads like a coin flip and is in fact close to the
  most discriminating split a binary component can take.
- [2026-08-31] DEFAULT APPLIED (not ruled): brand's 10 points are taken from `price` (25→20) and
  `body` (15→10) — the assembling session's allocation, not the developer's. Reversed by taking
  the 10 from different components before building 1d.
- [2026-08-31] DEFAULT APPLIED, and it DEPARTS from 1d's own body text: an UNEXTRACTED make scores
  **0 and says so**, not the full share. 1d's line ("a car with no extracted make gets the full
  share, hard rule 9") is the assembling session's elaboration — the Decisions Log entry above
  rules only the listed/unlisted split. Two reasons for the departure, both repo-native: hard rule 9
  forbids reading unknown as BELOW A MINIMUM, which is a *disqualifier*, and nothing here
  disqualifies (hard rule 8 keeps the mechanisms apart); and `VehicleScorer`'s own docblock already
  rules that every unknown component "scores 0 and SAYS so… a sparse listing ranks below a
  documented one" — the five sibling components all take that arm. Awarding the share would rank an
  EXTRACTION FAILURE as a definitely-not-Peugeot: a fact manufactured from its own absence, wearing
  an alibi, which is the defect this repo has paid for repeatedly. Both shipped car sources extract
  a make. Reversed by adding `$score += $w['brand'];` to that one arm in `VehicleScorer`.
- [2026-08-31] DEFAULT APPLIED: an EMPTY `brand_avoid` awards the share to every make, which is a
  different question and takes the opposite answer. That is not an unknown fact but a configured
  absence of preference, and withholding it would silently shrink the achievable maximum to 90 —
  while `high_priority_score` is an ABSOLUTE threshold, so the `!!` marker would become harder to
  reach for exactly the deployments that expressed no preference. Same failure shape the rent side
  measured on 2026-08-26.
- [2026-09-01 12:20] AGREED (Track 5b, F-C): Cityloger's surface selector accepts `m²` as well as
  `m2`. Not a hydration gap (61 cached details for 60 listings) and not a missing mapping — the
  regex required the ASCII unit and the source writes both. 56 of 61 rows carried no surface, so
  `min_surface_m2` could not act on 93 % of the source. The failing fixture was already committed;
  only the passing spelling was asserted.
- [2026-09-01 12:20] AGREED (Track 5b, F-D): rent `leboncoin` takes `feed_silent_days: 7`, matching
  the car side's ruling for the same portal. It does NOT silence today's verdict (the last message
  is 2026-08-26, already past 7 days) — it stops an ordinary bursty gap being called silence.
- [2026-09-01 12:20] AGREED (Track 5b, F-A): a `params` key no adapter reads is REFUSED — on
  `email_alert` sources ONLY. On `html`/`json` sources `params` IS the HTTP query string
  (`HtmlSource` does `withQuery([...params, ...extra])`), so In'li's `price_max`/`area_min`/
  `room_min` and Logirep's `ss_trnsctntp` cannot be enumerated and a blanket refusal would lock both
  polling sources out at startup. AND the cleanup was inverted: rent leboncoin's unread
  `subject_pattern` was not dead config but a documented guard that was never ARMED — rent and car
  leboncoin share sender, link host and card separator, and its own comment named the expiry as the
  day the Gmail filter is created. It is read now rather than deleted.
- [2026-09-01 12:20] AGREED (Track 5b, F-B): In'li's postcode comes from the detail page `<title>`,
  which states it beside the commune. Chosen over a commune→postcode table (needs an authoritative
  dataset, and French commune names repeat across departements while In'li lets outside IdF). It
  costs NO extra request — all 212 affected rows were already hydrated. Three earlier explanations
  were each refuted by the next query and are recorded in the commit so they are not retried.
- [2026-09-01 15:10] AGREED: the fuel weight goes 10→20 (−5 price, −5 mileage) and
  `high_priority_score` 70→73 with it. Diesel remains a PREFERENCE, never a disqualifier — the
  2026-08-30 ruling stands and diesels still appear. Measured over 269 snapshots: gap 16→25, best
  diesel 77→70. The marker had to move because it is ABSOLUTE; 78 was estimated from the
  petrol-only subset and was wrong.
- [2026-08-31] AGREED: PAP fix = patterns + health signal (Track 1h). No backfill of the 21
  null-extraction rows — stated cost, they stay null.
- [2026-08-31] AGREED: name-in-git-history = leave history; forward fix only (scrubber + re-scrub
  the two fixtures in a new commit). Rationale recorded: the same name is already the commit
  author on every commit in this repo.
- [2026-08-31] DEFAULT APPLIED: `config/car/criteria.json` `postcode_prefixes` → `[]` (explicit
  national scope; it is inert today anyway — no car source maps a postcode; confirmed
  `VehicleCriteria::matchesLocation()` treats `[]` as match-everything, and the car loader has no
  rent-style empty-filter refusal). Reversed by restoring the 8-department list in that one line.
- [2026-08-31] DEFAULT APPLIED: per-source `feed_silent_days` for new car sources — leboncoin-car
  **7** (observed cadence: one burst then 4+ days quiet; default 3 would false-alarm), La Centrale
  **3** (≈daily, default fine), Agorastore **3**. Reversed by editing the source's own
  `feed_silent_days` key.
- [2026-08-31] DEFAULT APPLIED: `.env.example`'s `CAR_*` keys stay COMMENTED until the car domain
  has run a full week (matches how the rent keys were activated only after real use). Reversed by
  uncommenting them.
- [2026-08-30] AGREED (predecessor, carried): today's new findings do NOT join `b8a1687`'s
  certification — they land as their own milestone afterward, so round 4 stays attributable.
- [2026-08-30] AGREED (predecessor, carried): diesel/ZFE — keep the existing fuel-preference
  weight as-is; no work item (recorded so it is not re-asked).
- [2026-08-30] AGREED (predecessor, carried): gate hooks/config are never edited; bypass
  *sentinels* via the sanctioned skill were authorised and are already in place.
- [2026-08-30] AGREED (predecessor, carried): Track 0 exit (close after round 4 vs run round 5 for
  the second clean) is decided WITH the developer after seeing round 4's verdict — via
  `AskUserQuestion`, never silently.
- [2026-08-31] ROUND 4 RAN AND IS **NOT CLEAN**: 19 findings across three lenses (2 × P0, both
  found INDEPENDENTLY by two lenses; 6 × P1; the rest P2/P3). All fixed in the round-4 fix commit.
  MAXIMAL's two-consecutive-clean counter is therefore **0**, and the close-vs-round-5 question
  above does not arise yet — the milestone re-freezes at the fix commit and round 5 runs against it.
  Full record: `var/claude/track0-round4.md`.
- [2026-08-31] DEFAULT APPLIED: the durable own reading is **PERMANENT**, and the docblock now says
  so instead of promising "until an explicit command". No command re-opens an excluded row
  (`staleVerdicts()` skips excluded, `pendingDigest()` skips non-DIGEST, `replay` writes no
  verdicts). Reversed by building such a command, which is a design question nobody has been asked;
  Q38's precedent is to state permanence outright rather than promise a route.
- [2026-08-31] DEFAULT APPLIED: Track 2 step 0's scrubber fix was pulled FORWARD into the Track 0
  fix commit, ahead of its planned place at the head of Track 1. Reason: the resilience lens found
  the leak as a live P1 (two committed fixtures carrying the subscriber's real name), so it stopped
  being preparatory work for future captures and became a defect in the tree. Reversed by nothing —
  the plan's sequencing rationale (1g/1h captures depend on it) is unaffected, it simply landed
  earlier.
- [2026-08-31] AGREED (developer ruling): **Track 0 CLOSES UNCERTIFIED.** Rounds 4/5/6 produced
  19/25/21 findings and never approached MAXIMAL's two-consecutive-clean bar; each round costs ~5 h
  and the other four tracks were untouched. Round 6's findings are closed, then Track 1 starts —
  round 7 is NOT run. What stands in place of the panel: the 588-case sabotage ledger, the full
  suite, drift-scan, and live `doctor` runs against the deployed image. Reversed by running a round.
- [2026-08-31] AGREED (developer ruling): track order for the remaining work is **1 → 2 → 3 → 4**
  (defect fixes, car sources, the audit, then the vehicle guard + store split).
- [2026-08-31] AGREED (developer ruling, re-confirmed): git history is LEFT AS IS for both fixture
  leaks (the name in 2 ParuVendu fixtures, the address in 3 Bien'ici ones). Forward fixes only; the
  blobs stay reachable by `git show <sha>:<path>` and hard rule 7 now states that cost. Reversed
  only by the developer's own force-push.
- [2026-08-31] DEFAULT APPLIED: the twin veto is now TRANSITIVE over a connected component of the
  twin graph. Stated cost: a chain A–B–C vetoes both ends even where A and C would not have been
  linked directly. §1's bias is toward not notifying and Q39 already prices pairwise over-linking as
  permanent; reversed by resolving only over direct edges (one `if` in the propagation sweep).
- [2026-08-31] FINDING RECORDED, NOT YET FIXED: the generic `ROOMS_PATTERN` matches hex inside
  photo-URL UUIDs (`…90F8-…` → 8 rooms). 14 wrong room counts on seloger, 6 of them notified; both
  directions do harm. New Track 1j — evidence in `var/claude/track1j-rooms-uuid-evidence.md`.
- [2026-08-31] FINDING RECORDED, NOT YET FIXED: PAP has THREE location shapes, not one, and all four
  of its positional params anchor on `\(\d{5}\)` — so on the department-only and Paris-arrondissement
  variants all four fail together. This is also the true cause of F6's "4 pap empty-title rows": one
  root cause, not two. Evidence and the measured replacement patterns:
  `var/claude/track1h-pap-evidence.md`.
- [2026-09-01] AGREED (developer instruction, verbatim intent): *"everything specific case we
  handled we need to check all sources/workflows to see if it needs to be generalized or in other
  sources too"*. Becomes **Track 5**, a standing rule as much as a task: no per-source fix lands
  again without its own row in the mechanism × source matrix saying which other sources were
  checked and what the answer was.
- [2026-09-01] AGREED (developer observation, then measured): a Bien'ici/SeLoger listing whose
  ADVERTISER is an institutional landlord is judged `LIBRE` at the source default, while the same
  flat on that landlord's own site is judged under `mixed_tenure: true`. **The verdict depends on
  the route, not on the flat** — a §1 breach. 24 stored rows, 21 pushed as MATCH, 18 with no twin.
  Fix = Track 5a. NOT a new classifier signal (the classifier reasons about tenure, not about who)
  and NOT a new reject path.
- [2026-09-01] AGREED: **AL'in goes on indefinite hold, like `src/phorj/`** (developer ruling).
  It is no longer an owed input; do not start it, do not re-propose it. `docs/SOURCES.md` A4 keeps
  the measurement. Reversed by the developer saying so.
- [2026-09-01] VERIFIED, input closed: the leboncoin `vous propose` Gmail filter EXISTS and works —
  all 5 messages carry `car-watch/portails` (`Label_809606989472151971`), confirmed over
  `in:anywhere` with no date or read-state restriction. The source is quiet, not broken: leboncoin
  has sent no car alert since 2026-08-27.
- [2026-09-01] INPUT PENDING: CapCar alert created by the developer; awaiting its first message.
- [2026-09-01] DEFAULT APPLIED: the advertiser is read from the **subject line** on SeLoger
  (`<ADVERTISER> vous adresse ses dernières exclusivités`, ~201 messages, one listing each).
  Anchored on the portal's own layout, never on a vocabulary scan of the body — the `au plus près` /
  filter-facet / CTA failure class has already cost this repo five fixes. Reversed by removing the
  source's `advertiser_pattern`.
- [2026-09-01] CORRECTION, same day, before any code: the count is **23 and all SeLoger**, not 24
  with one on Bien'ici. The extra row was the alias `i3f` matching inside a base64url JWT signature
  on a Century 21 listing — the sixth instance of the acronym-in-machine-text class, committed in
  the audit that found the bug. **Bien'ici has no advertiser surface and is uncovered**; the
  "advertiser is in the Bien'ici URL slug" claim was asserted from one example against a slug census
  already in evidence showing CRM vendors and agencies only.
- [2026-09-01] DEFAULT APPLIED: the landlord table is an **explicit registry pinned by a test**, not
  derived from `sources.json`. Derivation was the first design and the data refutes it — RIVP has no
  source block, *Immobilière 3F* lives under a block named `cityloger`, and *IN'LI* does not fold to
  `inli`. Reversed by deleting the registry and its pinning test together; never one without the
  other.
- [2026-09-01] DEFAULT APPLIED: a recognised advertiser with **no source block** inherits the
  fail-closed posture (`mixed_tenure: true`, `default_tenure: null`) — digest unless an explicit
  label decides. Guessing LIBRE for an unmeasured landlord is the §1-dangerous direction. Reversed
  per-landlord by giving it an explicit posture in the registry.
- [2026-09-01] AGREED: **Track 5b's matrix was measured and triaged; three items build and three are
  declined.** Report: `var/claude/track5-matrix-2026-09-01.md` (gitignored — its findings are
  restated below so the record survives it). Build T5B-1 (the extraction-miss signal on every
  adapter, both domains), T5B-9/F24 (a SeLoger title anchor that survives a card with no `pièces`
  line), and the register corrections. The developer challenged the first triage question for
  recommending one option without arguing the rest, and the re-ask carried a recommendation for
  every row — recorded because *"which do you recommend"* was the right correction to make.
- [2026-09-01] AGREED: **T5B-2 — no rescue route for rows rejected on an extraction since fixed.**
  Recorded as a stated cost instead. **The rescue has a shelf life the §1 risk does not**: the 32
  affected rows span 08-25 to 08-31 and `digest` already documents that the pipeline can only
  re-offer a listing while its ad is still published, so most would push dead flats — while
  re-judging on evidence the original verdict never saw is the breach `ListingSnapshot` exists to
  prevent. Once T5B-1 lands, the detection window is one pass rather than four days, at which point
  a MANUAL re-offer of a named set is sufficient and no standing machinery is warranted. **Stated
  cost: 32 rows stay rejected permanently, 4 of them SeLoger rows whose titles state a surface above
  the 50 m² floor.** Reversed by designing the route, keyed on *"the extraction changed"* rather
  than on *"the classifier improved"* — which is the one framing that may not carry the §1 risk.
- [2026-09-01] AGREED: **T5B-3 — `Core\Prose` does NOT learn `dernier étage`.** leboncoin states it
  on 3 of 3 cards, but it is a POSITION WITHOUT AN ORDINAL, so the reader recovers zero floors; and
  it cannot feed the high-floor penalty either, which requires an explicit `hasElevator = false` —
  and corrected per-line measurement finds `ascenseur` in **zero** card texts across all four
  portals' fixtures. A reading that changes no score is not worth the negation discipline it drags
  behind it. Reversed if a portal starts stating a numeric floor.
- [2026-09-01] AGREED: **T5B-7 — Logirep does NOT declare `prose_absent`.** It maps no `description`
  (120/120 null), so `exclude_patterns` runs on its title alone and the load guard would permit the
  declaration — but Logirep is an institutional landlord whose stock is not rooms or furnished
  lets, so a line on 123 rows saying *"colocation/meublé non vérifiables"* is how a caveat becomes
  the furniture its own counterweight test exists to prevent. Reversed by one key in
  `config/rent/sources.json`.
- [2026-09-01 ~22:30] RECORD: **Full audit run and banked** — four read-only auditors (rent store,
  car store, plan-vs-tree, config coherence) + a live Gmail census against HEAD `7a51ae3`.
  Synthesis: `var/claude/audit-2026-09-01.md`; raw evidence with every query:
  `var/claude/raw/audit-*-2026-09-01.md`. Zero closed register rows refuted; §1 store-wide sweep
  clean. Track 3 is DISCHARGED by this audit (census, reconciliation, unmatched senders,
  structural outliers — all four checklist parts per label/source are in the raw files).
- [2026-09-01 ~22:40] AGREED: **all four audit triage clusters are selected for execution**
  (A live-risk fixes, B new car sources, C hygiene + certification, D rulings) — see Track 6
  below. The developer executes via a different model; this session saved the plan and stopped.
- [2026-09-01 ~22:45] AGREED: **F30 — `advertiser_pattern` is DROPPED from the seloger block.**
  The template names no agency (Track 5b matrix: N/A; live: 375/405 miss), so the pattern asks for
  a field that does not exist, and a thin pass (truncated window, one agencyless 3-card alert)
  reaches the 100%-of-≥3 WARN spuriously. A pattern the template structurally cannot satisfy is
  not configured. Reversed by restoring the key — but capture a card that names an agency first.
- [2026-09-01 ~22:45] AGREED: **push grain — SCORE-FLOOR BATCHING.** Individual push only for
  score ≥ `high_priority_score` (rent 50 / car 73); every other match rolls into the existing
  digest path. Nothing is hidden — low scorers arrive rolled up instead of one-per-mail (~200
  rent + ~20–45 car mails/day today, 925 of 1141 rent-watch unread). AND, verbatim: *"we need to
  work more and make the score more realistic and beneficial to me!"* — a score-recalibration
  work item travels with this ruling (Track 6-A5): measure over stored snapshots, present 2–3
  calibrated options with their effect on the developer's own recent matches via AskUserQuestion
  (a user-facing product decision — the repo's mandatory-ask case), then apply the chosen one.
- [2026-09-01 ~22:45] AGREED: **F28 victims — ONE-SHOT READ-ONLY REPORT, no rescue mechanism.**
  Print the ~7 seloger rows REJECTed on misread surfaces (stored 5–9 m², titles stating 49–65 m²)
  with titles + links for manual review — the ads may still be live. NO store writes, no
  re-judging; the F28 ruling itself stands untouched.
- [2026-09-01 ~22:45] AGREED: **T5B-7 is SUPERSEDED — Logirep DOES declare `prose_absent`.** The
  earlier same-day decline (above) argued the caveat is furniture on an institutional landlord;
  the developer ruled to declare it after seeing the measurement (120/120 null description, 100%
  UNKNOWN tenure — the description-matching half of `exclude_patterns` structurally inert; titles
  still real and still scanned). Stated cost: the caveat line on every logirep push. Reversed by
  removing the key. Executor note: the load guard refuses the declaration only on a source that
  MAPS a description — logirep maps none, so it is permitted.
- [2026-09-01 ~22:45] RECORD (closing a log gap found by the audit): **`feed_silent_days` 7→5 for
  car leboncoin was superseded by `4b50e47`** (both leboncoin thresholds had sat past the
  IMAP_SINCE_DAYS window, so the ruled 7 was unobservable); the supersession previously existed
  only in that commit message. The stale `"7"` in the config's `_feed_silent_days` comment is
  Track 6-C1's to fix.

- [2026-09-01 ~23:30] AGREED: **Track 6 execution order is A2 → A1 → A3**, then A4, A7, B, C1 —
  the cheapest certain win first, then the live In'li degradation INVESTIGATED before anything is
  built for it, then the `ListingMapper` bundle. A6 waits for 2026-09-02 data by construction.
- [2026-09-01 ~23:30] AGREED, and it OVERRIDES the 2026-09-01 ~22:45 F30 ruling: **`advertiser_pattern`
  is KEPT and EXEMPTED from miss-counting**, not dropped. The drop was ruled before anyone traced
  what the key feeds. Measured this session: `advertiser_pattern` is read by
  `EmailAlertSource` → `RawListing`/`ListingSnapshot` → `LandlordRegistry` → `TenureClassifier`,
  and is the whole mechanism of `dede8ac` (Track 5a) — the fix that stopped 23 institutional-landlord
  rows on SeLoger (16 In'li, 7 CDC) being judged LIBRE at the portal's 50bp default, 21 of which had
  been pushed as MATCHes. Dropping the key sends those back to MATCH, which is a §1 regression, so
  the question is *how* to satisfy §1, never *whether*. The 92.6 % miss is not a template change and
  not a fault: the pattern reads the SUBJECT of the `exclusivités` template, and it is counted PER
  CARD, which is what turns 30 real hits into a 375/405 ratio. **The car domain already rules this
  exact shape** — `subject_pattern` is deliberately never counted, because a message-level pattern
  counted per card dilutes the ratio the WARN depends on. Same treatment here. Reversed by counting
  it again, which re-creates the spurious thin-pass WARN F30 identified.
- [2026-09-02] RECORD (Track 6-A1, audit N1): **In'li investigated before anything was built, and
  the finding is not the one the audit predicted.** The audit recommended "a failure-RATE signal in
  health"; `WARN_FLAKY` ALREADY EXISTS and already computes one. What is wrong is the TIMESCALE, and
  reading the rule before writing a replacement is what found it: the ratio is 30 % over
  `ROLLING_WINDOW_DAYS = 7`, and In'li measured 23.0 % over 24 h against **8.2 % over 7 days**, so
  the rule could not fire while the daily rate climbed 2.0 → 11.3 → 11.7 → 22.8 % across four days.
  **The window that makes a SUSTAINED fault visible is the window that hides a CLIMBING one** — the
  `STALE`/`FEED_SILENT` class, a correct rule blind on one axis of what it measures. Built: a SHORT
  window beside the long one (`FLAKY_SHORT_WINDOW_DAYS = 1`, ratio 0.2, `MIN_RUNS_FOR_SHORT_FLAKY =
  20`), same rows, same clock bound, long rule checked first so a sustained fault keeps the fuller
  statement, and the detail names which window fired. Ratio chosen against a COUNTERWEIGHT, not
  fitted: last-24 h failure rate over eleven sources in both domains was inli 23.0 %, cdc_habitat
  3.0 %, car leboncoin 1.0 %, the other eight 0.0 %. The minimum keeps a cron `--once` deployment
  (four passes/day, where one failure is 25 %) out of it, and the seven-day rule still covers those
  — asserted as its own test so the limit is not a hole. **Not built, deliberately:** no timeout
  raise (the only measurement is a 1.4 s quiet-hour TTFB; the anti-bandaid gate's named case) and no
  pacing change (failures track the site's daytime load, not our cadence — zero between 18:00 and
  05:00, and 26 of 46 are on the INDEX rather than deep pages). Evidence: `var/claude/track6-a1.md`.
  Stated cost: a source under 20 % today and under 30 % this week still reads `ok`. Reversed by
  removing the second check.
- [2026-09-02] RECORD (Track 6-A3, F27b + audit N5 + N6): **the miss signal reaches the four rent
  html/json sources; N5 is not reproducible and N6 is DECLINED.** `ListingMapper` is instrumented —
  the one funnel all four sources and `DetailHydrator` pass through, so `HtmlSource`,
  `HttpJsonSource` and `FixtureSource` implement `CountsPatternMisses` and both CLIs already gate
  their report on it. The hydrator SHARES the source's log, because a detail-map field that stops
  extracting is a fault of the SOURCE. **Only a CONFIGURED field can miss**, and that guard is the
  whole signal: logirep maps no `floor`/`elevator` and its 123 rows are 123/123 null on both, so
  without it every pass reports a permanent 100 % on fields nobody asked for — F30's shape on four
  sources at once. **Stated ceiling:** `total()` reports only `misses === calls`, and no configured
  field is near it (inli elevator 46 %, cdc 35 %, cityloger surface 16 %), so this catches the F1
  shape — a field going to zero everywhere at once — and is SILENT on a partial variant, cityloger's
  real 16 % surface defect included. A per-field opt-out was considered and NOT built: nothing needs
  one, and an inert config key is the class A2 made refusable the same evening.
  **N5 REFUTED AS STATED**: measured before writing a fix, `financement: PLS`, `regime: PLS` and
  `tenureField: PLS` all classify PLS at tier 1, and `financement: LLI` / `regime: LLI` both give
  LLI 97 — the unknown-field path already scans any value for excluded vocabulary. No verdict
  changes. What WAS fixed is the asymmetry: `tenure_field` acted on the html path (via
  `flatMapped()`'s literal-key renaming) and did nothing on json. Real, worth closing, and NOT the
  §1 hole the audit described — the register must not say it was.
  **N6 DECLINED on the measurement**: the 11 sub-400 € rents are correct readings — four cdc rows
  titled `Sous-sol` are cellars at 119–210 €, cityloger's seven are mostly `1 pièce(s)`. A 400 €
  floor nulls ten right answers to catch one suspicious `5 pièce(s)` at 290 €, and nulling does not
  reject (hard rule 9) — it makes the ceiling unverifiable, so the band LOSES information here. The
  email band exists because a prose reader assembles a plausible wrong figure from three numbers on
  a card; a mapped field is a declared price and fails differently. Price-per-m² is the shape that
  might work and needs its own study. Evidence: `var/claude/track6-a3.md`. Reversed by building it.
- [2026-09-02] RECORD (Track 6-A3, found by DEPLOYING it): **the card map and the detail map must
  count separately — pooling them hides a whole dead map.** The miss signal shipped, the image was
  rebuilt, and the FIRST live `doctor` reported `inli … cp 171/342`. In'li maps `cp` on both maps
  (card = URL slug, detail = page `<title>`, the `683a31b` fix), so 342 = 171 card + 171 detail —
  confirmed by `floor`/`elevator` reading `/171`, those being detail-only. `PatternMissLog::total()`
  speaks only at 100 %, so **a card pattern missing on all 171 cards averages with 171 detail
  successes into a silent 50 %**: one whole map dead, reported as half-working, WARN unreachable.
  The seven-day flaky window's dilution, one layer down, shipped the same evening by the same
  session. Fixed with a `$missPrefix` on `ListingMapper` (`'detail.'` from `DetailHydrator`).
  **The test goes through `HtmlSource` → `DetailHydrator`, not a hand-built mapper**, because the
  separation lives in the wiring — a mapper-level test keeps passing while the wiring is removed,
  which is the dead-safety-code shape. Its first draft asserted the card side missed on the shared
  fixture, which carries `.cp`, so it failed on a premise I assumed rather than measured; the card
  map now points at a selector the fixture genuinely lacks. Ledger case added; mutation verified
  detected (`3 is identical to 6` — the pooling exactly) and the restore verified byte-identical.
  **CORRECTION, and it must be recorded because the commit message cannot be: `2ab3245` describes
  the hazard as *"a card pattern missing on all 171 cards averaged with 171 detail successes"*, and
  that split is NOT established.** Measured afterwards over the store: **281 of 531 In'li URLs carry
  a 5-digit slug the card pattern can read (53 %)**, so the card map is not structurally dead, and
  `171/342` is merely CONSISTENT with one dead map rather than evidence of one. The real split is
  undetermined — which is the whole reason the pooling had to go, and the un-pooled report on the
  next deployed pass is what answers it. A true number with an assumed cause is this repo's named
  failure, and the reasoning for the FIX (pooling makes a 100 % failure on one side unreportable)
  stands on its own without that diagnosis.
  **Standing rule this leaves behind: a signal is not proven by its tests, only by its first live
  pass.** Reversed by dropping the prefix.
- [2026-09-02] AGREED + RESOLVED: **In'li changed its URL format, and the card `cp` pattern is
  REMOVED.** This settles what the `4968356` correction left open. The un-pooled report on the very
  next deployed pass said `cp 171/171 ← AUCUNE : gabarit changé ?` with `detail.cp` absent (0
  misses), and the cause is measured, not inferred: the old
  `/location-appartement-sceaux-92330/441-20013-2003` carried the postcode, the new
  `/locations/offre/paris/PRV-251653` carries only a commune slug. **0 of 190 rows seen that morning
  match the pattern**, and it CANNOT be repaired — the figure is not in the URL any more. The 281 of
  531 historical matches are older rows in the old format; two populations, to be read rather than
  reconciled. **So the miss signal caught a REAL portal template change on its first honest pass**,
  which is exactly the F1 shape it was built for and which the pooled counter had shown as a benign
  50 %. STATED COST (developer ruling): an unhydrated card now carries no postcode and
  `matchesCommune()` refuses a null postcode in region mode, so during a cold start, a detail-budget
  backlog or a hydration failure an In'li listing cannot match until hydrated — 0 recent rows
  affected, 38 historical rows unhydrated and postcode-less. Reversed by restoring a pattern, which
  needs the postcode to be back in the URL first. Config-only, so it is live through the bind mount
  without a rebuild (verified in-container). Commit `7f5e22b`.
- [2026-09-02] FINDING RECORDED, NOT A TASK: **49 In'li rows are hydrated AND have no postcode** —
  meaning the detail `<title>` pattern missed on them too. All 49 were last seen BEFORE today and
  the pattern missed on 0 of today's 171, so this is audit N9's residue: it self-heals on
  re-sighting, and F28 covers the rest. A second pattern question on the same source if it recurs.
- [2026-09-02] RECORD: **the executor stops here.** Eight code commits since `ac0cc1b`, two
  deploys, three developer rulings taken by `AskUserQuestion` (F30 exemption overriding the drop,
  A5's shape, the In'li `cp` removal). Certified by execution: suite **2648 / 10 307 assertions**,
  drift-scan exit 0, `test-sabotage-applies` 636/636 on `e9d1c96` (one expression retargeted after
  it reported INERT), `test-ci-workflow` OK, every `test-*.sh` guard OK, and all six new ledger
  cases individually detected with restores verified byte-identical.
  **UNCERTIFIED-BY-EXECUTION, stated in those words:** `tests/test-sabotage-baseline.sh` has not
  completed since `413f38e` (4/4 there; the copy list `src tests config phpunit.xml composer.json
  .env.example vendor` is unchanged, and later runs were killed by the harness mid-execution, not by
  a failure); the full 631-case ledger has not been run in this session (nightly's job); and **A1's
  short-window rule has never fired live** — In'li recovered overnight to 2.7 %, so the rule is not
  due. A dated prediction of what firing will look like is in `var/claude/track6-a1.md`, so tomorrow
  is checked against a record rather than a memory. Track 6-C2's MAXIMAL round is the REVIEWING
  session's, against a frozen commit.
- [2026-09-02] RECORD (Track 6-A6, audit N2): **premature, not answerable — re-run tomorrow.** The
  plan's own query filters `first_seen_at >= '2026-09-02'` and the newest row in the store is
  `2026-09-01T20:50:49Z`, so it returns nothing on every source. Against the DEPLOY boundary
  instead (image `2026-09-01T19:25:06Z`): all 14 tiny-surface seloger rows are pre-deploy, zero
  post, and the 8 post-deploy rows all state a surface, min 49.15 m². **n = 8 — a direction, not a
  verdict.** N2 stays open; the exact query is in `var/claude/track6-a6.md`. Note there is no
  `surface_m2` column: the figure lives in the v7 snapshot at `evidence_json -> $.surfaceM2`.
- [2026-09-02] RECORD (Track 6-A3 recon): **cityloger's null surfaces are a selector SCOPE miss,
  settled by ONE fetch.** The page states `65 m2` on line 221 in the icon-feature list; the
  `detail_map.surface` selector scopes to `div.tab-content`, which opens on line 268. Not an
  omission, and not the `m²`/`m2` spelling `7d882f3` fixed. 51 of 61 rows extract fine, so these two
  are a template variant. **Fix is a SEPARATE change** (developer ruling): add the second selector
  on its own and trial it over all 61 stored rows first — a widened scope altering any of the 51
  working extractions is a regression wearing a fix's clothes (the F24 precedent: 617 unchanged, 2
  gained, 0 lost). Evidence: `var/claude/track6-a3-cityloger.md`.
- [2026-09-01 ~23:30] AGREED (A5 shape): **a second queue, ONE drain, separated in the mail.**
  Low-score-but-tenure-clean matches queue on their own and drain through the existing
  `Cli/DigestBatch`, with the formatter keeping *"vérifié, score bas"* visibly apart from
  *"à vérifier"* — the rent digest already MEANS tenure-doubt, and reusing that kind for a
  score decision would misreport a listing's §1 status (schema v8's `DIGEST < MATCH` is monotone
  and never demotes). **Executor note, measured: the car domain has NO digest at all** — by design
  (`VehicleOutcome`: *"there is no mixed-stock digest in this domain"*), so the plan's "do NOT build
  a second drain" is rent-only advice and the car half is a NEW rollup that must not be called a
  digest. Reversed by pushing every match individually again.

---

## Fragile implementations register (the developer asked; keep this list honest)

> **IDS ARE UNIQUE AND NEVER REUSED, and this note exists because they were.** On 2026-08-31/09-01
> four rows were appended as F10–F13 without checking those numbers were taken — the register
> already ran to F22, and F10–F15 were all live. A first repair renumbered the wrong side and
> collided again. The rows added then are now **F23 (SeLoger `Baisse de prix` empty title), F24 (no
> `pièces` anchor), F25 (compose wedge), F26 (fixture-backed doctor)**, and the originals F10–F15
> are untouched.
>
> **Commits `d60a183`, `67e2828`, `8590db7` and `94962d2` cite the old numbers** (F10 for what is
> now F23, F13 for what is now F26). Git history cannot be corrected, so it is recorded here
> instead; the in-tree citations in `config/rent/sources.json`, `PriceLedTitleTest`,
> `SelogerFixtureTest` and `tests/sabotage-check.sh` were updated to the new numbers.
>
> This is the concrete half of R6-8, which called the register's bookkeeping "a real breach of this
> file's own rule". **Before appending a row, read the LAST id in the table, not the last one you
> remember.**

| # | Surface | State | Where handled |
|---|---|---|---|
| F1 | PAP positional anchors (all FOUR params) | **BROKEN since ~08-28**, and worse than recorded: 100% null on 08-29/30/31, **23 null rows, 19 notified MATCH**. Two independent axes — the body layout changed, AND all four params anchor on `\(\d{5}\)`, which 3 of PAP's location shapes do not carry | Track 1h — patterns measured, 39/39 vs 16/39 today |
| F1b | PAP department-only / arrondissement variants | `Cergy (95)`, `Paris 16e` carry no 5-digit postcode, so title+commune+surface+rooms all fail together; 4 rows, all `REJECT` with an empty title. **This IS F6's "4 pap" rows — one root cause, not two** | Track 1h (new) |
| F2 | Bien'ici single-card "Une annonce" reader | **CLOSED 2026-09-01** — fixed by `5962ae6` (08-31 22:04, the `Pas de photo` separator). Measured over the store by cross-checking title-stated against extracted surface on every row that states one: **BEFORE 244 checkable / 6 wrong · AFTER 52 checkable / 0 wrong**. The 6 victims stay REJECTed for ever (T5B-2) | Track 1g — done |
| F3 | Extraction failures are invisible | A configured pattern that misses yields null "visibly" — but nothing counts misses, so F1 ran 4 days unnoticed | Track 1h (health half) |
| F4 | `postcode_pattern` + `title_pattern` on the CAR side | **CLOSED, and discharged more strongly than this row asked for.** `title_pattern` became READ (`77ea035`, against leboncoin's subject); the remaining two are **REFUSED AT LOAD** — `VehicleSourceLoader::UNREAD_PARAMS` throws a `ConfigError` naming the file to edit, so an inert param cannot be configured at all rather than merely being documented | Track 1c — done |
| F5 | Coliving exclusion | `\bcoliving\b` misses `Co-living` / `Co living` — one real notified MATCH (2026-08-30) | Track 1i |
| F6 | Empty-title rows | **RE-MEASURED 2026-09-01: 3 rows, not 7 — 2 seloger, 1 INLI (new, never recorded), 0 pap.** The PAP four are fixed (Track 1h's re-anchoring); the In'li one nothing had noticed. Store counts are CURRENT STATE — a title is rewritten on every re-sighting — so this does not contradict the earlier census, it supersedes it. **One of the two seloger rows was NOTIFIED as a MATCH on 08-27** with every `exclude_title_patterns` entry inert on it | **T5B-9, building** |
| F7 | La Centrale truncation | Email carries ~3 of 900+ stated cards; `FEED_SILENT` keys on message DATE, so health stays green while 99.7% blind | Track 2 step 4 (documented cost) |
| F8 | SeLoger `id_from: content` + a misread surface | A bad surface reading changes the dedup key → one flat can notify twice under two identities | Noted in 1g; same root class |
| F9 | n=1 separators/patterns (leboncoin rent, PAP) | Measured on one capture each; PAP already proved what that costs | standing; each new alert is the regression test |
| F10 | Generic `ROOMS_PATTERN` reads hex out of photo-URL UUIDs | `(?:T\|F)\s?(\d)\b` is case-insensitive, so `…90F8-…` → 8 rooms. **14 wrong on seloger, 6 notified; 7 on bienici.** Too LOW loses real matches, too HIGH clears `min_rooms` on a number nobody stated | **NEW Track 1j** (2026-08-31) |
| F11 | Startup refusal reachable only under `--watch` | *FIXED 2026-08-31* — it was also consumed above the `isDue()` test, so a restart inside the beat interval destroyed it unreported; `doctor` now reports it without consuming | round-4 fix commit |
| F23 | SeLoger `Baisse de prix` yields an EMPTY title | **CLOSED 2026-09-01** (`d60a183`) — the pattern refused any candidate CONTAINING a `€`; it now refuses only one that IS a price. Measured over the store: 552 unchanged, 2 gained a title, 0 changed, 0 lost. Template frozen as fixtures 004/005 | — |
| F24 | A SeLoger card with no `pièces` line has no title anchor at all | **CLOSED 2026-09-01 (T5B-9).** The captured card of that shape was already in the store — schema v7 keeps the card text, so no Gmail capture was needed: both empty-title rows are room rentals whose title line is present and readable, sitting between the price line and a `140 m²` line. `title_pattern` gained a SECOND ANCHOR on the surface line (`m²` and the ASCII `m2`, since 128 stored titles write it). Trialled over all 619 stored SeLoger cards before shipping: **617 unchanged, 2 gained, 0 changed, 0 LOST**, the two gained being exactly the victims — and both are now rejected through the SHIPPED criteria, asserted with the description held empty so `\bcolocation\b` cannot deliver the verdict the title is supposed to. **Confirmed LIVE on the deployed image the same day**: `doctor --source=seloger` over **405 live cards names no `title_pattern` miss at all** (the log lists only patterns whose miss count is non-zero), so the anchor read every one and never fell back to the subject. That is the post-deploy evidence F9 asks for, arriving on the first pass rather than waiting for the next room-rental alert. Was: **LIVE, and the remaining half of F10** — the anchor IS the `pièces` line, so a card stating no room count (a room rental, a parking, an atypical ad) yields `''` whatever the `€` rule does. Two such rows; both REJECTED, by the description-matching `exclude_patterns` rather than the title ones — luck rather than a guard. Needs a SECOND anchor, and a captured card of that shape to measure one against | fix owed |
| F25 | `docker compose up -d` wedges on recreate and leaves a watcher DOWN | **LIVE, twice on 2026-08-31.** `stop_grace_period: 5m` + a renamed old container = the orchestration stalls; once it then failed outright on `Conflict. The container name … is already in use`. rent-scout was down ~13 min and nothing said so | see the redeploy note below |
| F26 | A fixture-backed `doctor` writes its run into the LIVE store | **HIT 2026-09-01, and it is the DOCUMENTED workflow that does it.** `MAILBOX_DIR=` swaps the mailbox, not the database, so a fixture run's item count joins the 7-day baseline every live run is judged against — it made car `leboncoin` report `broken` on a 5-annonce premise made of fixtures. Fixed in CLAUDE.md: every documented offline proof now pairs with a throwaway DB | closed, guidance fixed |
| F12 | Car heartbeat inside the pass closure | *FIXED 2026-08-31* — a throwing pass silenced the watcher entirely, the one state the beat exists to make visible | round-4 fix commit |
| F13 | Scrubber `To:`/`Cc:` display name, and any base64 fold ≤19 columns | *FIXED 2026-08-31* — two committed fixtures had shipped the subscriber's real name; a 19-column fold was written and reported `scrubbed` with the address one `base64 -d` away | round-4 fix commit |
| F14 | Three Bien'ici fixtures carried the subscriber's address behind a DOUBLE base64 layer | *FIXED `3d24525`* — detection in `8f0c526`, stripping once the QP-eating hex replacer was fixed; all three re-scrubbed, 0 of 15 fixtures leak four levels deep, and `FixtureSecretsTest` is now armed with the same recursive decode. HISTORY still carries the blobs — the developer's call (hard rule 7's new note) | closed, remote exposure stated |
| F15 | The refusal note is consumed at beat-COMPOSE time, not on delivery | *FIXED `ec17b74`* — An undelivered beat destroys it, and the commonest refusal IS a channel misconfiguration — so the beat that should carry the note is the one most likely to fail | **OPEN — round 5, O3** |
| F16 | Cron `--once` never clears the refusal note | *FIXED `ccc8498`* — `takeLastRefusal()` is called only in `watch()`, so `doctor` reports a fixed outage for ever while saying it will be carried on the next beat | **OPEN — round 5, O4** |
| F17 | Car `doctor` has no `pendingRefusal` | *FIXED `4503834`* — The gap round 4 closed for rent is fully open on car (`grep -c pendingRefusal`: rent 3, car 0) | **OPEN — round 5, O5** |
| F18 | A plain `grep` silently skips the Latin-1 PAP fixtures | `grep -c .` prints nothing and exits 0; `grep -ac .` prints 145. Any grep-based "N fixtures scanned, 0 hits" sweep is unsound on this tree — use a byte-level scanner | **OPEN — round 5, O6 (method)** |
| F19 | A §1 guard inside `twinClassification()` covered by neither the suite nor the ledger | **CLOSED — verified 2026-09-01.** The ledger case exists: `tests/sabotage-check.sh:3756` mutates `Pipeline.php:329`'s `groupExcludedTenure($key)` to `null`. This row read FIXED *and* OPEN at once, which is the R6-8 breach; a session re-verified it from scratch because of that. *FIXED `43778bd`* — `Pipeline.php:829`'s `clusterClassification(..., groupExcludedTenure($key))` is the only thing reaching an excluded tenure on an ABSORBED SIBLING OF THE TWIN's cluster. Mutating it to `null` leaves all 2 339 tests green AND pushes the agency copy of a PLS flat (proven by execution). Round 4 added ledger cases for the twin fact's write-across and read-across; this third surface has none — the same "one of two surfaces" shape as the P0 it was fixing | **OPEN — round 5 correctness, needs a test THEN a ledger case** |
| **F20** | **Neither documented repair route for an over-merge/over-link actually works, and no command can re-open a durably-excluded row** | The judged verdict (carrying the group's or twin's excluded tenure) is written into the row's OWN `tenure`, which round 4 then made durable — so a veto is laundered into the row's own reading. `staleVerdicts()`/`pendingDigest()`/`replay` all skip it. Q39 corrected; the rejection reason also MISATTRIBUTES the PLS to "a previous reading of THIS listing" when it was read on the other track | **OPEN — round 5 correctness** |
| F21 | An unrecognised `tenure` string silently releases a durable excluded reading | *FIXED `76251b4`* — it released the row's own reading, the group veto AND the twin veto together. `decodeTenure()` now refuses a non-empty value that does not decode; a NULL column still means nothing was said | closed |
| F27 | **The extraction-miss signal reaches ONE adapter of five, across both domains** | **LIVE, and it is the fix for F3 landing on one of several symmetric surfaces — the repo's named recurring defect, committed by the fix for the finding that names it.** `grep -c PatternMiss`: `EmailAlertSource` 5, `HtmlSource` 0, `JsonSource` 0, `DetailHydrator` 0, `VehicleEmailSource` 0. So inli, cdc_habitat, cityloger, logirep and all three car sources count nothing — a silently-null CSS selector or JSON path is the same failure as a missed regex. Measured live: **13 of 99 ParuVendu rows carry `body`+`fuel`+`year`+`mileageKm` all null** (one `facts_pattern` miss, identical count on all four fields) while `doctor` reports `ok · 3 annonces`. Cityloger carries 9 null surfaces of 60, **two re-sighted today**, cause undecidable from the store | **CLOSED 2026-09-02** — car half `78ff21a`, rent half Track 6-A3. (The census in this cell names `JsonSource`, a file that does not exist; the class is `HttpJsonSource`, and its count was 0 too — an empty grep reading as a measured zero) |
| F27b | The four rent `html`/`json` sources still count no extraction misses | **CLOSED 2026-09-02 (Track 6-A3).** `ListingMapper` is instrumented — the one funnel all four sources and `DetailHydrator` pass through — and `HtmlSource`/`HttpJsonSource`/`FixtureSource` implement `CountsPatternMisses`, which both CLIs already gate their report on. Proved end to end: `doctor --source=fixture_demo` on a throwaway DB now prints five per-field miss lines on a source that counted nothing. **Only a CONFIGURED field can miss** — logirep maps no `floor`/`elevator` and is 123/123 null on both, so without that guard every pass reports a permanent 100 % on fields nobody mapped (F30's shape, four sources at once). **Stated ceiling, and it retires this row's own promise about cityloger:** `total()` speaks only at 100 %, and cityloger's surface miss is 16 %, so the signal is SILENT on it. That question was settled instead by the one live fetch this row said it would cost — the page states `65 m2` on line 221 and the selector scopes to `div.tab-content`, which opens on line 268: a SCOPE miss, fix tracked separately | closed. **And it earned its keep on day one**: the first un-pooled deployed pass reported `inli cp 171/171 ← AUCUNE`, which is a REAL portal template change (In'li moved the postcode out of its URLs) that nothing else was watching — the F1 shape, caught. Partial-variant detection is still NOT covered |
| F28 | Nothing re-judges a `REJECT`, so a fix never rescues its own victims | **ACCEPTED AS A STATED COST** (ruling above). `reclassify` filters on `outcome` and reaches DIGEST/UNKNOWN; `staleVerdicts()` skips an excluded tenure; `replay` writes no verdicts. 32 rows: pap 21, bienici 6, seloger 5 — and 4 of the seloger 5 state a surface ABOVE the 50 m² floor, so they are real matches rejected as too small | closed by ruling |
| F29 | SeLoger's `link_host` is host-only and filters nothing | **DEFENDED, BUT NOT BY THAT PARAM.** Measured: 100 % of links in all five fixtures are on `click.by.seloger.com` (16/16, 19/19, 38/38, 17/17, 17/17), footer and unsubscribe included — so the PAP phantom-listing shape is available in principle. What actually prevents it is `card_separator` segmentation plus the no-information floor. Recorded because *guard* and *luck* are different things | watch; no work |
| F30 | SeLoger's `advertiser_pattern` misses 92.6 % of cards, permanently and quietly | **OPEN, and it is the cost of the F27 signal being honest.** First deployed `doctor` run: `advertiser_pattern 375/405 carte(s) sans résultat`, beside `residence_pattern 187/405`. The advertiser miss is not a template change — the Track 5b matrix already measured it as **N/A**: an ordinary SeLoger alert names no agency at all, so the pattern is asking for a field the template does not carry. On a full-window pass it cannot reach 100 %, so it will simply print a large ratio on every run for ever — **but that ceiling is a property of the window, not of the pattern.** The WARN is 100 % of ≥3, and a sparse read (the IMAP window truncated harder, or a quiet stretch where one agencyless 3-card alert is the whole pass) satisfies it exactly. So the reachable failure is a SPURIOUS WARN on a thin pass, not permanent silence — which is the worse of the two, because it fires the one signal F27 exists to give, on a pattern nobody can satisfy. Two readings, and choosing between them is a config decision, not a bug fix: either the pattern is right and 92.6 % is the honest shape of the source, or a pattern that structurally cannot be satisfied should not be configured. **Do not "fix" it by lowering the WARN floor** — that would fire on this row for ever and dilute the one signal F27 exists to give. Decide with the developer before touching it | watch; decide, don't build |
| F22 | A re-scrubbed ParuVendu fixture had a 152-column quoted-printable line | *FIXED `3d24525`* — root cause was the hex replacer eating `=3D` escapes, not the re-scrub itself; the line was re-folded at a soft break under a decode-equality guard that refuses unless the payload is byte-identical. Max column 152 -> 77 | closed |

---

## Step U — Unification commit (first commit of execution; docs-only)

U0. Verify the six gate-bypass sentinels exist (read-only `ls`); verify `git config user.name` /
    `user.email`. Do NOT re-run `/gates-bypass`.
U1. **Archive FIRST** (before installing the new plan, so no glob can sweep it up):
    `mkdir docs/plans/archive`, then `git mv` exactly these 15 files into it — an enumerated
    list, deliberately NOT a `*.plan.md` glob:
    `bienici-email-alert` · `car-domain-first-slice` · `claude-bundle-cross-repo-audit` ·
    `core-tenure-classifier` · `finish-everything` · `leboncoin-email-alert` ·
    `milestone-1-pipeline` · `pap-email-alert` · `phase-2-detail-hydration` ·
    `phase-2b-inli-floor-lift` · `plafonds-tier-4` · `q34-digest-daily-floor` ·
    `scout-rename-and-car-domain` · `seloger-email-alert` · `transit-enrichment`
    (each `.plan.md`). Prepend to each:
    ```
    > SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
    > Decisions Log and measurements; do not execute from this file.
    ```
U2. THEN copy this file verbatim to `docs/plans/scout-unified-execution.plan.md`, replacing only
    the leading `> INSTALL RULE` block with:
    ```
    > SINGLE SOURCE OF TRUTH for all pending scout work (developer ruling, 2026-08-31).
    > Supersedes every plan in docs/plans/archive/. Rulings land in this file's Decisions Log.
    ```
U3. Update every citation of the moved files — 20 exact hits (re-run
    `git grep -on "docs/plans/[a-z0-9-]*\.plan\.md" -- ':!docs/plans'` to confirm before editing):
    - `CLAUDE.md` (5): lines ~867, ~911, ~912, ~1019, ~1299 → `docs/plans/archive/<same-file>`.
    - `README.md` (4): lines ~66, ~487, ~555, ~556 → same rewrite.
    - `docs/OPEN-QUESTIONS.md` (3), `docs/SOURCES.md` (3), `docs/ALERT-CAPTURE.md` (1) → same.
    - `config/car/criteria.json` (1) and `config/car/sources.json` (2, incl. `_comment`) → same
      (JSON strings; keep valid JSON).
    - `src/php/Adapters/Http/RobotsResolver.php:53` docblock → same.
    - `.claude/skills/scout-repair/SKILL.md:25` cites `docs/plans/claude-bundle-integration.plan.md`,
      which does NOT exist (pre-existing drift — the real file is
      `claude-bundle-cross-repo-audit.plan.md`). Fix to the archived real path, or remove the
      example if the sentence doesn't need it.
    Also: `docs/OPEN-QUESTIONS.md` mentions phorj's `docs/plans/MASTER-PLAN.md` (outside the regex
    above — uppercase, no `.plan.md`) — that is a path in the PHORJ repo, not this one; leave it
    alone.
- U4. Verify: `bash .claude/skills/scout-repair/drift-scan.sh` exits 0;
    `git grep -n "docs/plans/" -- ':!docs/plans'` shows only `archive/` paths (plus the phorj
    MASTER-PLAN mention); `php -r "json_decode(file_get_contents('config/car/criteria.json'));"`
    and the same for `sources.json` report no error; full PHPUnit suite green (the PHP docblock
    edit is comment-only, but run it anyway).
U5. Commit (`docs: unify all plans into scout-unified-execution.plan.md, archive the rest`), push.
    **Certification: STANDARD** (one reviewer, three lenses, one pass) — this commit touches no
    application source; the mechanical carve-out in CLAUDE.md § Certification applies.
    **This commit does NOT disturb Track 0's freeze**: round 4 reviews `b8a1687` in worktrees
    pinned at that commit; a later docs commit on `master` is irrelevant to it.

---

## Track 0 — Finish certifying `b8a1687` (before any Track 1 code except 1a)

1. Filtered sabotage ledger, six cases (record said five; it is six — five added + one modified
   expression):
   ```
   SABOTAGE_FILTER="survivor's row only|excluded reading is forgotten|judged verdict|short last line|car startup refusal|never read back" bash tests/sabotage-check.sh
   ```
   Plain `|`, double quotes (ugrep). Pre-check that the filter selects exactly **6 of 574** labels
   before trusting any "0 undetected". Run under the JIT workaround (see executor briefing).
   Note: `b8a1687` also touched `Pipeline.php`, `Store.php`, `RentScout.php`, `CarScout.php`,
   `scrub-eml.php` beyond these 6 cases; whether that rotted any pre-existing sabotage expression
   is `tests/test-sabotage-applies.sh`'s job (run it too), not this filtered ledger's.
2. Panel round 4, frozen at `b8a1687`, three lenses (`tenure-correctness-reviewer`,
   `source-resilience-reviewer`, `completeness-reviewer`), spawned UNNAMED, each in its own pinned
   worktree (`cp -a`, never symlink `vendor/`). Put a timeout/Monitor on each lens — round 2 lost
   a lens to a 5.5 h stall.
3. Outcome: clean → ask the developer (AskUserQuestion) whether round 5 runs for the second clean
   or the milestone closes here. Findings → fix, re-freeze at the new commit, repeat round 4.

---

## Track 1 — Defects found 2026-08-30/31 (own milestone, own certification, AFTER Track 0 closes)

**Sequence within Track 1: Track 2 step 0 (the scrubber fix) lands as the FIRST commit of this
milestone** — the 1g and 1h fixture captures depend on it, and it is a code commit (tool + test),
so it belongs after Track 0 closes like the rest of Track 1. **Then 1g and 1h** — both are
actively losing/corrupting real matches daily. 1a runs immediately (gitignored file, outside any
certification). Then 1c, 1f, 1i, 1d.

### 1a. Car domain sends no email — one-line, gitignored, run at execution start

- Root cause (confirmed twice): `config/car/criteria.local.json` channels =
  `["console","ntfy"]` — missing `"email"`. Add it. No `CAR_*` SMTP key exists or is needed;
  `SMTP_*` is set and proven by rent's 889 sent notifications.
- `SMTP_FROM` is shared, so BOTH domains send from the identical address — if the developer's
  Gmail filter routes by sender, car mail misroutes into the rent label. The filter must key on
  the subject prefix `[car-watch]` (`CarScout.php:367`). Check the actual filter rule with the
  developer when applying.
- Fix the stale `_notify` comment in `config/rent/criteria.local.json` in the same edit (it still
  claims the `file` transport is pending real SMTP creds; `SMTP_TRANSPORT` is already `smtp`).
- Verify: `docker compose run --rm car-scout test-notify` → a message lands in the currently-empty
  `car-watch` Gmail label, and in the CORRECT label.

### 1b. `.env.example` `CAR_*` keys — RETRACTED, do not touch

Every `CAR_*` key IS declared, commented (`#CAR_SCOUT_DB=…` etc., lines ~139–148), which is this
repo's convention for optional keys (`RFR_N2`, `SCOUT_MAX_PASSES`, `TELEGRAM_*` identical).
drift-scan S8 counts a commented declaration as documented BY DESIGN. **Do not "fix" S8** — that
would false-positive on every commented-by-design key. No new test needed (`test-drift-scan.sh`
line ~131 already proves the missing-key mechanism generically). Keys stay commented per the
Decisions Log default.

### 1c. Car geography — inert filter, and two silently-unread params

- `postcode_prefixes` is inert on both car sources (neither maps a location) — set it to `[]` per
  the Decisions Log default and reword the config comment to say the location filter is
  deliberately national until a source maps a postcode.
- `postcode_pattern` AND `title_pattern` are declared in `VehicleSourceLoader::PATTERN_PARAMS` but
  read by ZERO car adapters. **Do not configure either on any car source** until an adapter reads
  them (the PAP-inert-param defect class, rebuilt). Making them actually read is real engineering,
  OWED but not scoped here — record as a one-line owed item in `config/car/sources.json`'s
  `_comment` (or this file) for whoever next touches `VehicleEmailSource`.
- Verify: a `matchesLocation()` unit test confirming `[]` matches every postcode (coverage of the
  already-true behavior); no doc line anywhere claims the two params are read.

### 1d. Car brand penalty — mechanism RESOLVED 2026-08-31

Contract (the ruling): **listed brands score strictly lower than unlisted at equal specs; unlisted
brands are unaffected; the weight comes out of the existing 100.**

- Config: `weights` becomes `price:20, age:20, mileage:20, gearbox:10, fuel:10, body:10, brand:10`
  (sum 100; the price/body allocation is a DEFAULT APPLIED, not ruled — see Decisions Log; the
  ruled part is only that `brand` comes out of the 100). New key `brand_avoid:
  ["peugeot","renault","opel"]` — a LIST of disfavoured brands, deliberately NOT named
  `brand_rank` (no `body_rank` higher-is-better connotation, and no ordering among the three was
  ruled — equal treatment).
- Scoring: an unlisted brand gets the full `brand` share (10); a listed brand gets 0. Matching is
  case/accent-insensitive on the extracted make. A car with NO extracted make gets the full share
  (hard rule 9: unknown is not disfavoured).
- Blast radius to cover in the same change: `VehicleCriteriaLoader` strict-key validation,
  `VehicleCriteria` constructor, every existing `VehicleScorer` test expectation (the rebalance
  shifts every score), `config/car/criteria.json` comments.
- `high_priority_score: 70` (already flagged uncalibrated): after the rebalance, re-score the
  stored car snapshots; if the distribution supports it, set a reachable value; if the store is
  still too small, keep 70 and record "recalibrate after a week of real scores" beside it.
- Test: table-driven — each of the three named brands scores strictly below an unlisted brand at
  otherwise-identical specs; an absent make scores as unlisted; weights still sum to 100.

### 1e. Diesel/ZFE — no change (ruling recorded in the Decisions Log; nothing to build).

### 1f. SeLoger room-in-shared-flat — price-per-m² plausibility heuristic

Evidence: three real matched listings implausibly cheap for their stated size (Champs-sur-Marne T5
at 605 € and 620 € CC ≈ 6.9 €/m²; a bare T5 at 449 €). The discriminating sentence exists only on
SeLoger's detail page, which this source structurally never reads (following the per-recipient
redirect is a hard-rule-5 refusal). **Do NOT write "SeLoger cards have no description"** — they
have one (`EmailAlertSource.php:451`), and `exclude_patterns` genuinely catches most coliving
wording through it; this heuristic covers the cards whose text says nothing.

Design constraints (non-negotiable):
- Threshold derived from a GAP in the store's price-per-m² distribution, never a raw percentile
  (p5 = 7.9 €/m² would eat a genuine 200 m²/750 € house at 3.75 €/m² — a real notified MATCH).
  Exclude 1g-class contaminated rows (misread surfaces) from the derivation set first. Use
  6.9 €/m² as a sanity anchor for where the break lands, never as the threshold.
- Reads `effectiveRentCc()` (same field as `max_rent_cc`); stated cost in the config comment: inert
  on the ~157 HC-only rows (Logirep, leboncoin, PAP).
- Guard `surface > 0 AND rooms > 0` — not merely non-null (15 Logirep rows carry surface=0 rooms=0
  for parkings; `rent/0.0` would `DivisionByZeroError` the pass).
- Routes to DIGEST via the existing v8 `notified_as` machinery, never a hard reject; the digest
  reason line names the price-plausibility cause explicitly. Whether it deserves its own route
  instead of reusing DIGEST is decided explicitly at build time (CriteriaEngine's docblock says the
  tenure classifier is meant to be the only outcome-decider — don't blur that silently).
- One sabotage case: disabling the heuristic turns the suite red.
- Verification: the three known rows are already `notified_as='MATCH'`, so NO digest email will
  appear for them — verify by re-querying their stored `outcome` flipping to DIGEST.
- The `une chambre` lookbehind bug in `exclude_title_patterns` stays a store-trialed CANDIDATE
  (zero confirmed real misses; naive fix re-opens a known over-rejection). Trial over
  `SELECT title FROM listings`; ship only with zero flat losses, else record the residual.

### 1g. Bien'ici single-card alerts silently reject real flats — FIRST, live loss (5 victims and counting)

The 2026-08-25 separator fix was measured on the MULTI-card template; the single-card "Une
annonce" template still reads the alert's own criteria line ("… - 3 pièces min - 45 m² min") as
the flat's stats. Five real production victims (ext_surface=45 vs titles 77/80/68/58/56 m², the
last two on 08-31 morning) — all genuinely inside criteria, all silently REJECTed. A sixth row
with title "…45 m²" agrees with its extraction — a genuine 45 m² flat, NOT a victim; exclude it
from the regression set.

- Capture the single-card template as a fixture (production `evidence_json` of the victims + a
  fresh Gmail capture, scrubbed — after Track 2 step 0's scrubber fix).
- Fix the surface/rooms reader for this template (same positional-anchor discipline as the
  multi-card fix, applied to the shape that lacks the separator).
- Regression test pins title-stated vs extracted surface on the 5 victim rows (not the 6th).
- Note in the same commit: the identical misread class on SeLoger changes the `id_from: content`
  dedup key → double-notification risk (F8). One line, not a separate fix.

### 1h. PAP template change broke both positional anchors — NEW 2026-08-31, with 1g at the front

**Evidence (live store, reproducible)**: null-surface AND null-rooms per day went 0/5 (08-26),
1/16 (08-27), 2/5 (08-28), 6/6, 5/5, 8/8 (08-31) — 21 rows null since 08-28, ~18 still notified
MATCH (null passes the 50 m² floor by hard rule 9 — so sub-50 m² flats can now notify). The
template moved rooms out of the title line into a combined line BELOW the postcode
(`4 pièces - 80 m²`) and switched `EUR` → `€`:

- OLD: `Location appartement 3 pièces` ⏎ `Meulan-en-Yvelines (78250)` ⏎ `90 m²` ⏎ `1.150 EUR / mois`
- NEW: `Location appartement` ⏎ `Lardy (91510)` ⏎ `4 pièces - 80 m²` ⏎ `1.200 € / mois`

Current patterns (`config/rent/sources.json`, pap params) and exactly why each misses:
- `surface_pattern = ~\(\d{5}\)\h*\n\h*([\d.,]+)\h*m²~u` — requires the surface figure FIRST on
  the line after the postcode; that line now starts `4 pièces - …`.
- `rooms_pattern = ~^[^\n]*?(\d+)\h*pi[eè]ces?[^\n]*\n[^\n]*\(\d{5}\)~mu` — requires rooms on the
  line ABOVE the postcode (the title); rooms moved below.

Fix (config-only for the patterns; code for the health half):
1. Capture one new-format `.eml` (Gmail RAW → corrected scrubber, see Track 2 step 0) as fixture
   003. Fixtures 001/002 (old format) are the regression set — both must keep extracting.
2. Widen both patterns to accept BOTH shapes. CANDIDATES (validate against all three fixtures and
   trial over the stored PAP evidence before shipping — the repo's own rule):
   - surface: `~\(\d{5}\)[^\n]*\n[^\n]*?([\d.,]+)\h*m²~u` (the figure immediately before `m²` on
     the line after the postcode — captures 90 in the old shape, 80 in the new, and can never see
     the criteria line's 45, which sits above the postcode anchor).
   - rooms: branch-reset both positions:
     `~(?|^[^\n]*?(\d+)\h*pi[eè]ces?[^\n]*\n[^\n]*\(\d{5}\)|\(\d{5}\)[^\n]*\n\h*(\d+)\h*pi[eè]ces?)~mu`
     (PCRE `(?|` keeps the capture as group 1 in both branches — verify the reader takes group 1).
   - Acceptance: 001 → 90 m²/3p (or its actual values — read the fixture, don't trust this line),
     002 → its values, 003 → 80 m²/4p; the criteria-line `45`/`3` are NEVER captured; a configured
     pattern that misses still yields null, never the generic scan (assert the counterweight).
3. **Health signal (the ruled half)**: count configured-pattern misses per source per pass and
   surface them through the ONE health funnel (`EmailAlertSource::health()` — the `feed_silent`
   precedent; doctor, pipeline and heartbeat all read it). Minimum contract:
   - per pass, per source: for each CONFIGURED pattern (`surface_pattern`, `rooms_pattern`,
     `title_pattern`, `commune_pattern`), the number of cards where it yielded null/`''`;
   - `scout --domain=rent doctor` prints the counts;
   - a pass where 100% of ≥3 parsed cards missed a configured pattern → health WARN (not BROKEN —
     cards still flowed; the portal changed its template, the F1 signature);
   - one sabotage case: break a pattern against the fixtures and assert doctor/health reports it.
4. No backfill of the 21 null rows (ruling). Stated cost: any sub-50 m² among them stays a wrongly
   notified MATCH in history.

### 1i. Coliving pattern gap — small, trialed, shipped only if clean

`Co-living - grande suite parentale…` (real notified MATCH 2026-08-30) and `Co living maison
partagee` both PASS every current exclusion. Candidate `\bco[\s-]?living\b` in
`exclude_patterns`; trial over `SELECT title FROM listings` (~1,790 rows) — ship only with zero
flat losses.

---

## Track 2 — Car source expansion (execute the existing research; do not re-research)

The exhaustive candidate investigation lives in `docs/plans/archive/scout-rename-and-car-domain.plan.md`
(dated 2026-08-26→29) — measurements stand, execute from here.

0. **Scrubber fix FIRST (prerequisite for every new capture, incl. 1g/1h fixtures)**:
   `tools/scrub-eml.php`'s `$drop` list contains `delivered-to` but not `to` — the `To:` header
   survives with only the address masked (and only when an address argument is passed at all;
   invoking without one exits 0 printing "scrubbed" while doing nothing). Add `to` to `$drop`;
   add a `tests/test-scrub-eml.sh` case asserting the `To:` header never survives; re-scrub the
   two ParuVendu fixtures that carry the developer's display name
   (`tests/fixtures/car/paruvendu/2026-08-29-{001,002}-*.eml`) so no new copy enters future
   commits. History stays as-is (ruling). Every capture invocation:
   `php tools/scrub-eml.php in.eml out.eml takieddine.messaoudi.official@gmail.com [needles…]` —
   address REQUIRED, plus a first-name/username needle for templates that greet by name
   (leboncoin does).
1. **leboncoin-car — DONE 2026-08-31, commit `77ea035`.** 5 of 5 captures parse; every field read
   by hand off the subjects before being asserted. Two things the plan got wrong here, both worth
   keeping. It said **never `title_pattern`** — and this source cannot be built without it: its
   facts are in the SUBJECT (`<vendeur> vous propose <MARQUE MODELE …> à <prix> € à <Commune>`),
   while the body above the price line carries only the dealer's name, its rating and `vous
   présente ses bonnes affaires :`. The positional reader would have titled all five with that
   marketing sentence, and `gearboxFromTitle()` reads the title, so the two cards stating `DCT-7`
   and `*BOITE AUTOMATIQUE` would have scored as stating no gearbox. So `title_pattern` was made
   READ (against the subject) and left `UNREAD_PARAMS` in the same change, which is the discharge
   1c's comment asks for. And `make_model_pattern` alone was not enough: it matches the LINK, and
   leboncoin's is `/vi/<id>.htm` carrying neither make nor model — hence `make_model_source: title`,
   named rather than a link-then-title fallback. That matters to the SCORE: `brand_avoid` reads
   `make`, and an unextracted make scores 0 on the brand component (1d).

   **A latent cross-domain collision was found and guarded on both sides.** The rent and car
   leboncoin sources share a SENDER, a LINK HOST, and the `Voir l'annonce` string the rent source
   splits on. Nothing separates them but the subject, and they do not collide today ONLY because
   the vehicle alerts sit unlabelled in the INBOX while the rent source reads the alert folder —
   luck that expires the moment the routing filter is created. Both blocks now carry a
   `subject_pattern`, anchored on their own wording rather than negating the other's.
2. **AutoScout24 — BLOCKED ON AN INPUT, not buildable**: no per-listing alert has ever arrived
   (verified 2026-08-31 twice, the second time against the populated `car-watch/portails` label:
   only `autoscout24-news@` magazine issues and `autoscout24-info@` marketing — `Voitures de ville
   dans toutes les gammes de prix`, `Essai du Kia EV5` — never a per-listing alert). Wait for
   the first real alert; then capture, confirm the sender, build.
3. **La Centrale — MEASURED 2026-08-31, and it is NOT the config drop-in this entry assumed.**
   Three real alerts captured and scrubbed cleanly (`car-watch/portails`, twice daily). What the
   payload actually shows:
   - **EVERY link is an opaque tracking redirect** — `clicks.mail-alerte.lacentrale.fr/f/a/<token>~~/…`
     — carrying no listing id and no listing URL. `VehicleEmailSource` derives its identity from
     `basename(parse_url($link, PATH))`, so every card in a message would share one id, and cards
     across messages would each get a fresh one. That is SeLoger's problem exactly, and its answer
     was `id_from: content` — **which the CAR adapter does not have**. Building this source means
     porting content-addressing to `VehicleEmailSource` first, with the no-information floor and
     the within-message duplicate rule that travel with it. It is real engineering, not a config
     block, and the identity scheme must be chosen BEFORE the first enabled pass because nothing
     migrates a stored row between schemes.
   - A card reads `MERCEDES CLASSE C IV COUPE AMG` / `La Centrale 49 600 km` / `61 990 €` — so the
     facts are there, on separate lines rather than one facts line, and a `facts_pattern` of the
     ParuVendu shape will not read them.
   - **F7 confirmed and worse than recorded**: the subjects say `399`, `426`, `1071` new vehicles;
     the message carries a handful. Two subject shapes come from this sender (`NNN nouveaux
     véhicules correspondent à votre recherche` and `🚗 Votre prochaine voiture est peut-être
     ici…`), so a `subject_pattern` must admit both or deliberately pick one.
   - Polling stays REFUSED BY RULING (DataDome, hard rule 5).
4. **Agorastore — BLOCKED ON THE SCRUBBER, with the evidence measured.** It alerts daily from
   `support@agorastore.fr` into `car-watch/portails` and greets by name (`Bonjour M. <Prénom Nom>`),
   so the name needle is mandatory. `tools/scrub-eml.php` **REFUSES both captures**, correctly: the
   message carries `WyIyNjk4ZCIsInRha2llZGRpbmUu…`, which base64-decodes to the JSON array
   `["<list id>","<the subscriber's address>","<subscriber id>"]` — no JWT and no `eyJ` anchor, just
   an opaque blob with the address inside it. The verifier decodes and sees it; the strippers do
   not know the shape, so it refuses rather than writing a file the address is one `base64 -d` away
   from. **A generic "strip any base64 run that decodes to a needle" was written and REVERTED the
   same day**: it turned five of `tests/test-scrub-eml.sh`'s REFUSAL guarantees green-by-removal —
   the tool's whole safety model is *refuse what you cannot strip*, and a stripper broad enough to
   catch this is broad enough to silence the refusal for encodings nobody has seen. The next
   attempt must add a NARROW, shape-specific stripper and keep all 44 scrubber tests green,
   including the five that assert an unknown encoding still refuses.
   The `api.auctelia.com` polling route is unchanged and still needs checking against the auction
   ruling's closing-time requirement.
5. Skips, settled: Alcopa (refused both routes 2026-08-29), CapCar (blocked on the developer's own
   one-time browser check), Carizy (dead). Interencheres is MEASURED, pollable, low-priority
   engineering backlog — not blocked on any input.

---

## Track 3 — Full audit: four Gmail labels + both stores (mechanical, then case-by-case)

Method ruled by the developer: mechanical store audit + count reconciliation + targeted sampling —
NOT hand-reading ~2,600 mails. Findings are decided case-by-case WITH the developer, not built
unilaterally.

1. Label-level census (all four labels: `rent-watch`, `rent-watch/portails`, `car-watch`,
   `car-watch/portails`): count, sender and subject-template breakdown, cross-referenced against
   both `sources.json` `params.from`/`link_host` — flag any sender/template no configured source
   consumes (the leboncoin car-alert shape, generalised).
2. Structural audit over BOTH stores, complete not sampled: price-per-m²/per-room outliers;
   null/missing commune/location/title counts per source (incl. the 3 seloger + 4 pap empty-title
   rows — F6); per-source health history (`FEED_SILENT`/`STALE`, run-log gaps); outcome/
   notified_as distribution per source (flag 100%-UNKNOWN or zero-match sources).
3. Count reconciliation per source: the portal's own stated count vs what the run log ingested.
4. Targeted raw-email sampling only where 1–3 flag something.
### Track 3 step 1, the LABEL CENSUS — and the one it turned up

Message counts per sender, per folder, against what the configs consume:

```
                                  rent-watch/portails   car-watch/portails
alertes.seloger.com                      793                    0
no_reply@bienici.com                     214                    0
no.reply@leboncoin.fr                    208                  182
users-alertes@pap.fr                      51                    0
info@paruvendu.fr                          0                   41
info@mail-alerte.lacentrale.fr             0                   11
support@agorastore.fr                      0                    4
autoscout24                                0                    5
jinka                                     41                    0
```

Unmatched senders — mail no source consumes — are `jinka` (41, rent folder) and `autoscout24`
(5, car folder). Both are harmless rather than lost: since 2026-08-25 each source pushes its own
`FROM` into the IMAP `SEARCH`, so an unconsumed sender costs no other source's window. Jinka stays
out of scope (a 78-byte `text/plain`, and an aggregator needs its own §1 evaluation); AutoScout24's
five are newsletters, its per-listing alert still never sent.

**The 182 leboncoin messages in the CAR folder are not car alerts.** Running the source itself
against the live folder — the only test that settles it, because IMAP `SUBJECT` search cannot see
RFC 2047-encoded subjects and returned 0 for every probe — yields **0 listings**. The five real
`vous propose` alerts remain unlabelled in INBOX, which is what the owed developer action is about.

**And that run exposed F26.** It reported `broken · 6 runs consécutifs à vide alors que la référence
précédente était de 5.0 annonces` — a `SOURCE_BROKEN` verdict whose baseline was *my own offline
fixture run*, written into the live car store by `MAILBOX_DIR=… doctor`. `MAILBOX_DIR` swaps the
mailbox, not the database. Recovered by backing up (`tools/backup-state.sh`), deleting the one
provably-synthetic `source_runs` row by id, and re-running: `ok · 0 annonces`, which is the truthful
verdict for a source with nothing yet to read. Every documented offline proof in `CLAUDE.md` now
pairs with a throwaway DB.

> **A smaller thing found while backing up:** `tools/backup-state.sh` reported `0 annonces` for the
> car store. The copy is a full and integrity-checked one — no data risk — but it counts the rent
> `listings` table, and the car store keeps its vehicles in `vehicle_listings`. A confirmation line
> that says zero for a 3 536-row database is the wrong kind of reassuring.

### Track 3 step 2, the CAR store — and why a 100 % match rate is not a broken filter here

```
source     rows  no_title  no_snapshot  notified  MATCH  REJECT
autohero   3452  3387      3387         3449         62       3
paruvendu    84     0         0           84         84       0
```

**autohero's 3387 empty rows are the documented `--seed`, not a failure** — every one of them was
first seen inside a SINGLE hour (`2026-08-29T22`), and everything after it (6 rows on 08-30, 59 on
08-31) carries a snapshot and a real outcome. Seeding marks the back catalogue seen-and-notified so
it cannot flood the channel; `notified_at` being set is how that works.

**paruvendu never rejects, and that is the portal, not an inert filter.** Its 84 rows top out at
exactly `30 000 €` — the saved search applies `max_price_eur` before sending, the same shape as
Bien'ici on the rent side. The distinguishing evidence is autohero, which is a sitemap crawl with
no pre-filter: it rejects 3, and the boundary is exact —

```
35 990  REJECT      30 090  REJECT
33 990  REJECT      29 990  MATCH
```

That pairing is the whole point of auditing two sources rather than one. A source that never
rejects is exactly the shape a broken ceiling would take, and only a source WITHOUT a pre-filter can
tell the two readings apart. If autohero is ever dropped, the car ceiling stops being observable.

### The pattern-miss fix, confirmed on a LIVE pass (2026-08-31/09-01)

A fixture run proves the parser; only a live `doctor` proves the ratio an operator reads.

```
bienici   commune_pattern   117/364   ->   3/250
```

114 furniture segments left the denominator, which now counts LISTINGS (250) rather than segments
(364); 3 genuine misses remain. **seloger's `residence_pattern 201/399` and `title_pattern 4/399`
are UNCHANGED, and that is equally the point** — measured, that source carries only ~3 furniture
segments, so its numbers were genuine all along, and a fix that had moved both would have been
moving the wrong thing. (The 4 title misses are F10/F11 above.)

`doctor` exits 1 here and that is CORRECT, not a fault: it returns `$problems > 0 ? 1 : 0` and the
one problem is `leboncoin feed_silent` — true, that portal has sent nothing since 26 August and its
routing filter does not exist yet.

### Redeploying is not `docker compose up -d` — F25, hit twice on 2026-08-31

Both watchers set `stop_grace_period: 5m` and `WatchLoop` stops only after the pass in flight
finishes, so a recreate can sit for minutes. Compose renames the old container while it waits, and
that is where it goes wrong:

- **Attempt 1** failed outright — `Error when allocating new name: Conflict. The container name
  "/scout-car-scout-1" is already in use by container ec3ce4f13a26…` — `up exit=1`, rent-scout left
  in `Created`, i.e. NOT RUNNING.
- **Attempt 2** wedged for ~13 minutes with `d9272b63ebf1_scout-car-scout-1` in `Created` beside a
  still-`Up` original, rent-scout again `Created` and down.

**Nothing announced either.** `docker compose ps` (no `-a`) simply omits a non-running service, so
the failure looks like a shorter list — the same silent-absence shape hard rule 2 is about, one
layer down in the deployment.

Recipe that works, and check the IMAGE ID rather than trusting the command:

```bash
docker compose build
docker compose ps -a --format '{{.Service}} {{.Name}} {{.Status}}'   # -a, always: a down service is INVISIBLE without it
docker rm -f <any hex-prefixed leftover>                            # e.g. d9272b63ebf1_scout-car-scout-1
docker compose up -d --no-deps <service>                            # one service at a time
docker inspect --format '{{.Name}} {{.Image}}' scout-rent-scout-1 scout-car-scout-1
docker image inspect scout:local --format 'current {{.Id}}'          # all three must match
```

A watcher down is worse than a stale one: a stale watcher still pushes, wrongly; a stopped one
pushes nothing, and *nothing arriving* is exactly what a quiet market looks like.

### Track 3 — STARTED 2026-08-31. The structural half of step 2, over the rent store.

```
source       rows  no_title  no_snapshot  notified   MATCH DIGEST REJECT
seloger      529   4         0            241        227   4      298
inli         469   0         36           121         76   0      357
cdc_habitat  445   0         18            86         77   4      346
bienici      245   0         0            165        161   2       82
logirep      120   0         3             10          0  10      107
cityloger     60   0         1              1          1   0        58
pap           51   0         0             45         45   0         6
leboncoin      3   0         0              1          1   0         2
```

Read carefully, because two columns look like defects and are not. **`no_snapshot` is
pre-v7 rows**, not a failed capture — `evidence_json`, `outcome` and the commune all go null
together on exactly those rows, which is the documented non-backfill. And **`logirep`'s 0 MATCH /
10 DIGEST is correct**: its rent is `h.c.`, so `max_rent_cc` never fires and the ceiling is
unverifiable there by design.

Two findings that ARE real:

- **PAP's 21 null-surface rows have SELF-HEALED, and the plan's stated cost was wrong.** The
  Decisions Log says *"No backfill of the 21 null-extraction rows — stated cost, they stay null"*.
  Measured after 1h landed: **0 null surfaces and 0 null rooms on every PAP day, 26–31 August.**
  `Store::record()` rewrites the snapshot on every sighting, so a row whose ad is still published
  re-extracts itself on the next pass once the patterns work. The cost that DOES stand is the one
  nobody stated: the `outcome` and `notified_at` of the passes that ran while the surface was null
  are unchanged, so listings notified as MATCH on a null surface stay recorded that way — the
  evidence is repaired, the history of what was announced is not. A row for a delisted ad would
  keep its nulls; none did.
- **F23/F24 above** — SeLoger's empty titles, with the cause measured rather than guessed:
  `title_pattern` is `~^\h*(?!https?://)([^\n€]{2,80}?)\h*\n(?:\h*\n|\h*https?://[^\n]*\n)+\h*\d+\h*pi[eè]ces?\b~mu`.
  The `[^\n€]` class forbids `€` in the captured line — a guard against capturing the rent line —
  and a `Baisse de prix` card's own agency text begins `600€ TOUT COMPRIS – électricité…`, so it is
  refused and the title comes back empty. The other two rows have no `pièces` line at all, so the
  anchor is absent.
  **F23 IS NOW FIXED** (`d60a183`), and the route is worth repeating. The two `Baisse de prix`
  messages still in the IMAP window were captured — `php tools/dump-eml.php alertes.seloger.com 40
  var/claude/captures 'rent-watch/portails'`, note the sender is a DOMAIN not an address — scrubbed
  and frozen as fixtures 004/005. **Neither reproduces the failing variant**: both extract a title,
  because the `€`-leading rows had aged out of the window. What stood in for them is better than a
  synthetic guess — schema v7 stores the card body a verdict was formed from, so the failing layout
  was read back byte for byte and encoded in `PriceLedTitleTest`. Measured over every stored seloger
  snapshot: 552 unchanged, 2 gained a title, 0 changed, 0 lost.

  **F24 IS NOT FIXED**, and it is not the same bug: the anchor IS the `pièces` line, so a card
  stating no room count has no anchor at all regardless of the `€` rule. Fixing it needs a second
  anchor and a captured card of that shape to measure one against.

5. Deliverable `var/claude/audit-<date>.md` with the falsifiable checklist: per label and per
   source it MUST state (a) message/row count, (b) reconciliation result, (c) unmatched
   senders/templates, (d) structural outliers. Missing any of the four per label/source = the
   audit fails verification.

---

## Track 4 — Next milestone (design first, do not build blind)

After Track 0 closes and Track 1 lands:

- `tests/test-vehicle-guard.sh` — the §1 tripwire (`tenure-guard.sh`) greps housing vocabulary
  only; the car domain's excluded-vehicle classifier has no tripwire. Same must-fire /
  must-stay-silent halves as `tests/test-tenure-guard.sh`.
- Generic-store split: `VehicleStore` composes the rent `Store` directly.

  **THE DESIGN PASS IS DONE (2026-09-01), AND IT SHRANK THE ITEM BY AN ORDER OF MAGNITUDE.** This
  entry used to read: *38 files reference the rent Store; **93 sabotage-ledger expressions** are
  path-anchored to `src/php/Rent/Store/Store.php`; the live `state/car-watch.sqlite3` already
  contains the composed rent tables … getting the migration wrong is a hard startup failure on a
  live domain.* Every number there is real and the conclusion drawn from them was wrong — this
  repo's own named failure, a true number attached to an invented cause. Measured:

  - **The car domain uses SIX methods of the rent `Store`** — `recordRun`, `health`, `shouldAlert`,
    `markAlerted`, `clearAlerts`, `journalMode`. That is the run log, source health and the alert
    cooldown. All generic; not one is housing-specific.
  - **6 of the 94 anchored ledger expressions touch that surface.** The other 88 test housing
    behaviour and do not move. `94` was never the blast radius of this change.
  - **It moves CODE, not DATA.** The rent-owned tables inside the car file are EMPTY — `listings` 0,
    `price_history` 0, `listing_detail` 0, `commute_cache` 0 — while the data that matters lives in
    `source_runs` (422) and the vehicle tables. `source_runs`/`source_alerts` are created
    `IF NOT EXISTS` and their schema does not change, so an extracted run log opens the same tables
    it already opens.

  **The ONE real design question**, and the only place a mistake is destructive: which schema-version
  key the extracted run log owns. The car file carries BOTH `schema_meta` (rent v12, written by the
  composed `Store`) and `vehicle_meta` (v1), and `Store::open()` refuses a schema newer than it
  knows — so a run log that keeps reading `schema_meta` inherits the rent version for ever, while
  one that ignores it must not trip the existing refusal. Settle that before writing code, back up
  first, and never run it against `state/car-watch.sqlite3` without the developer's explicit go.
- Its own MAXIMAL certification (frozen commit, two consecutive clean rounds), separate from
  Tracks 0 and 1.
- Verification: vehicle-guard both halves demonstrated; full suite + ledger green post-split with
  ZERO expressions pointing at a dead path (`grep -c "Rent/Store/Store.php" tests/sabotage-check.sh`
  before/after, reconciled); `state/car-watch.sqlite3` opens post-migration with row counts
  preserved on every table.

---

## Track 5 — Generalisation: every per-source fix is a candidate rule for ALL sources

Developer instruction, 2026-09-01. This repo's whole history is per-source repairs — `commune_pattern`
for SeLoger, the card separator for Bien'ici, positional anchors for PAP, prose readers for In'li —
and **each was measured on the source that hurt, then left there.** Nobody has ever asked the second
question. The entry point was the developer noticing a Bien'ici listing that is actually CDC
Habitat's.

### 5a. The advertiser is evidence, and nothing reads it — a live §1 breach

**Measured 2026-09-01 over `state/rent-watch.sqlite3`** (reproducible; the scan folds accents and
looks in `evidence_json`'s title+description):

| Portal | In'li | CDC Habitat | Immobilière 3F | RIVP | total |
|---|---|---|---|---|---|
| seloger | 16 | 7 | 2 | 1 | **23** |
| bienici | — | — | — | — | **0** |

All 23 classified **`LIBRE`, confidence 50** — the source default — and **21 pushed as `MATCH`**.
Six carry a v12 twin (4 `inli`, 2 `cdc_habitat`); **17 do not**, and that 17 is the fix's size.

> **THE FIRST VERSION OF THIS TABLE SAID 24, WITH ONE ON BIEN'ICI, AND THE EXTRA ROW WAS THE
> FAILURE CLASS THIS WHOLE FIX EXISTS TO PREVENT.** The scan looked for the alias `i3f`, which
> matched inside a **base64url JWT signature** — `…vgdb-d5xbqjfxsvc5tsiri3fo0ug2y4b…` — on a
> *Century 21* listing. An acronym found in opaque machine text: `Ce·lli·er`, `Plain-pied`,
> `?c=plai_plus` a sixth time, committed in the audit that found the bug. It is kept here rather
> than quietly corrected because the number was about to be quoted in a commit message.
>
> **Consequence: the developer's Bien'ici observation is NOT REPRODUCIBLE and the Bien'ici half of
> this item does not exist.** Zero of 254 stored Bien'ici rows name an institutional landlord, and
> a Gmail search over `in:anywhere` with no date restriction returns **zero** Bien'ici messages
> mentioning CDC Habitat, In'li, Immobilière 3F or RIVP, ever. The phenomenon the developer saw is
> real — 23 instances of it — and every one is SeLoger. Either the portal was mis-remembered, or
> the advertiser was a subsidiary trading under another name, in which case no anchor can see it.
>
> **And "Bien'ici puts the advertiser in the URL slug" was unfounded**, asserted from one example
> (`iad-france-800209`) without reading the census that was already in the transcript: the 254
> slugs are CRM vendors and agencies — `immo-facile` 30, `laforet-immo-facile` 16, `nestenn` 8,
> `iad-france` 7, `hektor-*`, `apimo`, `nockee-*` — and **no landlord**. If a landlord ever
> advertises there, the slug is the place to look; today there is nothing to anchor on, and
> building a body scan instead is what this design refuses. **Stated cost: Bien'ici is uncovered.**

**Why it is §1 and not a nicety.** CDC Habitat is a mixed-tenure landlord — `CLAUDE.md` says so in
its own words, *"social and intermediate stock on the same pages, sometimes in the same result
set"*. Its own source block is `mixed_tenure: true`, so a card of its stock stating no tenure goes
to the *à vérifier* digest. Routed through SeLoger it inherits `mixed_tenure: false` +
`default_tenure: LIBRE` and is pushed as a match. **Same flat, same evidence, opposite verdict,
decided by which mailbox it arrived in.**

**The user's premise was half right and the half matters:** this is NOT handled on SeLoger either.
`CLAUDE.md` records it as a known "residual" on the grounds that *"PLAI and PLUS are allocated by
commission and are not advertised on commercial portals; PLS occasionally is"*. That reasoning
covers a card from an ANONYMOUS advertiser. It does not survive a card whose advertiser announces
itself as a bailleur in the subject line.

**The advertiser is STRUCTURAL, which is what makes this safe to build.** Every hit is the message's
own opening line and subject:

```
CDC HABITAT vous adresse ses dernières exclusivités      <- ~201 such messages
IN'LI       vous adresse ses dernières exclusivités
NESTENN IGNY vous adresse ses dernières exclusivités
```

A vocabulary scan over the body is REFUSED — `au plus près`, `Ce·lli·er`, `En savoir plus`,
`Plain-pied`, `?c=plai_plus` are five paid-for instances of exactly that mistake, and a landlord
name in prose (`proche résidence CDC Habitat`) rebuilds it in reverse.

**Mechanism — a per-listing override of the SOURCE DEFAULT, nothing else:**

- New per-source param `advertiser_pattern`, compile-checked at load beside the other five. It reads
  the **SUBJECT**, not the segment — `matchParam()` runs over segment text and the SeLoger advertiser
  is not in it. The car domain already built this exact shape for its own `title_pattern`
  (`$fromSubject`); reuse that plumbing rather than inventing a second one.
- It joins the **PatternMissLog** counted set. When SeLoger renames the `exclusivités` template the
  miss must surface in `health()`, not silently revert this whole item — that is the F1/F3 lesson
  (PAP's anchors broke for four days and nothing counted the misses).
- `RawListing::$advertiser` (`?string`). It lives on the listing, not as a `judge()` argument —
  `scout --domain=rent reclassify` re-judges from the v7 snapshot alone, and a value passed
  alongside would be absent on every re-judge. This is the `commuteMinutes` lesson, already paid for.
  The snapshot encoder covers every constructor parameter BY REFLECTION, so it is carried for free —
  assert that rather than assume it.
- A recognised advertiser makes the listing inherit **that landlord's own** `default_tenure` /
  `mixed_tenure` — never a third invented treatment. That is literally the sentence *"the verdict
  must not depend on the route"* in code. Tiers 1–3 are untouched and still win: an explicit label
  beats an advertiser, always.
- The landlord table is an **EXPLICIT REGISTRY**, pinned by a test that asserts every institutional
  source block appears in it. Pure derivation from `sources.json` was the first design and **the 23
  rows refute it**: RIVP has no source block at all (measured out of scope, A14), so derivation
  leaves an RIVP-advertised card at the LIBRE default — the exact hole being closed; *Immobilière
  3F* maps to a block named `cityloger`; *IN'LI* does not fold to the key `inli` by any rule. So
  aliases and non-source landlords are needed regardless. The no-second-list concern is real and is
  answered by **pinning**, not by derivation — a test that fails when a source block is missing from
  the registry cannot drift, whereas a derived map silently covers only what happens to be enabled.
- **A recognised advertiser with NO source block inherits the fail-closed posture**
  (`mixed_tenure: true`, `default_tenure: null`) — i.e. digest unless an explicit label decides it.
  RIVP is predominantly social; guessing LIBRE for a landlord nobody has measured is the §1-dangerous
  direction.
- Outcome for a recognised mixed-tenure advertiser with no explicit label: **DIGEST**, via the
  existing v8 `notified_as` machinery. Never a hard reject (hard rule 8: silent over-rejection is
  invisible).

**Stated costs, all four real:**
1. The ordinary multi-card SeLoger alert names no advertiser per card, so this reaches the
   `exclusivités` template only. That template is where the landlords are, but it is not all of them.
2. No backfill. The 21 already-notified rows stay as they are; `reclassify` will re-judge them once
   the snapshot carries an advertiser, and it carries one only for rows captured after the fix.
3. **Bien'ici is uncovered** — it has no advertiser surface today (see the correction above).
4. **16 of the 23 are In'li, and they will now ALL digest, permanently.** In'li inherits
   `mixed_tenure: true`, an email-route card carries weak evidence, and its detail page can never be
   read on that route — so the fail-closed rule digests every one. That is correct under §1 and it
   is the majority of the affected rows: the developer will see the SeLoger match yield drop and the
   *à vérifier* digest grow by roughly that much. Named here so the change is not mistaken for a
   regression. In'li was itself proven not pure LLI (two live PLS listings), which is why this is the
   right answer rather than an over-correction.

**Tests:** corpus cases BOTH directions (advertiser-anchored card digests; a prose-only mention does
NOT flip an otherwise-clear card), the counterweight (an unrecognised advertiser changes nothing),
a round-trip through the v7 snapshot, and a sabotage case. Touches `src/php/Rent/Core` → MAXIMAL at
the milestone.

### 5b. The mechanism × source matrix — build it mechanically, then triage WITH the developer

Method mirrors the ruled Track 3 approach: measure every cell, write findings to
`var/claude/track5-matrix-<date>.md`, decide case by case. Do **not** build every row unilaterally.

Cells to fill, per source, both domains (the non-config mechanisms matter as much as the params):
criteria-line exposure (the PAP/Bien'ici `45 m² min` class — seloger and leboncoin have NO
positional anchors and rely on the first-match-wins scan); `link_host` path-vs-host (the PAP phantom
`/utilisateur/alertes` listing); `subject_pattern` presence; `exclude_title_patterns` reachability
(needs a non-empty title on every source); separator variant lines (Bien'ici's `Pas de photo`);
prose readers (`prose:floor` / `prose:elevator`, In'li-only today); `detail_map` presence on the
HTML sources; the advertiser surface from 5a; and the car domain throughout.

**The standing rule this leaves behind matters more than the one-off audit:** a per-source fix does
not land without a line saying which other sources were checked against it.

## Explicitly NOT in this plan (negative space — protects the fresh executor)

- **Do not "fix" drift-scan S8** (1b) — commented keys are documented by design.
- **Do not write "SeLoger cards have no description"** anywhere (1f) — false, and it would get
  `exclude_patterns` "fixed" into uselessness.
- **Do not configure `postcode_pattern`/`title_pattern` on car sources** (F4) — loaded, unread.
- **Do not follow SeLoger's per-recipient redirect at ingest** — hard rule 5; documented non-goal.
- **No CAPTCHA/anti-bot circumvention ever** — La Centrale/leboncoin polling, A15's shield: ruled.
- **`src/phorj/`** — on indefinite hold (2026-08-19). Not touched.
- **AL'in** — before any work: ONE logged-in look (developer's own browser) at what the account
  shows WITHOUT a NUR settles blocked-input vs §1 dead end. Obtaining a NUR runs through
  `demande-logement-social.gouv.fr` — out of scope by hard rule 5.
- **VPS/deploy kit** — confirmed not needed.
- **Editing any gate's own hook/config** — standing ruling; sentinels only, already in place.
- **Rewriting git history** for the name-in-fixtures incident — ruled: leave history, forward fix
  only (Track 2 step 0).

## Inputs still owed BY THE DEVELOPER (nothing else blocks)

1. AutoScout24: the first real listing alert (none has ever arrived — wait, don't build).
2. ~~AL'in: one logged-in look without a NUR.~~ **WITHDRAWN 2026-09-01** — AL'in is on indefinite
   hold like `src/phorj/` (developer ruling). Not an input; not work.
3. ~~CapCar: the one-time make-selector browser check.~~ **DONE 2026-09-01** — the developer created
   the alert. Now waiting on its first message, like AutoScout24.
4. ~~Gmail filter for leboncoin "vous propose".~~ **DONE 2026-09-01, verified** — all 5 messages
   carry `car-watch/portails`; confirmed over `in:anywhere`, no date or read-state restriction.
   The source is quiet (nothing since 2026-08-27), not broken.
5. ~~Track 0 round 4's close-vs-round-5 decision.~~ **RULED** — Track 0 closed uncertified.
6. **Go to run against the live `state/car-watch.sqlite3`** (Track 4's store split). Not yet given;
   the re-explanation of what the item IS was not authorisation. Build up to that line only.

## Verification (whole plan)

- Step U: drift-scan exit 0; no non-archive `docs/plans/` citation left; JSON configs parse; suite
  green; STANDARD certification passed.
- Track 0: 6-of-574 filter pre-check, 6/6 detected, round-4 verdict pasted verbatim.
- Track 1: each item's own verification block above; plus full suite, `tests/sabotage-check.sh`
  (JIT workaround), and every `tests/test-*.sh` green before each commit; Track 1's own
  certification round after it lands (MAXIMAL — it touches `src/`, `config/`, `tests/`).
- Track 2: scrubber test red→green on the `To:` case; each new source's
  `scout --domain=car doctor --source=<name>` returning a real count offline against its scrubbed
  fixture; La Centrale's blind spot documented in its config comment.
- Track 3: the audit report satisfies the four-part checklist per label/source; reviewed with the
  developer before anything from it is built.
- Track 4: its own verification block above.

---

## Round 5 (2026-08-31) — NOT CLEAN, 25 findings across three lenses

All three lenses reported against `a3c09c4`. **Every number in the round-4 commit message was
independently re-run and verified true** (2 339 tests, scrubber 40/40, all `test-*.sh`, 585/585
expressions, drift 0/0/0, the 8-case ledger, 13 fixtures 0 hits). The findings are about what those
numbers do not cover.

Fixed and pushed during round 5: the recursive-decode P0 (`8f0c526`), the false-healthy car beat
(`b4de3dd`, found by two lenses), plus two corrections of errors introduced in round 4 — Q39's false
"reversed by one line" claim, and a `StoreTwinTest` docblock that asserted the opposite of its own
assertion.

**Still open, by theme.** Full detail in `var/claude/track0-round5.md` (gitignored scratch — the
register rows F14–F21 above are the tracked record).

1. **A fix landing on ONE of two symmetric surfaces — the round-4 P0's own shape, three more times.**
   `car doctor` never got `pendingRefusal()` (F17); the twin scan's third §1 surface has no ledger
   case (F19); the PII fix covers the working tree while the pushed history still carries it.
2. **Repair routes that do not exist** (F20). Both documented ones fail, and the same-track group
   veto has no OPEN-QUESTIONS entry the way the cross-track one now does.
3. **Fail-OPEN on an unrecognised tenure** (F21), and it is worse than F21 records: the same
   `Tenure::tryFrom()` releases the row's own durable reading, the schema-v4 group veto AND the
   schema-v12 twin veto — three §1 mechanisms, silently, on one case-flip or enum rename.
4. **Guards whose mechanism is untouched.** `refute()` in `tests/test-scrub-eml.sh` reports `ok` for
   ANY non-zero exit, including 2 and 127 — the round-4 fused-comment bug was fixed as an instance,
   not as a class, in the one file whose subject is a privacy guard. `FixtureSecretsTest` still has
   no name-or-address pattern, and `docs/ALERT-CAPTURE.md` never tells the operator to pass a name
   needle — so the next capture from a portal that greets by name reproduces the leak with both
   guards green.
5. **Dead safety code, by this repo's own definition.** `durableOwnReading()` at the judging step is
   now provably unreachable-as-a-change (deleting it, and instrumenting it to throw if it ever
   alters anything, both leave 2 339 tests green). The round-4 commit called it "left in place as a
   defence"; `Pipeline.php`'s own comment says such code is "worse than none". Remove or cover it.
6. **New code with no coverage**: the `:memory:` branch on both CLIs; and one re-scrubbed ParuVendu
   fixture gained a 152-column QP line, so it is no longer byte-what-the-mailer-emitted.

---

## Round 6 (2026-08-31) — NOT CLEAN, 21 findings; developer ruled "fix these, then move on"

Three lenses against `b8a1687..7765997`. Every number in every round-5 commit message was
independently re-run and verified TRUE; the findings are about what those numbers do not cover.

**The developer's ruling (2026-08-31): close round 6's findings, then START TRACK 1 rather than
running round 7.** Rounds 4/5/6 produced 19/25/21 findings and never approached the
two-consecutive-clean bar; each round costs ~5 h and the other four tracks were untouched.
**Track 0 is therefore CLOSED UNCERTIFIED, and that is a ruling rather than an omission.** What
stands in its place: the sabotage ledger (588 cases), the full suite, drift-scan, and live `doctor`
runs against the deployed image.

### Fixed

- **P0 — the twin veto was not TRANSITIVE.** Each twin contributed its own durable reading plus its
  own group veto but never its own TWIN-derived verdict, so an exclusion reaching a listing THROUGH
  a twin never reached that listing's OTHER twins: a second copy of a flat rejected as PLS was
  pushed as a MATCH naming the rejected route as the *voie directe*. The graph is now resolved to a
  fixed point across every edge BEFORE any survivor is judged — writing the judged reading back
  mid-loop is not enough, because sources are harvested institutional-first. **Stated cost:** the
  veto is now transitive over a connected component, so a chain A–B–C vetoes both ends even where A
  and C would never have been linked directly. §1's bias is toward not notifying; Q39 already
  prices pairwise over-linking as permanent.
- **P0 — the documented capture procedure defeated the address scrub** (needles before the address).
  See `5726222`.
- **P1 — a ledger case reddened on an undefined method**, found by all three lenses, and the CLASS
  behind it: `test-sabotage-applies.sh` now also checks every replacement names a live method. It
  found a second instance immediately.
- **P1 — the forced `--once` beat reported a hard-coded 0 notified** on both CLIs, re-introducing a
  defect the ledger already pins on the watch path; **the car forced beat swallowed its send
  failures**; and **the car half had no test at all** (deleting the branch left the suite green).
- **P1 — CDC Habitat was `broken / 0` for a day** on a message that named an untrue cause. Now
  `ok / 312 annonces`, verified live.

### Recorded, NOT fixed — the developer's ruling caps this milestone

| # | finding | why it is left |
|---|---|---|
| R6-1 | PAP `commune_pattern` cannot read an arrondissement (`Paris 16e`) | **CLOSED 2026-09-01** (`c95ddb8`) — the capture may now contain a digit but not start with one; all four frozen captures identical, reverting reddens 3 tests. The false `_why` (three patterns anchor on the postcode, not four) is corrected |
| R6-2 | A hard-wrapped PAP criteria line would still hand the search floor to `surface_pattern` | Unverified against any real payload; n=4 captures all put it on one line |
| R6-3 | The forwarded-as-attachment capture route defeats both secrets layers (`from` not dropped; embedded `message/rfc822` sits after the blank line; `Resent-To` in neither list) | Latent, not an active leak — the current tree is clean four levels deep |
| R6-4 | The wrapped-token stripper's left anchor is blind in quoted-printable (`?u=3D<blob>`), so it fails CLOSED on the very shape every real capture uses | Refuses rather than leaks, but the refusal is unresolvable — which invites hand-editing |
| R6-5 | Omitting the address makes the scrubber a silent no-op that reports success | **CLOSED 2026-09-01** (`c95ddb8`) — the address is REQUIRED, no opt-out. The load-bearing half was that the RECOVERABILITY check had no needle and passed vacuously. 6 assertions, both directions |
| R6-6 | The `=3D`-eaten escape is permanently baked into two ParuVendu fixtures (originals not in the repo) | Parse-identical; the tool is fixed so it cannot recur |
| R6-7 | QP line length regressed 4× in the Bien'ici fixtures (max 121 → 196) | Parse-identical: link counts and body lengths byte-stable |
| R6-8 | No Decisions Log entry for the round-5 fix commits; four register rows read FIXED and OPEN at once; `Pipeline.php:829` citation drifted; 7 dangling links inside `docs/plans/archive/**` | Bookkeeping. The first is a real breach of this file's own rule |

**Do not read round 5's verdict as a setback.** Round 4 fixed 19 findings and round 5 found that
several landed on one surface of two — which is the same defect class the milestone has now produced
at every round. That pattern, not any single finding, is the thing to fix: **before closing a
finding, enumerate every symmetric surface it has (rent/car, read/write, survivor/absorbed,
tool/guard) and say which ones the fix covers.**

---

## Track 6 — 2026-09-01 full-audit execution (all four clusters selected; rulings in the Decisions Log above)

> **Executor briefing.** Evidence for every item: `var/claude/audit-2026-09-01.md` (synthesis) and
> `var/claude/raw/audit-{rent-store,car-store,plan-vs-tree,config-coherence,gmail}-2026-09-01.md`
> (every query + raw output). Read the synthesis before building anything. Standing traps, all
> paid for at least once: pair every `MAILBOX_DIR=` proof with a throwaway DB (F26); scrub every
> new fixture with the address AND name needles (Agorastore and CapCar greet by name); never
> follow a tracking redirect at ingest (CapCar links are `sendibt3.com` per-recipient tokens —
> SeLoger's class); `/bin/grep` is ugrep (plain `|`; `-a` on Latin-1 fixtures); ledger runs need
> the JIT workaround; timestamps are `T`-separated (never compare against `datetime('now')`
> strings); trial any new pattern over the stored rows before shipping; sabotage cases for every
> guarantee whose branch no fixture reaches. Certification boundaries are the reviewing session's,
> not the executor's.

### 6-A — live-risk fixes (first: A1 and A2 are live loss/risk today)

- **A1 In'li degradation** (audit N1). 23 of 94 passes failed 2026-09-01 (20 s connection
  timeouts + one bare HTTP 302 on the index), climbing 2→11→12→23/day; health `ok` throughout
  because interleaved passes still return ~165 items. Investigate FIRST, fix second: read the
  302's `Location` once (one request, hard rule 5 posture unchanged), check whether the timeouts
  correlate with pagination depth or time of day (the run log has `duration_ms`), and only then
  decide between (a) a failure-RATE health signal (N% failed over 24 h — new verdict, needs its
  own counterweight run showing sources it does NOT fire on), (b) pacing changes, (c) nothing but
  the report. A timeout increase without measurement is the anti-bandaid gate's named case.
- **A2 car loader allow-list** (audit N4/C1). Port the rent side's EMAIL_ALERT_PARAMS-style
  allow-list refusal to `VehicleSourceLoader`: any param key no car adapter reads is a
  `ConfigError` naming the file. The docblock already promises this; make it true. One
  table-driven test; also cover the ~21 untested car refusals while in the file (audit N10).
- **A3 ListingMapper bundle** (F27b + audit N5 + N6). One pass over the one funnel: (1) instrument
  `ListingMapper` with `PatternMissLog` so inli/cdc_habitat/cityloger/logirep and `DetailHydrator`
  count null-yielding selectors/paths — this finally answers cityloger's 9-null-surfaces-of-60
  question with data; (2) make `$map->tenureField` actually read on the JSON path (it is inert
  today; fixture_demo passes by coincidence — §1-adjacent latent); (3) run the rent plausibility
  band on mapped rents (7 stored history rows at 119–290 € came through the html path unbanded).
  Gate the CLI report on `CountsPatternMisses`, as both CLIs already do. Sabotage cases for each
  half; the miss-print itself is unpinned in both domains (audit N11) — pin it while here.
- **A4 brand `autres` bypass** (audit N3). A ParuVendu DS4 stored `make='autres'` from the portal
  ad path `/autres/autres/` and took the full 10-pt brand share. Preferred mechanism: when the
  path token is a category word, read the make from the title (`Ds Ds4 E-tense` states it);
  fallback mechanism: map category tokens to null (honest, unknown-make arm applies). Trial over
  all stored makes; the existing zero-over-reach sabotage cases must stay green.
- **A5 score-floor batching + recalibration** (ruling above). Two halves, ordered: (1) the
  batching — individual push only at score ≥ `high_priority_score`, the rest through the existing
  digest drain (`Cli/DigestBatch` is the shared landing zone; do NOT build a second one); (2) the
  recalibration — re-judge stored snapshots offline (the 2026-08-26 precedent: no poll needed),
  measure the distribution per component, present 2–3 weight options with their concrete effect
  on the developer's own recent matches via AskUserQuestion, apply the chosen one, then re-check
  `high_priority_score` still marks a sane fraction (the car 73 was calibrated 2026-09-01; rent 50
  predates commute's current shape).
- **A6 SeLoger surface-reader verification** (audit N2). One query on 2026-09-02+ data: rows
  first-seen ≥ 09-02 with extracted surface ≤ 12 whose title states a larger figure. Zero → the
  deployed fix stack covers surfaces too; close N2. Non-zero → the surface reader needs the same
  positional repair the rooms reader got (Track 1j's shape), measured over the store first.
- **A7 F28 one-shot victims report** (ruling above). Read-only script printing the ~7 misread-
  surface REJECTs (source, title, stored vs title-stated surface, link). No store writes. Hand
  the output to the developer; done.

### 6-B — new car sources (payloads banked in Gmail; build offline)

- **B1 CapCar** (`contact@capcar.fr`, first real alert 2026-09-01 18:00). Structured labelled
  fields per card (`Marque/Modèle/Finition/Motorisation/Carburant/Boîte/Année/Kilométrage/Prix`),
  4 cards/message. Links are per-recipient tracking redirects → identity is CONTENT-based (the
  SeLoger discipline: no-information floor, rent/price out of the key, duplicate-in-message
  announced). n=1 — state it, and let the second alert be the regression test. `params.from`
  scopes to `contact@capcar.fr`; note `capcar.fr` also mails from agent addresses (noise).
- **B2 La Centrale** (`info@mail-alerte.lacentrale.fr`, ~2/day since 08-27, ~10 payloads banked).
  Build as ruled (Track 2 step 3): `feed_silent_days: 3`, truncation cost (email carries ~3 of
  900+ stated cards — F7) documented IN THE CONFIG COMMENT, polling refused by ruling (DataDome).
  `params.from` must exclude `mail-marketing` and `mail-rachat` senders.
- **B3 Agorastore** (`support@agorastore.fr`, daily) — optional third, price-only truncated email
  (Track 2 step 4's shape). Greets by name: scrubber needles mandatory on every fixture.
- AutoScout24 stays BLOCKED (mailbox-verified 2026-09-01: still zero real alerts; likely future
  sender `savedsearches@notifications.autoscout24.com`). The leboncoin car Gmail filter is DONE
  (messages labeled) — strike it from Inputs-owed.

### 6-C — hygiene + certification

- **C1 one docs commit** syncing the register to the tree (audit N7): F1/F5/F10/F15/F16/F17/F19/
  F24 → their true CLOSED/FIXED state with commit refs; fix the five State/Where cell
  disagreements; F26's stale count in F1 ("23 null rows" → healed, 0); the two stale pre-collision
  id citations (Track 3's "F10/F11", "3 seloger + 4 pap"); the stale `"7"` in car leboncoin's
  `_feed_silent_days` comment; fold `pap-detail-hydration.plan.md` back under this file (archive
  it with the SUPERSEDED banner and copy its prose_absent ruling's pointer into the log above) so
  the single-source ruling holds; strike the done items from Inputs-owed.
- **C2 certification round** (audit N8): freeze AFTER Track 6-A/C1 land (or at the reviewing
  session's discretion), then ONE MAXIMAL round covering the entire uncertified span since
  `7765997` — 45+ commits (Tracks 1, 2, 4, 5, T5B, this track). One panel for one backlog is the
  economize ruling's shape; run by the REVIEWING session, not the executor.

### 6-D — rulings: ALL RESOLVED 2026-09-01 (see Decisions Log) — F30 drop is one config edit
(land it with A-cluster work); the rest are embedded in A5/A7 and the T5B-7 config line.

### Explicitly out of scope for Track 6
- Redact `%40` and redirect-vs-robots: still open by the developer's explicit earlier choice —
  touch neither without a new ruling.
- In'li: no headless browser, no fingerprinting, no timeout raise without measurement.
- The 12 historical rooms-misread MATCHes already pushed: noise already spent; nothing retracts a
  push. Only A7's read-only report looks backward.
