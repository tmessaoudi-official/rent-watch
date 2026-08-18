---
name: converge
spotlight: true
description: Run the project's MAXIMAL certification ladder (CLAUDE.md § "Certification ladder"), or a deeper tunable convergence sweep, over an audit/migration/gate. Defaults ARE the rent-watch ladder — 3 adversarial evidence-based lenses, TWO consecutive fully-clean rounds, cap 5 rounds, certified by fresh-context reviewer subagents. Override with --cycles/--converge/--angles/--certify. Runs AUTONOMOUSLY by default (rent-watch) and reports progress every cycle; --ask restores the approval gate. Escalates via /ask-human (AskUserQuestion) if it cannot converge.
user-invocable: true
args: "[--cycles=N] [--converge=K] [--scope=ladder|3C|6C|custom] [--angles='angle1;angle2;angle3'] [--certify=reviewer|self] [--ask] [--auto-cap=N]"
side-effects: None — read-only analysis loop; findings incorporated into conversation context only.
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  rent-watch ADAPTATION (2026-08-06) of the twes-in port (2026-07-29), which came from the pdfturbo
  container port (2026-07-27), which came from the developer's machine bundle
  `claude-setup-global-20260722` via the phorj port. The port's machinery (plain-text questions,
  reviewer-subagent certification, `var/claude/**` reports, the ≤5-agent cap) is kept verbatim; what
  was RE-GROUNDED is the domain. twes-in's invoicing hooks — money and tax arithmetic, invoice/quote
  state machines, multi-tenant row-level isolation, Peppol / EN16931 / Factur-X validity, three
  client toolchains — are gone, because rent-watch has no analogue for them. What is load-bearing
  here instead: the FRENCH HOUSING TENURE CLASSIFIER and its fail-closed contract, silent
  source breakage, cross-portal dedup correctness, the legal/ToS posture on scraping, and secrets
  hygiene for the alert mailbox. These deltas OVERRIDE the body below wherever they conflict:

  1. QUESTIONS USE `AskUserQuestion` (re-inverted 2026-08-18 — the cloud container in which it
     timed out is dead; on this machine it works and the global Stop hook requires it). Every
     "invoke ask-human" below means: call `AskUserQuestion` — options with the recommended one
     FIRST and its reason, and a visible "none of these / challenge the premise" escape.
     Protocol: `.claude/skills/ask-human/SKILL.md`.
  2. `advisor()` IS AVAILABLE on this machine (verified 2026-08-18) and is the FIRST rung of
     certification. The panel of record remains the
     fresh-context read-only reviewer subagents, run as the three rent-watch lenses
     (`tenure-correctness-reviewer`, `source-resilience-reviewer`, `completeness-reviewer` — see
     `/converge`). All three are REAL agent definitions in `.claude/agents/` — spawn them by name
     via the Agent tool rather than re-describing their charter inline, so each lens's attack surface
     stays in one place. Self-grading is the last resort and MUST be disclosed as self-graded.
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored via `/var`, survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`: in-repo reports stay next to the code they describe. Never `git add` a report regardless
     — being ignored is what keeps them out of history, not what makes staging them harmless.
  4. `--scope=global|both` IS AVAILABLE AGAIN: `~/.claude/` is the developer's real, persistent
     install (the container-era generated copy died with `scripts/claude-bootstrap/`, removed
     2026-08-18), so auditing it audits the real thing.
  5. ≤5 concurrent subagents (10 caused ~50% rate-limit failures upstream). Every pipeline agent
     writes its raw output to `var/claude/<stage>/raw/` BEFORE returning — autocompact fires at 80%
     here and in-conversation results do not survive it.
  6. PROJECT RULES WIN on any conflict: `this repo's `CLAUDE.md``. It EXISTS and is
     authoritative — READ IT. It carries the social-housing exclusion (the one non-negotiable rule),
     the domain glossary, the certification ladder, the git-autonomy override, the adapter-contract
     rules, and the in-repo plan home (`docs/plans/<topic>.plan.md`, each plan carrying its own
     `## Decisions Log`). On any conflict with a delta above, CLAUDE.md wins.
  7. ONE TOOLCHAIN, AND THE APPLICATION IS NOT BUILT YET. rent-watch is a single-language,
     single-user, self-hosted CLI watcher — no web UI, no multi-user support, no service tier. As of
     this banner the repo carries the SPECIFICATION, a PROTOTYPE, and the PURE CORE of the
     implementation: `spec/PROJECT_BRIEF.md` (the source of truth — mandatory reading before any
     application code), `prototype/scout.py` + `prototype/sources.yaml` (a pre-existing single-file
     prototype, reference material only), `CLAUDE.md`, `README.md`, `docs/OPEN-QUESTIONS.md`,
     `.claude/`.
     **PRESENT since 2026-08-06: `src/php/Core/` (models + the tenure classifier + `SourceHealth`/
     `SourceStatus`), `src/php/Store/` (the SQLite seen-set, price history and run log, added
     2026-08-07), `tests/php/`, `tests/fixtures/tenure/corpus.json`, `composer.json`, and a
     WORKING TEST RUNNER —
     `php tools/phpunit.phar`. Tests can be executed here, so Rule 7's "tests MUST be executed"
     applies in full and "no test runner in the tree" is NOT an available answer.
     STILL ABSENT: `config/`, every adapter, the notify channels, the CLI, a linter, CI.**
     Verify with `git ls-files src/ config/ tests/` rather than trusting this paragraph — it has been
     stale before, in both directions. Never hardcode a build or lint command, and never report a
     finding about `src/core/tenure.*` (a path that has never existed here; the classifier is
     `src/php/Core/TenureClassifier.php`). When the stack a step needs is genuinely absent, say so
     and skip the step. A finding invented about code that does not exist is worse than an empty
     report.
  8. THE SOCIAL-HOUSING EXCLUSION IS THE LOAD-BEARING INVARIANT, and it is an eligibility fact, not
     a preference. `logement social` (PLAI, PLUS, and `conventionné` / ANRU / ANAH regimes absent an
     explicit intermediate label) must NEVER be surfaced as a match: the user is not eligible, so a
     social-housing false positive is not a ranking miss, it is a wasted application and a tool the
     user stops trusting. Treat it as rent-watch's P0 class, the way a cross-tenant read was
     twes-in's. Two consequences for any review or sweep run here: (a) `UNKNOWN` tenure on a
     mixed-tenure source routing anywhere except the low-priority "à vérifier" digest is a P0
     finding, not a smell; (b) a config key, flag or default that could re-enable an excluded tenure
     is a P0 finding even if nothing currently sets it. `.claude/hooks/tenure-guard.sh` is a
     tripwire on this, not a guarantee — it greps, it does not reason.
  9. LEGAL POSTURE IS PART OF CORRECTNESS. Email-alert (IMAP) ingestion is the PRIMARY path for the
     private portals (SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) — within ToS, no bot to detect,
     faster than polling because alerts fire on publication, and immune to markup churn. Direct
     scraping of those portals is opt-in, disabled by default, `legal_risk: true`, and refuses to run
     without an explicit flag. CAPTCHA solving, proxy rotation and fingerprint spoofing are OUT —
     never propose them as a fix for a blocked source; propose the email-alert route instead.
     `demande-logement-social.gouv.fr` and Bienvéo are out of scope entirely (social-housing
     channels — they violate delta 8).
