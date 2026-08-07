---
name: aggregate-findings
spotlight: true
description: Cross-stage synthesis of review reports — deduplicates findings that appear across /inspect, /sleuth, /gaps, /sweep and /inspect --vision runs. Produces one prioritized master list with cross-references instead of N separate reports. Use after running two or more of those skills.
user-invocable: true
args: "[--top=N] [--since=<date>]"
side-effects: Writes a consolidated report to var/claude/reports/aggregate-<date>.md (gitignored; never committed)
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

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /aggregate-findings — Cross-stage synthesis of review reports — deduplicates findings across /inspect, /sleuth, /gaps, /sweep and vision runs into one prioritized master list.
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /aggregate-findings

## When to use
Run after **two or more** of `/inspect`, `/sleuth`, `/gaps`, `/sweep`, `/inspect --vision` have produced reports, to synthesize them into one deduplicated, prioritized master list. (`/mega-analysis` was imported by none of the pdfturbo, twes-in or rent-watch ports, so there is no umbrella run to key off: the stage set is simply whatever reports exist under `var/claude/`.)

## Flags
- `--top=N` — show only the top N unique findings (default: all)
- `--since=<date>` — only aggregate reports dated on/after this (default: the most recent report per skill)

## Step 0 — Locate reports

```bash
# Reports live in the repo under var/ (gitignored) — see the adaptation header.
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
REPORT_ROOT="$REPO_ROOT/var/claude"
mkdir -p "$REPORT_ROOT/reports"
# Enumerate what actually exists — this list IS the stage set:
find "$REPORT_ROOT" -name '*.md' -not -path '*/reports/*' | sort
```

Enumerate every report found and state the count before reading — an unlisted report is a coverage gap.

## Step 1 — Read all stage reports (parallel, max 5 at a time)

Read every report enumerated in Step 0, in batches of ≤5 files (the project's concurrency ceiling for LLM-backed agents is 5). Typical stage set here: `/inspect`, `/inspect --vision`, `/sleuth`, `/gaps`, `/sweep`. There is no global-scope pass — those flags were removed on import.

Read each file and pass to Step 2.

## Step 2 — Spawn 3 synthesis agents (parallel)

Spawn exactly 3 agents with the full report content:

### Agent 1: Deduplication detector
Prompt: "You are given N stage reports from this project's review skills — a single-language CLI watcher whose specification, prototype and (eventually) implementation describe the same behaviour, so the same underlying defect is often reported once against the spec and once against the code and must be collapsed. Your job is to identify findings that appear in 2 or more stage reports — these are the highest-confidence issues. For each cross-stage finding, list: the finding name/ID, which stages mention it, what each stage says (noting any contradictions), and a deduplicated one-sentence summary. Output as a markdown table. Only report findings that appear in ≥2 stages."

### Agent 2: Priority ranker
Prompt: "You are given N stage reports from this project's review skills. Your job is to produce a single master priority list of ALL unique findings (not cross-stage-only), ranked by: (1) severity (P0/High before P1/Med), (2) fix cost (Quick before Long), (3) breadth of impact. Remove exact duplicates. Format: numbered list with severity badge, one-line description, estimated fix time, and which stage it came from. Cap at 50 entries."

### Agent 3: Quick wins extractor
Prompt: "You are given N stage reports from this project's review skills. Your job is to extract all 'quick win' findings: severity P1 or higher AND fix cost ≤30 min. These are the highest-value, lowest-effort items. Output as a table: finding, stage, exact file/line, exact fix, minutes. Include at most 20 rows; rank by impact."

## Step 3 — Synthesize into consolidated report

Combine all 3 agent outputs into:

```markdown
# Aggregate Findings — <date>
Generated: <timestamp> | Stages: <N, named> | Raw findings: ~<N> | Unique after dedup: ~<N>

## Top 10 Cross-Stage Findings (appear in ≥2 stages — highest confidence)
[Agent 1 table]

## Quick Wins (P1+ / ≤30 min fix)
[Agent 3 table]

## Master Priority List (all unique findings, ranked)
[Agent 2 list]
```

## Step 4 — Save and report

Save to `var/claude/reports/aggregate-<date>.md` (gitignored — never `git add` it).

Report to user:
- Total unique findings
- Cross-stage duplicates found and collapsed
- Top 5 quick wins
- Suggest: "Run `/aggregate-findings --top=10` to see just the highest priority items"
- Name any report that existed but could not be parsed — a silently dropped stage is a coverage lie

## Self-reflection
After saving, note any findings where the stages disagreed (e.g., one stage calls it P0, another calls it P2). Flag these as "conflicting severity" in the report.
