---
name: retrospective
spotlight: true
description: Use at the end of a long or complex session for deliberate end-of-session learning extraction and memory capture across hidden dependencies, naming surprises, behavioral quirks, and decision rationale.
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
     `scripts/claude-bootstrap/`. **Present since 2026-08-06: `src/php/Core/` (the pure core), `tests/php/`, `composer.json` and a
     PHPUnit runner at `tools/phpunit.phar` — verify with `git ls-files` rather than trusting this
     line. Still absent: `config/`, any adapter, a
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
> /retrospective — End-of-session deliberate learning extraction and memory capture across hidden dependencies, naming surprises, behavioral quirks, and decision rationale.
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /retrospective — Session Learning Capture

Manual trigger for end-of-session learning extraction. Companion to the automatic Phase 8 learning prompt — use this for a deliberate sweep after long or complex sessions.

**Flags**:

| Flag | Behavior |
|------|----------|
| `--quick` | Skip to the 2 highest-signal lenses only (Failure pattern + Decision rationale); skips all 6-lens scan; output is a compact 2-question pass. |
| `--source=project\|all` | (default: `all`) — `all` runs Step 2.5's duplicate check against `var/claude/memory/`; `project` **skips** Step 2.5 entirely. Upstream used this flag to scan *other projects'* `MEMORY.md` indices; there is no memory pipeline and no other project here [Verified: `find / -name MEMORY.md` → nothing], so the cross-project half does nothing — but the skip is real, so the flag is not inert. |

---

## Step 1: Reconstruct what happened

Review the session by scanning:
```bash
git diff --stat
git log --oneline -10
```

If git shows nothing (e.g. the session only touched gitignored paths), fall back to recency rather
than to a reference file — rent-watch has no single manifest to compare against (on
2026-07-29 the API is partly built and both clients are scaffolded):
```bash
find "${CLAUDE_PROJECT_DIR:-$PWD}" -mmin -720 -type f \
  \( -name '*.php' -o -name '*.ts' -o -name '*.dart' -o -name '*.md' -o -name '*.sh' \
     -o -name '*.json' -o -name '*.yaml' -o -name '*.yml' \) \
  -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*/build/*' \
  -not -path '*/.git/*' -not -path '*/var/*' 2>/dev/null | head -20
```
Also check the conversation context directly — it is the authoritative record of what was done.

Summarize in one paragraph: what was the core task, what approach was taken, what changed.

---

## Step 2: Extract non-obvious discoveries

**If `--quick` flag was passed**: scan only "Failure pattern" and "Decision rationale" lenses. Skip all others and jump directly to Step 3 with those 2 results.

For each of these lenses, ask the question and answer honestly — skip any where the answer is "nothing surprising":

| Lens | Question |
|------|----------|
| **Hidden dependency** | Did anything turn out to depend on something that wasn't documented? |
| **Naming surprise** | Was anything named differently than expected (script, var, path, command)? |
| **Behavioral quirk** | Did a tool, command, or system behave in a non-obvious way? |
| **Failure pattern** | What broke, and why — and would it be easy to repeat the mistake? |
| **Workaround** | Was something fixed with a workaround that future sessions should know about? |
| **Decision rationale** | Was a design choice made that isn't obvious from the code alone? |

---

## Step 2.5: Cross-project index enrichment (skip if `--source=project` or `--quick`)

**Index scan** — read the memory indices, text only, no full file reads:
```bash
# In this container there is exactly ONE memory home: the repo's own gitignored var/claude/memory.
# Other projects' memories lived under ~/.claude/projects/<slug>/ upstream and are not reachable here.
ls "${CLAUDE_PROJECT_DIR:-$PWD}"/var/claude/memory/*.md 2>/dev/null
```

**Honest scope note (rent-watch adaptation):** with a single memory home, this step degrades from
cross-*project* enrichment to a within-repo duplicate check — it catches an entry you already wrote in
an earlier session of this repo, and nothing more. Say so rather than implying a fleet-wide scan; the
`[SEEN in N other projects]` annotation below can only fire if a genuinely separate memory home exists.

For each proposed entry from Step 2, compare its description + key terms against the index lines already present:

- **No match in any other project** → proceed normally, save as project memory in Step 4
- **Match found in ≥1 other project** → annotate with `[SEEN in N other projects: slug1, slug2]` and mark as **PROMOTION CANDIDATE**

Annotation format for Step 3 preview:
```
[2] type: feedback | file: feedback_<slug>.md
    name: <name>
    description: <one-line description>
    body preview: <first 3 lines>
    ⚡ PROMOTION CANDIDATE — also seen in: <other-slug>, <other-slug> [2 other projects]
```

Be conservative on matching — only flag when there is strong textual overlap in the description. When uncertain, do not annotate (saving as project memory is safe; false promotion flags are noise).

---

## Step 3: Present proposed memory entries — confirm before saving

For each non-trivial discovery from Step 2, draft the memory entry but **do not write it yet**.
Present each proposed entry as a numbered preview:

```
Proposed memory entries (N total):

[1] type: project | file: project_<slug>.md
    name: <name>
    description: <one-line description>
    body preview: <first 3 lines of content>

[2] type: feedback | file: feedback_<slug>.md
    ...
```

**Write them, then report (rent-watch adaptation — no interrupts).** Upstream stops here for
per-entry approval. That gate existed because the upstream target was the developer's real
`~/.claude/projects/<slug>/memory/`. Here the target is **`var/claude/memory/`, which is
gitignored and dies with the container** — so an unwanted entry costs nothing and needs no
confirmation. Write all entries, then state plainly what was written:

```
[retrospective] wrote N entries → var/claude/memory/
  1. project  — <name>
  2. feedback — <name>
```

Two things this does NOT license:

- **Never write into the repo proper.** No `CLAUDE.md` edit, no `docs/` file, no committed artifact
  from this skill. A discovery worth keeping permanently is a **CLAUDE.md § Gotchas** entry, and that
  is a real change — propose it in plain text with the exact diff and let the developer rule on it.
- **Never invent a discovery to fill the report.** If nothing non-obvious came up, write nothing and
  say `No discoveries worth persisting.` A padded retrospective is worse than an empty one.

---

## Step 4: Save the entries

For each entry (Step 3 writes them all — there is no approval gate here; the no-interrupts directive
removed it, and reporting after the fact is the substitute):

- If it's about **the project** (a quirk, a hidden dep, a workaround): save to `project_*.md` memory
- If it's about **how to collaborate** (a preference revealed, an approach that worked well): save to `feedback_*.md` memory
- If it's about **the user** (a skill gap revealed, a domain they know deeply): save to `user_*.md` memory

Write each discovery as a standalone memory entry — not a bullet in an existing file unless it naturally extends one. Keep entries focused: one fact, one "Why:", one "How to apply:".

Write to `var/claude/memory/` (gitignored) — **not** `MEMORY.md`, which does not exist here; upstream's memory pipeline is not installed, so there is no index to update. If a learning is durable enough to outlive the container, it does not belong in `var/` at all: put it in `CLAUDE.md` § "Gotchas" or the relevant `docs/plans/<topic>.plan.md` `## Decisions Log`, and commit it.

---

## Step 5: Report

Print a summary:
```
Retrospective complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Session scope : [1-sentence summary]
Discoveries saved : N
  - [file] → [one-line description]
  ...
Nothing to save : [list lenses that returned no findings]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

If Step 2 found nothing for any lens: report "No non-obvious discoveries — session was routine." and stop.