═══════════════════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /converge — Run the project's MAXIMAL certification ladder (3 adversarial evidence-based lenses, TWO consecutive clean rounds, cap 5, fresh-context reviewer subagents), or a deeper tunable convergence sweep. Every parameter is overridable. Runs autonomously by default; --ask restores the approval gate.
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /converge — Convergence Loop

Runs a structured multi-angle convergence loop. **Autonomous by default in rent-watch** — it announces its parameters and proceeds; `--ask` restores the upstream approval gate. Progress is reported after every cycle: autonomy suppresses `ask-human` pauses, never output.

**Relationship to the project's Phase 3C/6C gates.** Project `CLAUDE.md` § "Certification ladder" mandates a 3-lens reviewer panel with two consecutive clean rounds at **every** 3C and 6C gate, all task sizes — and today that ladder is hand-rolled from memory each time. Running `/converge` with its defaults **IS** that gate, executed mechanically instead of remembered. Reach for the flags when you want more than the mandated tier: a wider lens set, a higher clean-round threshold, or an enumerated custom scope for a large audit or migration.

## Flags

- `--cycles=N` — maximum total cycles before escalating (default: **5** — the ladder's cap)
- `--converge=K` — consecutive fully-clean cycles required to declare convergence (default: **2** — the ladder's *two consecutive fully-clean rounds*; any finding resets the counter)
- `--scope=ladder|3C|6C|custom` — which lens set to use (default: **`ladder`**). The `3C`/`6C` names describe the angle *content* (expanding-context / adversarial / blast-radius) and are kept for continuity; `ladder` is the project-mandated panel — running it here IS the 3C/6C gate, performed rather than remembered.
  - `ladder` (**default — the project's ratified ladder**): the 3-lens reviewer PANEL, each lens adversarial and **evidence-based** (the reviewer reads the actual diff/tests/specs itself, never the author's narrative). rent-watch's three lenses are named, and each spawns as a fresh-context read-only subagent under that name:
    1. **`tenure-correctness-reviewer`** — correctness + regression, aimed at what rent-watch can silently get *wrong*: the tenure classifier (signal priority, confidence arithmetic, the fail-closed `UNKNOWN` route), the excluded-tenure set and every path by which PLAI/PLUS/`conventionné`/ANRU/ANAH could reach a notification, the split between hard disqualifiers and score components, and dedup keys (within-source stability, cross-portal over- and under-merging). A plausible social-housing false positive is P0, not a smell.
    2. **`source-resilience-reviewer`** — silent breakage + legal posture + secrets hygiene: per-source health baselines and the `SOURCE_BROKEN` path, exception handling that converts a loud failure into an empty result set, parser fragility against frozen fixtures, rent normalisation (charges comprises), the opt-in/`legal_risk` gate on portal scraping, `robots.txt` and request rates, and any credential, mailbox address or personal financial figure reaching a committed file, a log, an exception or a notification body.
    3. **`completeness-reviewer`** — completeness + blast radius + **config-and-fixture coverage**: a change to the `Source` interface, a `config/sources.json` key, the SQLite schema or a notification payload is incomplete until every source block, fixture, migration path and doc that depends on it is accounted for (updated, or the change shown to be additive). `spec/PROJECT_BRIEF.md`, `README.md`, `docs/OPEN-QUESTIONS.md` and the classifier corpus all count as part of the radius.
    This is the tier project `CLAUDE.md` mandates at every 3C/6C gate, all task sizes. All three exist as real agent definitions in `.claude/agents/` — spawn them by name via the Agent tool rather than re-describing their charter in a prompt, so the panel's attack surfaces stay in one place.
  - `3C`: pre-implementation-style angles (expanding-context, adversarial, blast-radius)
  - `6C`: pre-completion-style angles (expanding-context on result, failure modes, callers/docs)
  - `custom`: angles provided via `--angles`
- `--angles='A;B;C'` — semicolon-separated angle descriptions when `--scope=custom`; for custom scope, at least one angle **must** be prefixed with `enumerate:` (e.g. `enumerate:list all image dirs`). See Angle Requirements below.
- `--certify=reviewer|self` — how a cycle's findings get judged (default: **`reviewer`**)
  - `reviewer` (**default**): each lens is run by a **fresh-context read-only reviewer subagent** that reads the artefacts itself. `advisor()` does not exist in this environment, so this IS the top of the ladder's availability chain here. Convergence still requires `--converge=K` (2) consecutive fully-clean rounds — independence removes the self-grading blind spot, it does not remove the project's two-round requirement.
  - `self`: self-graded CLEAN/RESET/STUCK comparison against the previous cycle. Last resort — a restricted subagent context with no ability to spawn reviewers. **Using it obliges you to state in the output that certification was self-graded and why** (project CLAUDE.md's disclosure rule).
- `--ask` — opt IN to the Step 0 approval gate (autonomous is the rent-watch default; this restores the upstream stop-and-confirm behaviour)
- `--auto-cap=N` — hard safety ceiling for autonomous mode (default: **30**, max: **30**); overrides `--cycles` when autonomous and N > auto-cap; prevents runaway token burn

---

## Angle Requirements

These rules apply to every angle in every cycle, regardless of scope.

### Evidence gate (all scopes)

Every angle result **must** include at least one of:
- A command and its actual output (grep, find, ls, read, wc — something that ran and produced text)
- An explicit enumerated list of items checked with a total count
- A file path + line number citation pointing to the specific location of the finding

**Pure prose reasoning fails the evidence gate.** "I believe X is covered" or "X looks correct" without a supporting command or citation is not a valid angle result. If an angle produces only prose, it must be re-run with concrete evidence before the cycle result is recorded.

### Enumeration angle (custom scope — mandatory)

When `--scope=custom`, at least one angle must be designated `enumerate:`. This angle:

1. **Runs an explicit enumeration command** (`ls`, `find`, `grep` on an index file, or equivalent) to list every member of the set being audited
2. **States the total count** — "N members found: [list]"
3. **Cross-checks coverage** — after all other angles complete, compares members visited this cycle against the total enumerated. Any member not visited by any angle is a scope gap.
4. **Scope gaps are findings** — an unvisited member triggers a RESET with the finding "scope gap: <member> not covered"

The enumeration angle cannot be satisfied by memory or assumption. It must show the command that produced the member list.

**Example** — for an audit that must cover every payment-gateway adapter (the whole coverage surface of a payments change):
```
enumerate: run `git ls-files | grep -i gateway` to get the full adapter list (N files found —
           on a greenfield tree ZERO is a valid answer, and saying so IS the evidence), then
           cross-check which of those files were read or grep'd by the other angles this cycle
```

---

## Step 0 — Announce and run (autonomous by DEFAULT — rent-watch adaptation)

**rent-watch runs this loop autonomously.** Upstream (and phorj) stop here for approval; that is an
interrupt, and the developer's standing directive for this repo is no interrupts. So: parse flags,
take the ladder defaults for anything missing (`--scope=ladder`, `--cycles=5`, `--converge=2`,
`--certify=reviewer`), **print the parameter block, then proceed immediately** — do not wait.

```
[converge] ladder | certify=reviewer | cycles=5 | converge=2 | auto-cap=30
[converge] lenses: 1 tenure-correctness-reviewer  2 source-resilience-reviewer  3 completeness-reviewer
```

`--ask` opts back INTO the approval gate: present the block via `/ask-human` (`AskUserQuestion`,
recommended option first), then STOP and wait. Use it when the scope is large enough that the
token cost itself deserves a decision.

**Autonomous mode is therefore the default**: `autonomous = true` unless `--ask` was passed and the
developer chose a non-autonomous option. The ONLY guaranteed stop in autonomous mode remains the cap
escalation in Step 5 — a stuck independent review is not something autonomy may silently override.

With no `--ask`: `autonomous = true`, proceed straight to Step 1.

If user selects "Change parameters": ask two follow-up questions (max cycles, convergence threshold), then re-display the updated config and confirm once more before proceeding.

If user selects "Skip": exit immediately, report "Convergence loop skipped by user."

---

## Step 1 — Initialize state

```
TOTAL_CYCLES  = N                          # from approved config (default 5 — ladder cap)
CONVERGE_REQ  = K                          # from approved config (default 2 — ladder clean rounds)
CERTIFY       = reviewer | self            # from approved config (default reviewer)
AUTO_CAP      = min(auto-cap, 30)          # hard safety ceiling for autonomous mode
autonomous    = true                       # rent-watch DEFAULT (--ask can turn it off)
counter       = 0                          # consecutive clean cycles so far (self mode only)
cycle_num     = 0                          # total cycles run
prev_findings = []                         # findings from the immediately preceding cycle
```

---

## Step 2 — Run one cycle

Increment `cycle_num`.

**Autonomous safety cap check**: If `autonomous == true` AND `cycle_num > AUTO_CAP` → go to Step 5 (autonomous safety cap).

Run all angles against the current context. For each angle:
1. Execute the angle (grep, read, enumerate, or reason with evidence)
2. **Apply evidence gate**: confirm the result includes a command + output, enumerated list, or file citation. If not, re-run the angle with concrete evidence before proceeding.
3. List findings as bullet points. A finding is anything unresolved — a risk, gap, side-effect, inconsistency, or scope gap.

**If `--scope=custom` and an `enumerate:` angle is present:**
After all other angles complete, run the cross-check: compare the enumerated member list against members visited in this cycle. Any unvisited member → add as a scope gap finding before recording the cycle result.

**After running all angles, emit a progress line:**

```
[converge] Cycle cycle_num/TOTAL_CYCLES | counter/CONVERGE_REQ clean | <status>
```

Where `<status>` is one of:

- `CLEAN (counter/CONVERGE_REQ)` — no findings at all this cycle
- `RESET (counter → 0) — new: <one-line finding>` — something appeared that was not in prev_findings
- `STUCK — persistent: <one-line finding>` — findings identical to prev_findings, nothing new

*This progress line is always emitted, even in autonomous mode. Autonomous mode suppresses ask-human pauses, not output.*

---

## Step 3 — Evaluate and act

**If `CERTIFY == reviewer`** (default): the reviewer subagents' verdicts ARE the evaluation — do not self-compare. Spawn one read-only reviewer per lens — `tenure-correctness-reviewer`, `source-resilience-reviewer`, `completeness-reviewer` — each given the artefacts (diff, files, tests, spec) and told to **read them itself** and to try to REFUTE the work:
- Every lens returns zero findings → **Case A (CLEAN)**, `counter += 1`. **Do NOT jump straight to converged** — CLAUDE.md § "Certification ladder" requires TWO consecutive fully-clean rounds, so a single clean round is `counter = 1`.
- Any lens raises something not in `prev_findings` → **Case B (RESET)**, `counter = 0`.
- A lens repeats a point after a resolution attempt → **Case C (STUCK)**.
`prev_findings` is still tracked, and is what tells the next round's reviewers what changed.

**If `CERTIFY == self`** (last-resort fallback — `reviewer` is the default): evaluate using the original self-graded comparison, and say in the output that certification was self-graded and why:

**Case A — CLEAN:**
- `counter += 1`
- `prev_findings = []`
- If `counter == CONVERGE_REQ` → go to Step 4 (converged)
- Else → go to Step 2 (next cycle)

**Case B — RESET (new finding appeared):**
- `counter = 0`
- `prev_findings = current_findings`
- Incorporate the new finding into context/plan

- **If `autonomous == true`**: emit one line and continue without pausing:
  ```
  [converge] ↺ RESET cycle_num — autonomous: <finding summary>. Incorporating and continuing.
  ```
  Go to Step 2.

- **If `autonomous == false`**: **ask via `/ask-human` (`AskUserQuestion`) and STOP until answered** — option content below:
  ```
  Question: "New finding detected in cycle cycle_num. Counter reset to 0.
             Finding: <description>
             Continue the loop or escalate now?"
  Options:
    1. "Continue — incorporate and retry (Recommended)"
    2. "Continue autonomously — run rest of loop silently (no more ask-human calls)"
    3. "Escalate — surface to user and stop"
    4. "None of these / challenge the premise — e.g. the finding is not real, the lens is
        mis-scoped, or the scope should be narrowed. Say so and I will re-run differently."
  ```
  Option 4 is REQUIRED, not optional garnish: `ask-human` § "The five required parts" and
  `CLAUDE.md` § "Questions" both mandate a visible escape on every option set, and a
  template that omits it is the thing sessions will copy.
  - If "Continue": go to Step 2
  - If "Continue autonomously": set `autonomous = true`, go to Step 2
  - If "Escalate": go to Step 5 (cap escalation)

**Case C — STUCK (same findings, nothing new):**
- `counter` unchanged (neither increments nor resets)
- `prev_findings` unchanged
- Attempt deeper resolution of the persistent finding
- Emit: `[converge] STUCK on cycle cycle_num — attempting deeper resolution`
- Go to Step 2
- *(No ask-human call for STUCK — deeper resolution is attempted automatically in both modes)*

**Case D — Cycle cap reached (`cycle_num == TOTAL_CYCLES` and `counter < CONVERGE_REQ`):**
- Go to Step 5 (cap escalation)

---

## Step 4 — Converged

Emit:
```
[converge] ✓ CONVERGED — cycle_num cycles total, counter/CONVERGE_REQ consecutive clean cycles.
```

Report a one-line summary of what was verified across all clean cycles. Exit.

---

## Step 5 — Cap escalation (could not converge)

**Determine cap type:**
- If reached via autonomous safety cap (`cycle_num > AUTO_CAP`): emit `[converge] ✗ AUTONOMOUS SAFETY CAP — {AUTO_CAP} cycles reached.`
- Otherwise: emit `[converge] ✗ CAP REACHED — cycle_num/TOTAL_CYCLES cycles, counter/CONVERGE_REQ clean.`

In both cases:
- List all remaining findings accumulated so far
- Exit autonomous mode: `autonomous = false`

**Ask via `/ask-human` (`AskUserQuestion`) and STOP until answered** — this is the one guaranteed question in autonomous mode, and per project CLAUDE.md the 5-round cap NEVER silently proceeds:
```
Question: "Could not converge in cycle_num cycles (counter/CONVERGE_REQ clean).
           <If autonomous safety cap: 'Autonomous safety cap of AUTO_CAP cycles reached.'>
           Remaining findings:
             • <finding 1>
             • <finding 2>
           How do you want to proceed?"
Options:
  1. "Rerun — N more cycles (Recommended)"          → restart Step 1 with same K, new N
  2. "Rerun autonomously — N more cycles"           → restart Step 1 with autonomous = true
  3. "Decompose — split task and converge each part"
  4. "Escalate manually — I will review and decide"
  5. "None of these / challenge the premise"        → e.g. accept the remaining findings as
     documented risk, drop the clean-round requirement for this scope, or stop the loop entirely
     because the artefact is not worth further rounds. State which, and I will record it."
```

Wait for direction. This is the only guaranteed ask-human call in autonomous mode.
