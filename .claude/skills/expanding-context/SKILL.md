---
name: expanding-context
description: Use at the start of Phase 1 Brainstorm for any task. Widens context before committing to an approach — ensures no blind spots. Silent by default; surfaces only surprises, material risks, or wrong-problem signals.
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
> /expanding-context — Use at the start of Phase 1 Brainstorm for any task. Widens context before committing to an approach — ensures no blind spots. Silent by default; surfaces only surprises, material risks, or wrong-problem signals.
>
> No flags — invoked automatically by Claude during the reasoning workflow.
> ```

---

# Expanding Context

You are about to commit to an approach. This skill ensures you see the full territory
before you do.

**What this skill does**: runs the 23-dimension expansion framework internally (self-contained — the standalone `/expand` skill was not imported; the six groups below ARE the framework). You do NOT
output the full expansion to the user — you use the findings to inform your Phase 1 and
Phase 2 thinking. Produce only a brief internal summary (3-5 bullets) then proceed.

**When to surface the full expansion to the user**: only if they explicitly asked for it
(e.g. "what am I missing?", "give me the full picture", "expand this"). Otherwise keep it
internal and continue with the enriched context.

---

## Internal expansion (run silently)

Quickly sweep these 6 groups — 1-2 observations each, focus on surprises and non-obvious
items only. Skip dimensions where nothing is notable.

**I — Identity**: Is the scope what it appears to be? Is the mental model obvious?

**II — Structure**: What depends on this? What does this depend on? Any hidden contracts?

**III — Behavior**: What are the non-obvious failure modes? What edge cases exist?

**IV — Quality**: Any known issues, dark observability, or test gaps that matter here?

**V — Context**: What constraints or assumptions are load-bearing for this decision?

**VI — Discovery**: Any gaps, risks, or contradictions worth surfacing before proceeding?

**Questions**: Generate 2-3 internal questions — especially Strategic ones. If any question
would materially change the approach, surface it to the user before continuing.

---

## Decision gate

After the internal sweep:

- **No surprises found**: proceed to Phase 2 with enriched context. No output needed.
- **1-2 notable findings**: mention them briefly inline ("One thing worth noting before we
  proceed: ...") then continue.
- **Material risk or wrong-problem signal**: STOP and surface it explicitly. Ask the user
  before continuing. This is more valuable than any implementation.

---

## Skip conditions

Do NOT invoke this skill when:
- Input is already broad ("review the whole codebase", "plan the next sprint")
- Task is a simple lookup or rename with no design decisions
- You already ran this skill in the current session for the same topic
- The user explicitly said "just do it" (Small task signal — respect it)
