---
name: sweep
spotlight: true
description: Use when running a Phase 6 second sweep on uncommitted changes before committing, or reviewing code written outside the standard agent workflow.
user-invocable: true
disallowed-tools: AskUserQuestion
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

  1. QUESTIONS ARE PLAIN TEXT. `AskUserQuestion` TIMES OUT in this cloud container, so a gate that
     "asks" cannot fire. Every "invoke ask-human" below means: print the question, a minimal
     concrete example, numbered options, and the recommended option FIRST with its reason, as
     ordinary prose — then STOP and wait. Protocol: `.claude/skills/ask-human/SKILL.md`.
  2. NO `advisor()` HERE — the tool does not exist in this environment. Independent certification =
     fresh-context read-only reviewer subagents, run as the three rent-watch lenses
     (`tenure-correctness-reviewer`, `source-resilience-reviewer`, `completeness-reviewer` — see
     `/converge`). All three are REAL agent definitions in `.claude/agents/` — spawn them by name
     via the Agent tool rather than re-describing their charter inline, so each lens's attack surface
     stays in one place. Self-grading is the last resort and MUST be disclosed as self-graded.
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored via `/var`, survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`: that is wiped when
     the container is reclaimed, so a report written there is lost. Never `git add` a report regardless
     — being ignored is what keeps them out of history, not what makes staging them harmless.
  4. `--scope=global|both` IS REMOVED wherever it appears: `~/.claude/` in this container is
     GENERATED from repo files by `scripts/claude-bootstrap/install.sh`, so auditing it audits a copy.
  5. ≤5 concurrent subagents (10 caused ~50% rate-limit failures upstream). Every pipeline agent
     writes its raw output to `var/claude/<stage>/raw/` BEFORE returning — autocompact fires at 80%
     here and in-conversation results do not survive it.
  6. PROJECT RULES WIN on any conflict: `/home/user/rent-watch/CLAUDE.md`. It EXISTS and is
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
     `.claude/` and `scripts/claude-bootstrap/`.
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

## rent-watch dimensions — MANDATORY additions to this skill's review set

Run these **in addition to** the dimensions below, on every sweep of this repo. Skip a dimension only
when the tree it applies to genuinely does not exist yet (as of 2026-08-07 that is *`config/`, the adapters and the CLI — but NOT
`src/php/Core/`, `src/php/Store/` or `tests/php/`, which exist and are populated* — so some of these
dimensions have no subject yet) — and then **name the dimensions you skipped and why**. A silently
skipped dimension is a coverage lie. Reviewing `prototype/scout.py` against these is legitimate and
useful; reporting a finding about `src/core/tenure.py` is not — that path has never existed here; the classifier is `src/php/Core/TenureClassifier.php`.

- **Tenure exclusion (P0 — this is an eligibility error, not a bug).** Trace every path from a parsed
  listing to a notification and prove that PLAI, PLUS, `conventionné`, ANRU and ANAH cannot reach it.
  A path that *happens* to filter them today because of ordering, a truthy default or a source that
  currently returns no social stock is the bug — the exclusion must hold by construction. Any config
  key, CLI flag, env var or default that could re-enable an excluded tenure is P0 even if nothing sets
  it. `prototype/scout.py` has **no classifier at all** and is a standing example of this failure.
- **Fail-closed classification (P0).** Confidence `< 0.6` on a source flagged `mixed_tenure: true`
  must yield `UNKNOWN`, and `UNKNOWN` must route to the low-priority "à vérifier" digest — never to a
  match, never to the normal notification path. Check the *default* when a signal is missing, not just
  the happy path: an absent `financement` field must lower confidence, not silently inherit the
  source's `default_tenure` at full confidence. A missed listing is annoying; a social-housing false
  positive makes the tool untrustworthy, which is worse.
- **Classifier corpus integrity (P0).** The hand-labelled fixture corpus is the only thing standing
  between a refactor and a silent regression. A skipped, xfailed, deleted or relabelled fixture is P0
  unless the label was demonstrably wrong — and then the evidence goes in the commit message. Never
  weaken a fixture to make a change pass. New classifier behaviour needs a new fixture whose
  pre-change form provably fails.
- **Silent source breakage.** The classic failure of this tool class: a selector breaks, the source
  returns zero results forever, no notification arrives, and the user concludes the market is quiet.
  Every source must persist last-success, last-count and a rolling 7-day mean, alert `SOURCE_BROKEN`
  after N consecutive empty runs against a non-zero baseline, and warn on a >70% drop. A `try/except`
  that swallows a fetch error and returns `[]` is this failure mode wearing a hat — see the
  anti-bandaid gate below.
- **Field-map and parser fragility.** Parsers run offline against frozen fixtures; a parser test that
  needs the network is not a test. Check that rent is compared **charges comprises** and that a source
  reporting rent excluding charges is normalised rather than compared raw — an un-normalised rent is a
  wrong disqualification, which is invisible. Check that commune matching uses structured fields, not
  a substring search over the whole blob: "proche Chatou" in a Paris listing must not pass the commune
  filter.
- **Dedup correctness.** Within-source key stability first: a `(source, external_ref)` that changes
  between runs re-notifies forever, and a hash over a volatile title does the same. Cross-portal, the
  fuzzy match on `(cp, surface ±2 m², rent ±20 €, rooms)` must be checked in both directions — a
  cluster that over-merges hides a real second flat, one that under-merges triple-notifies the same
  one. A rent drop on a known listing is a notification-worthy event, not a duplicate.
- **Legal and ToS posture (P0).** Direct scraping of a private portal must be opt-in, disabled by
  default, `legal_risk: true`, and refuse to run without an explicit flag. Any CAPTCHA solving, proxy
  rotation, fingerprint spoofing or dishonest User-Agent is P0 — remove it and route the source
  through email-alert ingestion instead. Check `robots.txt` handling and request rates. A source added
  under `demande-logement-social.gouv.fr` or Bienvéo is P0 (out of scope by §1).
- **Secrets hygiene (P0).** IMAP credentials, notification tokens, the IDFM/PRIM API key and the RFR
  figure live in `.env`, gitignored, mirrored by an in-sync `.env.example`. Grep the diff for a
  credential, a mailbox address, a real RFR or a personal financial figure reaching a committed file,
  a log line, an exception message, a fixture or a notification body. A fixture captured from a live
  payload must be scrubbed before it is committed.
- **Notification quality.** Every notification carries `score` **and** human-readable `reasons[]`
  explaining it. A score with no reasons is unreviewable by the user and untestable by us. Hard
  disqualifiers reject silently and are logged only — a disqualifier that notifies, or a score
  component that silently disqualifies, means the two mechanisms have been conflated.
- **Anti-bandaid gate.** For every `||` fallback, `2>/dev/null`, `|| true`, bare `except:` that
  continues, error trap, retry loop, timeout bump or default-value assignment introduced: state the
  exact failure mode, the *physical* evidence that confirmed it (log, measurement, trace, test
  output), and whether the root cause is fixed. No evidence ⇒ **P0**, replace it with a root-cause
  fix. In this repo the highest-frequency instance is an adapter swallowing a fetch exception and
  returning an empty list — that converts a loud breakage into a silent one, which is exactly the
  thing source health exists to prevent.
- **Anti-bandaid gate.** For every `||` fallback, `2>/dev/null`, `|| true`, `try {} catch {}` that
  continues, error trap, retry loop, timeout bump or default-value assignment introduced: state the exact
  failure mode, the *physical* evidence that confirmed it (log, measurement, trace, test output), and
  whether the root cause is fixed. No evidence ⇒ **P0**, replace it with a root-cause fix.

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /sweep — Run a Phase 6 second sweep on uncommitted changes before committing, or review code written outside the standard agent workflow.
>
> No flags — invoked without arguments.
> ```

