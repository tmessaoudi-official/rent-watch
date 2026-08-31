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

---

## Fragile implementations register (the developer asked; keep this list honest)

| # | Surface | State | Where handled |
|---|---|---|---|
| F1 | PAP positional anchors (all FOUR params) | **BROKEN since ~08-28**, and worse than recorded: 100% null on 08-29/30/31, **23 null rows, 19 notified MATCH**. Two independent axes — the body layout changed, AND all four params anchor on `\(\d{5}\)`, which 3 of PAP's location shapes do not carry | Track 1h — patterns measured, 39/39 vs 16/39 today |
| F1b | PAP department-only / arrondissement variants | `Cergy (95)`, `Paris 16e` carry no 5-digit postcode, so title+commune+surface+rooms all fail together; 4 rows, all `REJECT` with an empty title. **This IS F6's "4 pap" rows — one root cause, not two** | Track 1h (new) |
| F2 | Bien'ici single-card "Une annonce" reader | **BROKEN** — reads the search-criteria line ("45 m² min") as the flat's surface; 5 real matches silently REJECTed, 2 more on 08-31 | Track 1g |
| F3 | Extraction failures are invisible | A configured pattern that misses yields null "visibly" — but nothing counts misses, so F1 ran 4 days unnoticed | Track 1h (health half) |
| F4 | `postcode_pattern` + `title_pattern` on the CAR side | Declared in `VehicleSourceLoader::PATTERN_PARAMS`, read by ZERO adapters — configuring them loads fine and does nothing | Track 1c (documented, fix owed) |
| F5 | Coliving exclusion | `\bcoliving\b` misses `Co-living` / `Co living` — one real notified MATCH (2026-08-30) | Track 1i |
| F6 | Empty-title rows (3 seloger + 4 pap) | Every `exclude_title_patterns` entry inert on them (nothing matches an empty string) | Track 3 audit item |
| F7 | La Centrale truncation | Email carries ~3 of 900+ stated cards; `FEED_SILENT` keys on message DATE, so health stays green while 99.7% blind | Track 2 step 4 (documented cost) |
| F8 | SeLoger `id_from: content` + a misread surface | A bad surface reading changes the dedup key → one flat can notify twice under two identities | Noted in 1g; same root class |
| F12 | `docker compose up -d` wedges on recreate and leaves a watcher DOWN | **LIVE, twice on 2026-08-31.** `stop_grace_period: 5m` + a renamed old container = the orchestration stalls; once it then failed outright on `Conflict. The container name … is already in use`. rent-scout was down ~13 min and nothing said so | see the redeploy note below |
| F10 | SeLoger `Baisse de prix` yields an EMPTY title | **LIVE** — `title_pattern` refuses any line containing `€`, and a price-drop card's own text starts with one; `exclude_title_patterns` is inert on the whole template. 2 of 4 empty-title rows were NOTIFIED | Track 3 finding, fix owed against a captured fixture |
| F11 | A SeLoger card with no `pièces` line has no title anchor at all | **LIVE, and narrower than F10** — the pattern anchors on the `pièces` line, so a card stating no room count (a room rental, a parking, an atypical ad) yields `''`. Two such rows; both were REJECTED, by the description-matching `exclude_patterns` rather than by the title ones | same |
| F9 | n=1 separators/patterns (leboncoin rent, PAP) | Measured on one capture each; PAP already proved what that costs | standing; each new alert is the regression test |
| F10 | Generic `ROOMS_PATTERN` reads hex out of photo-URL UUIDs | `(?:T\|F)\s?(\d)\b` is case-insensitive, so `…90F8-…` → 8 rooms. **14 wrong on seloger, 6 notified; 7 on bienici.** Too LOW loses real matches, too HIGH clears `min_rooms` on a number nobody stated | **NEW Track 1j** (2026-08-31) |
| F11 | Startup refusal reachable only under `--watch` | *FIXED 2026-08-31* — it was also consumed above the `isDue()` test, so a restart inside the beat interval destroyed it unreported; `doctor` now reports it without consuming | round-4 fix commit |
| F12 | Car heartbeat inside the pass closure | *FIXED 2026-08-31* — a throwing pass silenced the watcher entirely, the one state the beat exists to make visible | round-4 fix commit |
| F13 | Scrubber `To:`/`Cc:` display name, and any base64 fold ≤19 columns | *FIXED 2026-08-31* — two committed fixtures had shipped the subscriber's real name; a 19-column fold was written and reported `scrubbed` with the address one `base64 -d` away | round-4 fix commit |
| F14 | Three Bien'ici fixtures carried the subscriber's address behind a DOUBLE base64 layer | *FIXED `3d24525`* — detection in `8f0c526`, stripping once the QP-eating hex replacer was fixed; all three re-scrubbed, 0 of 15 fixtures leak four levels deep, and `FixtureSecretsTest` is now armed with the same recursive decode. HISTORY still carries the blobs — the developer's call (hard rule 7's new note) | closed, remote exposure stated |
| F15 | The refusal note is consumed at beat-COMPOSE time, not on delivery | *FIXED `ec17b74`* — An undelivered beat destroys it, and the commonest refusal IS a channel misconfiguration — so the beat that should carry the note is the one most likely to fail | **OPEN — round 5, O3** |
| F16 | Cron `--once` never clears the refusal note | *FIXED `ccc8498`* — `takeLastRefusal()` is called only in `watch()`, so `doctor` reports a fixed outage for ever while saying it will be carried on the next beat | **OPEN — round 5, O4** |
| F17 | Car `doctor` has no `pendingRefusal` | *FIXED `4503834`* — The gap round 4 closed for rent is fully open on car (`grep -c pendingRefusal`: rent 3, car 0) | **OPEN — round 5, O5** |
| F18 | A plain `grep` silently skips the Latin-1 PAP fixtures | `grep -c .` prints nothing and exits 0; `grep -ac .` prints 145. Any grep-based "N fixtures scanned, 0 hits" sweep is unsound on this tree — use a byte-level scanner | **OPEN — round 5, O6 (method)** |
| F19 | A §1 guard inside `twinClassification()` covered by neither the suite nor the ledger | *FIXED `43778bd`* — `Pipeline.php:829`'s `clusterClassification(..., groupExcludedTenure($key))` is the only thing reaching an excluded tenure on an ABSORBED SIBLING OF THE TWIN's cluster. Mutating it to `null` leaves all 2 339 tests green AND pushes the agency copy of a PLS flat (proven by execution). Round 4 added ledger cases for the twin fact's write-across and read-across; this third surface has none — the same "one of two surfaces" shape as the P0 it was fixing | **OPEN — round 5 correctness, needs a test THEN a ledger case** |
| **F20** | **Neither documented repair route for an over-merge/over-link actually works, and no command can re-open a durably-excluded row** | The judged verdict (carrying the group's or twin's excluded tenure) is written into the row's OWN `tenure`, which round 4 then made durable — so a veto is laundered into the row's own reading. `staleVerdicts()`/`pendingDigest()`/`replay` all skip it. Q39 corrected; the rejection reason also MISATTRIBUTES the PLS to "a previous reading of THIS listing" when it was read on the other track | **OPEN — round 5 correctness** |
| F21 | An unrecognised `tenure` string silently releases a durable excluded reading | *FIXED `76251b4`* — it released the row's own reading, the group veto AND the twin veto together. `decodeTenure()` now refuses a non-empty value that does not decode; a NULL column still means nothing was said | closed |
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

