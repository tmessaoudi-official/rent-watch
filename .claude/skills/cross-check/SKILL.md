---
name: cross-check
description: Deep standalone validation of a spec or doc — hunts contradictions, undefined terms, unstated assumptions, missing sections and ambiguities, then certifies the analysis with fresh-context reviewer subagents. Use it on a doc before building from it, or to detect doc-vs-reality drift.
user-invocable: true
args: "<spec-file> [--drift] [--dry-run]"
disallowed-tools: AskUserQuestion
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  rent-watch CONTAINER ADAPTATION (2026-08-06). Imported from the developer's machine bundle
  `claude-setup-global-20260722` by way of the already-container-adapted `stack` port (which is where
  the `--drift` mode below was invented). This skill is the highest-value one in the repo right now:
  rent-watch's `spec/PROJECT_BRIEF.md` is currently the ONLY substantial artefact, so validating it —
  and later detecting drift between it and a real `src/` — is exactly this skill's job. These deltas
  OVERRIDE the body below wherever they conflict:

  1. QUESTIONS ARE PLAIN TEXT. `AskUserQuestion` TIMES OUT in this container, so a gate that "asks"
     cannot fire. Every "invoke ask-human" below means: print the question, a minimal concrete example,
     numbered options and the recommendation as ordinary prose, then STOP and wait. Protocol:
     `.claude/skills/ask-human/SKILL.md`. Every reply ends with a `❓ QUESTION` / `⏹ NO QUESTION`
     marker as its literal last line.
  2. NO `advisor()` HERE. Independent certification = fresh-context read-only reviewer subagents —
     the three rent-watch lenses in `.claude/agents/` (`tenure-correctness-reviewer`,
     `source-resilience-reviewer`, `completeness-reviewer`). Spawn them by name rather than
     re-describing their charter inline. Self-grading is the last resort and MUST be DISCLOSED as
     self-graded in the output.
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored by the blanket `/var` rule, survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`, which is wiped when
     the container is reclaimed.
  4. THE JIRA MODE IS DELETED (inherited from the `stack` port). There is no Jira and no Jira MCP
     server here, so the mode could never run — a documented mode that cannot execute is worse than an
     absent one. `--drift` is the check this project actually needs.
  5. THE PRIMARY TARGET IS `spec/PROJECT_BRIEF.md`, and it is a RULING SET, not a draft. A constraint
     being inconvenient is not a contradiction. Legitimate findings here are: the brief contradicting
     ITSELF, a term used before it is defined, an unstated assumption a builder would have to guess at,
     or a requirement that cannot be satisfied as written. "This would be easier as a web service" is
     not a finding — it is the brief being disagreed with (see `/forge`'s Chesterton gate).
     `docs/OPEN-QUESTIONS.md` is the brief's inverse: what is listed there is explicitly NOT decided,
     so an ambiguity already recorded there is not a new finding — cite it and move on.
  6. PROJECT RULES WIN on any conflict: `/home/user/rent-watch/CLAUDE.md`.
═══════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /cross-check — Deep standalone validation of a spec or doc: contradictions, undefined terms,
>                unstated assumptions, missing sections, ambiguities. Certified by fresh-context
>                reviewer subagents.
>
> Usage: /cross-check <spec-file> [--drift] [--dry-run]
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /cross-check — Doc validation

Parse `$ARGUMENTS`:

## Flags

| Flag | Behavior |
|------|----------|
| `<spec-file>` | Path to the doc to validate (required) |
| `--drift` | Also verify every checkable claim against the actual repo state (see Mode B) |
| `--dry-run` | Print findings to conversation only; no output file written |

If `<spec-file>` is not provided: report the error and stop.

Natural targets in this repo: `CLAUDE.md`, `templates/tips/env-update.md`, `templates/tips/env-scan.md`,
`templates/tips/file-layout.md`, `README.md`, `TODO.md`, `docs/**`, any `docs/plans/*.plan.md`, and
`scripts/claude-bootstrap/README.md`.

---

## Mode A — internal consistency (default)

### Step 1 — Read the doc fully

Read `<spec-file>` completely before forming any judgement. Do not skim; a contradiction between
section 2 and section 19 is invisible to a partial read, and that is the class of finding this skill
exists for.

### Step 2 — Independent check

Investigate the three angles yourself, then certify with **fresh-context read-only reviewer subagents**
that read the doc themselves (`advisor()` does not exist here). Loop: investigate → certify → repeat
until a round raises nothing new; cap at 5 rounds, then ask in plain text — never silently proceed.

- **Angle 1** (expanding-context): Are there implicit requirements not explicitly stated? Assumed
  context a reader might not share?
- **Angle 2** (adversarial): What internal contradictions exist? What claim in one section is
  contradicted in another?
- **Angle 3** (blast-radius): What is missing? What should be specified but isn't? Which edge cases
  are unaddressed?

Give the reviewers the doc and the analysis so far. If any raises something new, resolve it and re-run
the round.

### Step 3 — Categorise findings

- **CONTRADICTION** — a claim in section A directly contradicts a claim in section B
- **UNKNOWN** — a term or concept used without definition or reference
- **ASSUMPTION** — an implicit prerequisite not stated
- **MISSING** — a section that should exist but doesn't (error handling, rollback, security…)
- **AMBIGUOUS** — a statement that can be read more than one way
- **STALE** — a claim that was true once and is contradicted by the current tree (only with `--drift`)

---

## Mode B — `--drift`: doc vs reality

This project's docs make many **mechanically checkable** claims, and a stale one is worse than a
missing one because it is trusted. For every such claim in the doc, verify it and record the command
you ran as the evidence. Examples of what is checkable here:

| Claim shape | How to verify |
|---|---|
| A file/path layout claim | `ls` / `find` the path. A documented path that does not exist is STALE. As of 2026-08-06 `src/php/Core/` and `tests/` EXIST and `config/`, `src/php/Adapters/` and `src/phorj/` do not — so check, do not recall: this row asserted all three were absent for four commits after two of them landed. Report a path claim as stale only where the doc asserts it EXISTS. |
| "the classifier corpus has ≥30 hand-labelled cases" | `python3 -c "import json;print(len(json.load(open('tests/fixtures/tenure/corpus.json'))['cases']))"` — the cases live INSIDE one JSON file, so the `ls \| wc -l` this row used to give counted 1 and would have passed a corpus of any size. The brief's §4 number is a requirement; the count is the check |
| "source X is enabled" | `yq '.sources[] \| select(.name=="X") \| .enabled' config/sources.yaml` |
| "every enabled source has a verified URL" | `grep -n 'REMPLACER' config/sources.yaml` — any hit on an `enabled: true` block is STALE and a hard-rule-1 violation |
| "source X mixes social and intermediate stock" | `yq '.sources[] \| select(.name=="X") \| .mixed_tenure' config/sources.yaml` — and cross-read the fixture: a fixture containing a `PLAI`/`PLUS` value on a `mixed_tenure: false` source is the highest-severity drift this repo can have |
| A `map:` field path | check it against that source's committed fixture — a mapped path absent from the fixture is STALE and fails silently at runtime |
| An env var name or default | `grep '^SCOUT_' .env.example` — **never** read the real `.env`, which is permission-denied and gitignored |
| A CLI verb or flag exists | run it with `--help`; a documented `scout` verb that is not in the parser is STALE |
| "N skills / N agents / N hooks exist" | `ls .claude/skills/`, `ls .claude/agents/`, `ls .claude/hooks/` — and check the inventory table in `CLAUDE.md` § "Claude config in this repo" against the result |
| A tool is available | `command -v <tool>`. Run it, do not recall it. [Verified 2026-08-06 by `command -v`: `ruff`, `python3`, `jq`, `git`, **`pytest`** and **`yq`** ARE present; `shellcheck`, `yamllint`, `shfmt` and `hadolint` are ABSENT.] An earlier version of this row asserted `pytest`/`yq` "may not be" present when both were — asserted from memory in the very row that says to check. Any doc claiming a command runs, without a manifest that wires it, is conditionally stale. |
| The prototype's behaviour | `python3 prototype/scout.py --help`. The prototype is the one runnable thing; a claim about it is cheap to verify and several in `CLAUDE.md` § Gotchas were written from reading it. |

Report each as **STALE** with: the claim, the command, its actual output, and the corrected value.
Do **not** silently fix the doc — report first. Docs are the project's memory; a correction the
developer has not seen is indistinguishable from a new error.

Counts drift fastest and are the highest-yield thing to check.

---

## Step 4 — Write output

- `--dry-run`: print to conversation only, then stop.
- Otherwise: write to `var/claude/reports/crosscheck-<basename>-<date>.md` (gitignored). Do **not**
  write `<spec-file>.validation.md` next to the source — that path is tracked here and the report is
  session state, not a deliverable.

State in the output whether certification was by reviewer subagents or **self-graded** (and if
self-graded, say why no reviewer was available). Also state which claims you could **not** check and
why — a doc validated with unverifiable claims silently marked OK is the failure mode this skill is
supposed to catch.