---

Run a Phase 6 Second Sweep on current uncommitted changes. **Never auto-applies anything — this command only reads and reports.** Use before committing or to review code written outside the standard agent workflow.

## Steps

1. **Assess the diff**:
   - `git diff --stat` — change footprint (files changed, lines added/removed)
   - `git diff` — full diff
   - `git diff --cached --stat` + `git diff --cached` — staged changes too

2. **Review each changed file** using the Phase 6 checklist:

   **All files**:
   - **Bug hunt**: logic errors, off-by-one, null/nil/undefined deref, unchecked error returns, unhandled edge cases
   - **Security**: credentials/secrets in code, injection risks (SQL, shell, template), missing input validation at system boundaries
   - **Contracts**: changed function signatures, changed CLI flags, changed `config/*.json` keys, changed SQLite schema or `Source` interface methods — flag every one as a potential breaking change. For a `sources.json` key, say whether existing source blocks keep parsing; for a schema change, say what happens to an existing `seen`/listings database
   - **Tests**: new behavior without a test? Modified behavior without updated tests? Derive the runner from the dependency manifest once one exists — do **not** name `pytest`, `ruff` or any command from memory. As of 2026-08-06 the runner is `php tools/phpunit.phar` and the manifest is `composer.json`; run the suite rather than assuming there is none. "No runner present yet" is NOT an available answer for a PHP change; it remains the honest one only for a dimension with no subject in this tree at all
   - **Docs**: changed public interface without updated documentation?

   **Shell scripts** (`.sh`):
   - Missing `set -euo pipefail` or equivalent
   - Unquoted variable expansions (`$VAR` instead of `"$VAR"`)
   - Missing error handling after commands that can fail silently
   - `rm -rf` on an unvalidated or unquoted path

   **Config / infra files** (`.yaml`, `.yml`, `Dockerfile`, `.env`):
   - Secrets or credentials committed directly
   - `ARG` without matching `ENV` if runtime access needed
   - Trailing `;` in list vars that would be silently swallowed