### Redeploying is not `docker compose up -d` — F12, hit twice on 2026-08-31

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
- **F10/F11 above** — SeLoger's empty titles, with the cause measured rather than guessed:
  `title_pattern` is `~^\h*(?!https?://)([^\n€]{2,80}?)\h*\n(?:\h*\n|\h*https?://[^\n]*\n)+\h*\d+\h*pi[eè]ces?\b~mu`.
  The `[^\n€]` class forbids `€` in the captured line — a guard against capturing the rent line —
  and a `Baisse de prix` card's own agency text begins `600€ TOUT COMPRIS – électricité…`, so it is
  refused and the title comes back empty. The other two rows have no `pièces` line at all, so the
  anchor is absent.
  **NOT FIXED HERE, deliberately.** The narrow repair is to reject a line that is ONLY a price
  rather than any line containing one — but that is a pattern change on the highest-volume source,
  and this repo's rule is that a real payload is the input. Capture the `Baisse de prix` template
  (`php tools/dump-eml.php alertes@seloger.com … "$IMAP_MAILBOX"`), freeze it, then change the
  pattern against it.

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
- Generic-store split: `VehicleStore` composes the rent `Store` directly. Measured blast radius:
  38 files reference the rent Store (10 non-test + 28 test); **93 sabotage-ledger expressions**
  are path-anchored to `src/php/Rent/Store/Store.php`; the live `state/car-watch.sqlite3` already
  contains the composed rent tables and a `schema_meta` row (rent v12 vs vehicle v1), and `Store`
  refuses to open a newer schema — getting the migration wrong is a hard startup failure on a live
  domain. **Short design pass first** (which methods move, the live-file migration path, which
  ledger expressions change), then build.
