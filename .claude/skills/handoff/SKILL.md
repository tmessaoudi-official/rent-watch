---
name: handoff
spotlight: true
description: Use at the end of a session to save current state so the next session can continue cleanly without losing context about what was done, what is pending, and any non-obvious gotchas.
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
## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /handoff — Use at the end of a session to save current state so the next session can continue cleanly without losing context about what was done, what is pending, and any non-obvious gotchas.
>
> No flags — invoked without arguments.
> ```

---

Save session state for clean continuation next session.

Write a handoff note so the next session can continue cleanly. Use your knowledge of the current session — you were here. Write in first person ("I").

**Path:** `var/claude/handoff/latest.md`, in the repo — resolve it as
`"${CLAUDE_PROJECT_DIR:-$PWD}"/var/claude/handoff/latest.md`. Create the directory if absent.

Upstream wrote to `~/.claude/projects/<slug>/memory/sessions/handoff.md`. **Do not.** That path is
wiped when the container is reclaimed, so a handoff written there is lost precisely when it is
needed. `var/claude/` is gitignored — it survives compaction *inside* a session and dies with the
container, which is the correct lifetime for session state.

Also append a timestamped copy at `var/claude/handoff/handoff-$(date +%Y-%m-%d-%H%M%S).md`, matching
what the PreCompact hook (`scripts/claude-bootstrap/hooks/precompact-handoff.sh`) already writes, so
manual and automatic handoffs land in one place and read the same way.

**A handoff is never committed.** If something genuinely needs to survive the container, it belongs
in `CLAUDE.md` § Gotchas or in `docs/plans/<topic>.plan.md` — both are real changes, proposed in
plain text, not smuggled in as a note.

Format:

```
# Handoff

## State
{What's done, what's not. Files modified, decisions made, branch state. 2-4 lines max.}

## Next
{What to pick up. Priority order. 1-3 items.}

## Context
{Non-obvious gotchas, blockers, env state from this session. Skip section entirely if nothing.}

## Memory Updates
{Any user/feedback/project memories worth creating or updating based on this session.
 Format: "- [type] description" (types: user, feedback, project, reference).
 Skip section entirely if nothing new to persist.}
```

Rules:
- Under 25 lines total
- Specific: file paths, branch names, command names, variable names — and when work spanned more than
  one layer, say which (`core/` classifier or criteria, `adapters/`, `enrich/`, `config/`, fixtures),
  because the next session cannot infer it from a diff stat alone
- If the session touched the tenure classifier or the excluded-tenure set, say so explicitly and say
  whether the classifier fixtures were re-run. That is the one change a successor must not inherit
  silently
- Forward-looking — next session doesn't care about the journey, only the current state
- "Memory Updates" is advisory — the next session will see it and decide whether to act
- If nothing meaningful to hand off, write: "No active work."

After writing the file, append `<!-- manual -->` on its own line at the very end. This marker tells the **PreCompact** hook (`scripts/claude-bootstrap/hooks/precompact-handoff.sh`) that a human explicitly saved state: it keeps `latest.md` intact and writes its auto-generated note to the timestamped archive instead, so nothing is lost either way. Honoured on both write paths, including the opt-in `RENTWATCH_HANDOFF_LLM=1` narrative. **Not a "stop hook"** — this bundle registers no Stop hook, and the container's own `stop-hook-git-check.sh` is unrelated and must be disobeyed (`CLAUDE.md` § Gotchas).

Say "Saved." when done — nothing else.