3. **Classify each finding** by severity:
   - **CRITICAL**: security hole, data loss risk, broken API contract, shell injection, unhandled error that will crash in production
   - **WARNING**: missing test, logic edge case, performance regression, missing error handling, unquoted variable
   - **NOTE**: style, naming, non-blocking improvement

4. **Output a structured findings table**:

```
## Sweep Results

| # | Severity | File:Line | Finding | Fix |
|---|----------|-----------|---------|-----|
| 1 | CRITICAL  | <file>:<line>         | UNKNOWN tenure falls through to the match path | Route UNKNOWN to the "à vérifier" digest; add a fixture |
| 2 | WARNING   | <file>:<line>         | Missing exit-code check     | Check return value of the failing command |
| 3 | NOTE      | <file>:<line>         | Unused binding              | Remove or document |

**Verdict**: PASS (safe to commit) or BLOCKED (N critical findings must be fixed first)
```

5. **Save the report**: Write findings to a timestamped file so they survive the session:

```bash
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
SWEEP_DIR="$REPO_ROOT/var/claude/sweeps"
mkdir -p "$SWEEP_DIR"
SWEEP_PATH="$SWEEP_DIR/$(date +%Y-%m-%d-%H%M%S).md"
```

Write the full findings table (including verdict) to `$SWEEP_PATH`. Announce: "Sweep report saved to `$SWEEP_PATH`"

## Notes

- A single CRITICAL finding means verdict is BLOCKED
- Multiple WARNINGs with no CRITICAL = PASS with notes (your discretion)
- Apply **Kernighan's Law**: if the diff is hard to understand, that itself is a WARNING (complexity)
- Apply **Chesterton's Fence**: before flagging a removal as wrong, understand why the code existed (`git blame`, commit message)
- Apply **Hyrum's Law**: any changed public interface (CLI flag, function signature, config key, command output format) is a potential contract break — flag it