- Its own MAXIMAL certification (frozen commit, two consecutive clean rounds), separate from
  Tracks 0 and 1.
- Verification: vehicle-guard both halves demonstrated; full suite + ledger green post-split with
  ZERO expressions pointing at a dead path (`grep -c "Rent/Store/Store.php" tests/sabotage-check.sh`
  before/after, reconciled); `state/car-watch.sqlite3` opens post-migration with row counts
  preserved on every table.

---

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
2. AL'in: one logged-in look without a NUR (browser, developer's own session).
3. CapCar: the one-time make-selector browser check.
4. Gmail filter creation: route leboncoin "vous propose" → a scout-readable label (no tool can
   create filters; retro-labelling the 5 messages is doable from here).
5. At Track 0 round 4's verdict: the close-vs-round-5 decision (asked via AskUserQuestion).

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
| R6-1 | PAP `commune_pattern` cannot read an arrondissement (`Paris 16e`), and the block's `_why` still claims all four patterns anchor on the postcode | Safe direction (a null commune cannot match) but SILENT. The `_why` is the record a future editor reads — correcting it is owed |
| R6-2 | A hard-wrapped PAP criteria line would still hand the search floor to `surface_pattern` | Unverified against any real payload; n=4 captures all put it on one line |
| R6-3 | The forwarded-as-attachment capture route defeats both secrets layers (`from` not dropped; embedded `message/rfc822` sits after the blank line; `Resent-To` in neither list) | Latent, not an active leak — the current tree is clean four levels deep |
| R6-4 | The wrapped-token stripper's left anchor is blind in quoted-printable (`?u=3D<blob>`), so it fails CLOSED on the very shape every real capture uses | Refuses rather than leaks, but the refusal is unresolvable — which invites hand-editing |
| R6-5 | Omitting the optional address makes the scrubber a silent no-op that reports success | The docs say to pass it; the missing WARNING is the finding |
| R6-6 | The `=3D`-eaten escape is permanently baked into two ParuVendu fixtures (originals not in the repo) | Parse-identical; the tool is fixed so it cannot recur |
| R6-7 | QP line length regressed 4× in the Bien'ici fixtures (max 121 → 196) | Parse-identical: link counts and body lengths byte-stable |
| R6-8 | No Decisions Log entry for the round-5 fix commits; four register rows read FIXED and OPEN at once; `Pipeline.php:829` citation drifted; 7 dangling links inside `docs/plans/archive/**` | Bookkeeping. The first is a real breach of this file's own rule |

**Do not read round 5's verdict as a setback.** Round 4 fixed 19 findings and round 5 found that
several landed on one surface of two — which is the same defect class the milestone has now produced
at every round. That pattern, not any single finding, is the thing to fix: **before closing a
finding, enumerate every symmetric surface it has (rent/car, read/write, survivor/absorbed,
tool/guard) and say which ones the fix covers.**
