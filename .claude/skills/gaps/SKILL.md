---
name: gaps
spotlight: true
description: Use when hunting for incomplete implementations, missing features, unfulfilled promises, stubs, TODO markers, partial feature flags, or undocumented capabilities across a project.
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
     this banner the repo carries the SPECIFICATION and a PROTOTYPE, not an implementation:
     `spec/PROJECT_BRIEF.md` (the source of truth — mandatory reading before any application code),
     `prototype/scout.py` + `prototype/sources.yaml` (a pre-existing single-file prototype, reference
     material only), `CLAUDE.md`, `README.md`, `docs/OPEN-QUESTIONS.md`, `.claude/` and
     `scripts/claude-bootstrap/`. **Absent: `src/`, `config/`, `tests/`, a dependency manifest, a
     test runner, a linter, CI — all of it.** So: never hardcode a build, test or lint command, and
     never report a finding about `src/core/tenure.*` as if the file existed. Read the manifest for
     real script names once one exists; until then, the only executable surface is
     `python3 prototype/scout.py --help`. When the stack a step needs is absent, say so and skip the
     step. A finding invented about code that does not exist is worse than an empty report.
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
> /gaps — Use when hunting for incomplete implementations, missing features, unfulfilled promises, stubs, TODO markers, partial feature flags, or undocumented capabilities across a project.
>
> Flags:
>   --quick                        Run agents A, F, H only (~3 min, debt markers/test gaps/error handling)
>   --focus=<A|B|C|D|E|F|G|H|I|J> Run a single detection agent
>   --target=<path>                Analyze a specific directory (default: $CLAUDE_PROJECT_DIR)
>   --priority=high                Report Now items only — skip Soon/Later
>
>   --scope=project|global|both is REMOVED here — see the adaptation header, delta 4.
> ```

---

# /gaps — Incompleteness & Missing Feature Detector

Hunt for incomplete implementations, missing features, unfulfilled promises, and pending work across the entire project. Produces a prioritized roadmap. **Never auto-applies — presents a ranked plan and waits for explicit direction.**

Differentiation from `/inspect`: `/inspect` finds *what's wrong with existing things*. `/gaps` finds *what's missing or unfinished* — features described but not implemented, code started but not completed, documentation that promises things the code doesn't deliver.

Use `--quick` (agents A, F, H only — debt markers, test gaps, error handling; ~3 min), `--focus=<A|B|C|D|E|F|G|H|I|J>` (single agent), `--target=<path>` (analyze a specific directory), `--priority=high` (Now items only — skip Soon/Later).

---

## Step 0: Setup

```bash
# There is no --scope here (adaptation delta 4): one pass over $TARGET, which is either the
# explicit --target path or the project root.
TARGET="${target_arg:-${CLAUDE_PROJECT_DIR:-$PWD}}"
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
GAPS_DIR="$REPO_ROOT/var/claude/gaps"
mkdir -p "$GAPS_DIR"
TODAY=$(date +%Y-%m-%d-%H%M)
REPORT_PATH="${output_arg:-$GAPS_DIR/$TODAY.md}"
PRIOR_GAPS=$(ls "$GAPS_DIR"/*.md 2>/dev/null | sort -r | head -1 || true)
```

Announce: "Scanning gaps: `$TARGET` → saving to `$REPORT_PATH`"

If a prior `/gaps` run exists: note its date. Agents will flag items that have been pending since the prior run as [STALE], helping prioritize chronic incompleteness over fresh debt.

**No `--scope` handling** (adaptation): a single pass over `$TARGET`. If a caller passes `--scope=global` or `--scope=both`, say plainly that the flag was removed for this repo and why, then run the project pass.

## Step 1: Detect Project Context

```bash
ls "$TARGET"/{pyproject.toml,requirements*.txt,setup.cfg,package.json,Makefile,docker-compose*.y*ml,README.md} 2>/dev/null
[[ -f "$TARGET/CLAUDE.md" ]] && head -60 "$TARGET/CLAUDE.md"
git -C "$TARGET" log --oneline -10 2>/dev/null || true
find "$TARGET" -maxdepth 2 \( -name "*.md" -o -name "*.sh" -o -name "*.php" -o -name "*.ts" \
  -o -name "*.dart" \) -not -path '*/vendor/*' -not -path '*/node_modules/*' 2>/dev/null | head -20
```

Summarize: what actually exists (spec only / prototype only / a real `src/` tree), approximate project age (from git log), primary language(s), team size (from commit authors — single-developer here). Pass as `PROJECT_CONTEXT` to each agent.

**On a greenfield tree, most of what is "missing" is simply unwritten.** When a stack is absent, do not enumerate its unbuilt features as gaps — that turns the whole product backlog into a report. Restrict findings to promises that something in the repo actually makes: a documented command with no file behind it, a plan in `docs/plans/` whose stated next step was never taken, a placeholder or template marker left in place, a config key referenced but never defined. Say plainly which stacks were absent and therefore not scanned.

## Step 2: Spawn Gap-Detection Agents

Respect flags:
- `--quick`: spawn only agents A, F, H (debt markers, test gaps, error handling)
- `--priority=high`: instruct agents to report Now-priority items only
- `--focus=<X>`: spawn only that agent
- Default: spawn in two sequential batches — **never exceed 5 concurrent LLM agents** (5 is the proven rate-limit ceiling; >5 causes ~50% failures):
  - **Batch 1**: spawn agents A–E in one message; wait for all 5 to complete before continuing
  - **Batch 2**: spawn agents F–J in one message; wait for all 5 to complete

**Agent A: Explicit Debt Markers** — TODO, FIXME, HACK, XXX, WORKAROUND, BUG, KLUDGE comments; classified by age and actionability.

**Agent B: Stubs & Placeholder Detection** — empty function bodies, `raise NotImplementedError`, hardcoded placeholder returns, shell scripts with TODO bodies.

**Agent C: Partial Feature Implementations** — unhandled switch/match cases, parsed-but-unused CLI flags or config keys, stub adapters, features with empty branches, and missing branches in the tenure decision tree (an unhandled `Tenure` variant, a confidence band with no route, a signal source declared in the spec but never consulted — an unreachable or unhandled tenure is a real gap, not a style note). Also: a source declared in `config/sources.yaml` with no fixture, and a CLI verb promised in `spec/PROJECT_BRIEF.md` §10 that does not exist.

**Agent D: Undocumented Features (code exists, docs absent)** — commands not in CLAUDE.md, env vars not in the committed .env files (api/.env, api/.env.test, infra/.env), Makefile targets not in README, hook scripts not in docs.

**Agent E: Promised Features (docs mention, code missing)** — commands in CLAUDE.md with no file, env vars documented but never read, workflows referencing scripts that don't exist, and any capability a `docs/plans/<topic>.plan.md` records as decided but that nothing implements.

**Agent F: Missing Tests for Named Features** — named features with zero tests, error paths with no test, workflows with no integration test.

**Agent G: Config & Environment Gaps** — env vars used but not in the committed .env files (api/.env, api/.env.test, infra/.env), required config with no startup validation, placeholder values with no format hint.

**Agent H: Missing Error Handling Paths** — happy path without error path, silent switch/if fall-throughs, cleanup that runs on success but not failure.

**Agent I: Template & Placeholder Markers** — `<!-- ADAPT: -->` markers, unactivated `.sh.template` files, `{{VAR}}` placeholders, skeleton banners still present.

**Agent J: Integration & Dependency Stubs** — interfaces with no concrete implementation, abstract base classes never subclassed, plugin systems with no plugins registered, unused imports, Makefile targets calling non-existent scripts.

---

## Step 3: Synthesize Gaps Report

```markdown
# /gaps Report — <DATE>
Scanned: <DATE> | Project: <TARGET> | Stack: <PROJECT_CONTEXT>

## Executive Summary
[3-5 sentences: dominant type of incompleteness, most actionable gap, overall completeness feel]

## Priority Roadmap

### NOW — Act immediately (blocking or high-impact)
| # | Category | Gap | Location | Effort |

### SOON — Important but not blocking
| # | Category | Gap | Location | Effort |

### LATER — Nice to have
| # | Category | Gap | Location | Effort |

## Findings by Category
[A through J sections]

## Stale Gaps [CHRONIC] *(only if prior run exists)*
Items present in prior run that are still unfilled.

## Quick Wins (Effort=Quick, Priority=Now or Soon)
| # | Category | Gap | Next action |
```

## Step 4: Save Report

Write the synthesized report to `$REPORT_PATH`.

## Step 4b: Self-Reflection

Spawn ONE agent to reflect on this command's own definition using the just-saved report as evidence. Pass the actual `$REPORT_PATH` value. The agent produces its blind spots, prompt drift, and proposed changes sections, then reads `$REPORT_PATH` and writes the complete updated file (original content + its block) using the Write tool. Returns only: "Self-reflection appended." Parent announces: "Self-reflection complete — see `$REPORT_PATH`"

## Step 5: Present Roadmap — Hard Stop

Show: Executive summary, full NOW table, Quick Wins table, counts of SOON/LATER.

**Close with a non-blocking offer** (rent-watch adaptation — no interrupts, matching `/sleuth`,
`/inspect` and `/forge`). Report the findings, state that nothing was changed, and offer the next
steps — then END THE TURN. Do **not** block on a question: the report is the deliverable, and the
developer picks up from it whenever they choose.

```
N gaps found (Now: X | Soon: Y | Later: Z). Nothing has been changed — every finding above is
a proposal.

Say the word and I can:
1. Fix specific gaps (recommended, cheapest first) — name the IDs, e.g. `G1, G3`.
2. Show all Soon items.
3. Show one category in full — name it, e.g. `category B`.
4. None of these / challenge the premise — if a "gap" is deliberate, say so and I will record it
   as intentional rather than re-report it next run.
```

*Never auto-fills anything. The user decides what to close.*
