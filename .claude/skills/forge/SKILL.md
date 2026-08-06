---
name: forge
spotlight: true
description: Use when you want adversarial critique of architecture, design patterns, and structural decisions in a codebase. Demands justification for every structural choice using the Chesterton's Fence protocol. 9 parallel analysis agents, each with exclusive ownership rules, feed a synthesis agent that deduplicates and produces a clean action list. Never auto-applies anything.
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

## Side effects
- Creates: `var/claude/forge/raw/<A–I>.md` (intermediate, overwritten per run; gitignored)
- Creates: `var/claude/forge/YYYY-MM-DD-HHMM.md` (final report, new file per run; gitignored)
- Reads: project source files, git log, ADR files (read-only)
- Never modifies source files

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /forge — Use when you want adversarial critique of architecture, design patterns, and structural decisions in a codebase.
>
> Flags:
>   --quick                      Run agents A, B, D only (fastest signal-to-noise ratio; Agent I excluded)
>   --focus=<A|B|C|D|E|F|G|H|I>  Run a single analysis lens + synthesis
>   --target=<path>              Analyze a specific directory
>   --output=<path>              Override default report path (default: $FORGE_DIR/YYYY-MM-DD-HHMM.md)
> ```

---

# /forge — Adversarial Design Critic

Interrogates structural decisions in a codebase and demands justification. Applies Chesterton's Fence as a gate: if a structural choice cannot be justified by git history, ADRs, or docs, it is challenged with a named principle, a concrete alternative, and a cost estimate. **Never auto-applies anything — this command only reads and reports.**

## Differentiation from related skills

- **`/inspect`** — diagnoses health issues (security, dead code, error handling, config drift). Produces a P0–P3 defect list. `/forge` interrogates *design decisions*, not defects.
- **`/inspect --vision`** — Vision Agent VA *proposes* architectural improvements (current state → proposed state). `/forge` *interrogates* the current state and demands justification before considering any alternative.
- **`/sleuth`** — hunts behavioral bugs: logic traps, silent failures, contract violations, timing issues. `/forge` does not look for runtime bugs — it challenges structural decisions that survive at compile time.

Use `/forge` when you want adversarial "why does this exist this way?" pressure on the codebase, not a defect list or improvement proposals.

---

Use `--quick` (agents A, B, D only — fastest signal-to-noise; bare `/forge` still runs all 9, see Step 0), `--focus=<A|B|C|D|E|F|G|H|I>` (single analysis lens + synthesis), `--target=<path>` (specific directory). `--scope=global|both` is REMOVED: `~/.claude/` in this container is generated from repo files by `scripts/claude-bootstrap/install.sh`, so auditing it audits a copy.

---

## Step 0: Setup

```bash
TARGET="${target_arg:-${CLAUDE_PROJECT_DIR:-$PWD}}"
# Reports live in the REPO under var/ (gitignored, survives compaction) — never ~/.claude,
# which is wiped when the container is reclaimed. Never commit them.
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
FORGE_DIR="$REPO_ROOT/var/claude/forge"
mkdir -p "$FORGE_DIR/raw"
TODAY=$(date +%Y-%m-%d-%H%M)
REPORT_PATH="${output_arg:-$FORGE_DIR/$TODAY.md}"
PRIOR_REPORT=$(ls "$FORGE_DIR"/*.md 2>/dev/null | grep -v '/raw/' | sort -r | head -1 || true)
```

Announce: "Forging: `$TARGET` → report: `$REPORT_PATH`"
If `$PRIOR_REPORT` is non-empty, note its date for comparison.

**Announce the cost, then run (rent-watch adaptation — no stop).** Upstream halts here for confirmation;
this repo's standing directive is no interrupts, and the developer invoking `/forge` has already
chosen to pay for it. So print one line and proceed:

```
[forge] Large task — N analysis agents (≤5 concurrent) + 1 synthesis, writing to $FORGE_DIR/
```

Mode selection is by flag only, never by question: bare `/forge` → all 9 agents; `--quick` → A, B, D;
`--focus=<X>` → agent X + synthesis. If a caller passes `--scope=global|both`, say plainly that the
flag was removed for this repo and why, then run the project pass.

**Respect the ≤5-concurrency cap**: spawn the 9 agents in batches of at most 5, never all at once
(10 concurrent LLM agents caused ~50% rate-limit failures upstream).

## Step 1: Detect Project Context

```bash
ls "$TARGET"/{pyproject.toml,requirements*.txt,setup.cfg,package.json,Makefile,docker-compose*.y*ml,*.sh} 2>/dev/null
[[ -f "$TARGET/CLAUDE.md" ]] && head -60 "$TARGET/CLAUDE.md"
```

Scan for design rationale in git: `git -C "$TARGET" log --oneline -50 2>/dev/null | grep -iE 'adr|decision|design|arch|refactor|rewrite|why|chose|chose|picked'`

Find the WHY corpus. **rent-watch's lives in three places, and on 2026-08-06 two of the three are empty.** The
intended register is `docs/plans/<topic>.plan.md` — each plan carries its own `## Decisions Log`,
which is where a structural ruling is recorded — plus `CLAUDE.md` § "Gotchas & pitfalls". The third
and, right now, by far the richest source is `spec/PROJECT_BRIEF.md`: it is a specification the
developer wrote deliberately, so **every constraint in it is a ruling**, not an accident to be
challenged. `docs/OPEN-QUESTIONS.md` is its inverse — what is listed there is explicitly NOT yet
decided, and proposing an answer for it is useful rather than presumptuous. Establish what the corpus
actually contains before you challenge anything; the honesty of this gate depends on it:

```bash
ls docs/plans/*.plan.md 2>/dev/null | wc -l            # plan files = the decision register
grep -n '## Decisions Log' docs/plans/*.plan.md 2>/dev/null | head -40
[[ -f CLAUDE.md ]] && wc -l CLAUDE.md || echo "no CLAUDE.md yet — no ruled invariants to cite"
[[ -f spec/PROJECT_BRIEF.md ]] && wc -l spec/PROJECT_BRIEF.md   # the specification IS a ruling set
[[ -f docs/OPEN-QUESTIONS.md ]] && wc -l docs/OPEN-QUESTIONS.md # the explicitly-undecided list
[[ -f README.md ]] && wc -l README.md
git log --oneline -50 | grep -iE 'fix|refactor|revert|why'
```

**If that corpus comes back empty, say so and stop early.** A greenfield tree has almost no structure
to interrogate and no record to interrogate it against — under the gate below, nearly every finding
would fail the Chesterton check and be dropped anyway. Reporting "0 findings — no `src/` tree
yet; the specification and the prototype are the only structure that exists" is the correct output
for the parts that do not exist — but `prototype/scout.py` and `spec/PROJECT_BRIEF.md` ARE real and
are fair game, so do not wave them off as absent either; manufacturing structural criticism of code
that does not exist is the failure mode this skill is most prone to.

Four rent-watch-specific rules for the Chesterton's Fence gate:

1. **A `## Decisions Log` entry IS a ruling.** If the choice you want to challenge is explained in a
   `docs/plans/<topic>.plan.md` decisions log (or, once it exists, in `CLAUDE.md` § Gotchas), it is
   **Justified by definition** — the developer decided it, usually after a real failure. Re-litigating
   it is the single worst output this skill can produce.
2. **`spec/PROJECT_BRIEF.md` is a ruling set, not a draft.** Its constraints were chosen: the
   fail-closed classifier, email-alert ingestion as the primary private-portal path, no web UI, no
   auto-application, no CAPTCHA solving, single-user. "This would be more capable as a web service"
   or "polling would be simpler than IMAP" are not findings — they are the brief being disagreed
   with. A genuine finding here shows the brief is *internally inconsistent*, or that a constraint
   cannot be met as written; both are worth raising.
3. **A guard in the tenure or health path is a feature.** A classifier that returns `UNKNOWN` rather
   than guessing, a criteria engine that refuses a listing whose tenure never resolved, a source that
   raises instead of returning `[]`, a scraper that will not run without an explicit flag — these are
   the eligibility and legal invariants. Proposing to "just allow" one, or to make an excluded tenure
   configurable, without addressing the invariant it protects is a P0-grade misread. Fail-closed is
   not over-engineering here: a social-housing false positive is a wasted application.
4. **`prototype/scout.py` is not the architecture.** It is pre-existing reference material, explicitly
   superseded by the layered design in the brief. Findings *about* it are welcome as evidence of what
   the real implementation must avoid, but "the prototype does it this way" is never authority for how
   `src/` should be built, and proposing to extend the prototype in place contradicts the brief.

Summarize in two lines: (1) tech stack sentence for `PROJECT_TYPE`; (2) ADR/design doc inventory for `ADR_CONTEXT` (e.g., "3 ADR files found at docs/adr/; relevant commits: …"). Pass both to every agent.

## Step 2: Spawn Analysis Agents

Respect flags before spawning:
- `--quick`: spawn only A, B, D
- `--focus=<X>`: spawn only that agent; proceed to Step 3 (synthesis) after it completes
- Default: two sequential batches — **never exceed 5 concurrent LLM agents**:
  - **Batch 1**: spawn A, B, C, D, E in one message; wait for all 5 to complete
  - **Batch 2**: spawn F, G, H, I in one message; wait for all 4 to complete

Replace `<TARGET>` with the actual target path. Replace `PROJECT_TYPE` and `ADR_CONTEXT` with the values from Step 1. Replace `CURRENT_DATE` with today's date. Replace `$FORGE_DIR` with the actual path.

**Every agent must write its raw output to `$FORGE_DIR/raw/<letter>.md` before returning.**

---

### Chesterton's Fence Protocol (applied inside every agent for every finding)

Before escalating any structural choice to Questionable or Unjustified:

1. Check git log (last 50 commits), ADR files, and inline docs for an explicit WHY.
2. Rationale found → verdict is **Justified** — note the rationale, stop. Do not report this finding.
3. Rationale not found → escalate **only if all 4 required fields are populated**:
   - **Named principle violated** — specific authority (e.g., Parnas/information hiding, Fowler/Shotgun Surgery, SOLID/SRP, Ousterhout/deep module)
   - **Concrete alternative** — one specific better structure with a 2-sentence implementation sketch
   - **Cost to change** — Low (< 1 day) / Medium (1–3 days) / High (> 3 days)
   - **Cost to keep** — coupling tax, cognitive tax, or evolution risk in one sentence

If any of the 4 fields cannot be populated → verdict defaults to **Justified** (drop the finding, do not report it). Incomplete challenges are noise.

---

### Paradigm auto-detection (per agent, per module)

Before applying principles, identify the dominant paradigm **per module**, not per project: "predominantly OOP", "predominantly FP", "procedural", or "mixed OOP/FP". Apply principles per-region:
- OOP regions: SOLID, composition vs. inheritance, DDD model richness
- FP regions: pure functions, immutability, referential transparency
- Suppress false positives (e.g., do not flag lack of classes in a functional module, do not flag mutation in a systems/scripting context where it is idiomatic)

---

**Agent A — Architecture & Inter-Module Structure**

OWNS: All cross-module structural concerns.
DELEGATES: single-module internal design → B; boundary/interface design → C.

> Analyze `<TARGET>` as an adversarial architecture critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN cross-module structure. If a finding is about one module's internal design, it belongs to B. If it is about what a module exposes at its boundary, it belongs to C.
>
> Auto-detect paradigm per module before applying principles.
>
> Interrogate: (1) Dependency direction — do dependencies flow toward stable, abstract modules? Any module depending on a more-volatile or more-concrete module? (2) Layer violations — does the dependency graph respect declared layers (presentation → domain → infrastructure)? Any upward dependency? (3) Conway's Law — does the module structure reflect the org/team structure, or would a different structure reduce coordination cost? (4) Connascence across module boundaries — are modules coupled by value (good), type, meaning/convention (bad), position (very bad), or algorithm (worst)? (5) Pattern-to-scale fitness — is the architectural pattern (monolith, modular, service, layered, event-driven) appropriate for the project's current size and growth trajectory?
>
> For each finding: run Chesterton check (git log, ADRs, inline docs). Rationale found → skip. Not found → report with all 4 required fields. Verdict: Justified | Questionable | Unjustified.
>
> Write raw output to `$FORGE_DIR/raw/A.md`. Research only, no writes to source files.

---

**Agent B — Design Philosophy & Intra-Module Structure**

OWNS: Within-module design quality — paradigm fit, patterns, code smells as structural signals.
DELEGATES: cross-module coupling root cause → A; boundary/interface design → C.

> Analyze `<TARGET>` as an adversarial design critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN intra-module structure. Cross-module coupling goes to A. Interface/contract design goes to C. A code smell that signals a coupling issue between modules (e.g., Feature Envy) should be named here as the surface signal, but root cause attribution in the report goes to A.
>
> Auto-detect paradigm per module before applying principles.
>
> For OOP modules: (1) SOLID violations — name the specific principle and the specific class; (2) inheritance used for code reuse instead of composition; (3) design pattern cargo-cult — pattern applied without solving the problem it was designed to solve; (4) DDD model anemia — domain objects as data bags with logic scattered in service layers.
>
> For FP modules: (5) impure functions embedded in pure pipelines without isolation; (6) mutable state smuggled into otherwise-pure code; (7) over-abstraction — point-free style beyond readability; (8) missing appropriate monadic patterns where error/async is handled ad-hoc.
>
> For all paradigms: (9) Fowler code smells as structural signals — Shotgun Surgery, Divergent Change, Speculative Generality, Primitive Obsession, Data Clumps, Parallel Hierarchies; (10) Brooks' conceptual integrity — is there one coherent design philosophy throughout, or does each file look like a different author's style guide?
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/B.md`. Research only, no writes to source files.

---

**Agent C — Interface & Contract Design**

OWNS: What modules expose at their boundaries and how they communicate failure.
DELEGATES: inter-module coupling direction → A; intra-module structure → B.

> Analyze `<TARGET>` as an adversarial interface critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN boundary and contract design. If coupling is the primary issue (which module imports which), that belongs to A. If internal structure is the primary issue, that belongs to B.
>
> Interrogate: (1) Bloch's API principles — is the public surface minimal? Are common cases simple and uncommon cases possible? Do methods encourage correct use and make misuse difficult? (2) Postel's Law application — is input acceptance appropriately strict or permissive for the context? (3) Connascence at boundaries — are modules coupled by meaning (shared magic values, implicit conventions), by position (argument order must match), or by algorithm (caller must know implementation details to use the interface)? (4) Error handling philosophy at boundaries — do functions communicate failure clearly and consistently? Is the strategy (exceptions / error codes / null / Result/Option types) consistent across the codebase and appropriate for the language? (5) Interface evolution — are public interfaces stable? Is there a deprecation strategy for breaking changes? (6) The adapter contract — every source must satisfy one `Source` interface (`name`, `family`, `defaultTenure`, `fetch()`, `health()`), and adding a source is supposed to be CONFIG-ONLY in the common case. Ask whether that contract is expressed anywhere a new source can rely on, whether `config/sources.yaml` can express what a real source needs without a bespoke adapter, and whether a per-site override in `adapters/sites/` is an escape hatch or the de-facto default path. A contract that every source has to bypass is not a contract.
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/C.md`. Research only, no writes to source files.

---

**Agent D — Complexity & Cognitive Budget**

OWNS: Cognitive cost of the code — how hard it is to hold in working memory.
DELEGATES: complexity caused by inter-module coupling → A (flag the cost here, root in A); complexity caused by internal structure → B (flag the cost here, root in B). D owns the cognitive *measure* independently.

> Analyze `<TARGET>` as an adversarial complexity critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN cognitive complexity. You measure and quantify it. Root causes of that complexity belong to A or B — you may note the root but the canonical finding stays here.
>
> Interrogate: (1) Ousterhout's deep vs. shallow modules — does each module hide significant complexity behind a simple interface, or does it expose complexity without absorbing any? (2) Essential vs. accidental complexity — which complexity is inherent to the problem domain vs. introduced by design choices that could be reversed? (3) YAGNI violations — generality added for hypothetical requirements that never materialized; hooks, extension points, and abstractions with zero or one consumer; (4) Naming as cognitive proxy — do names reveal intent clearly enough that the implementation rarely needs to be read? Identify names that force readers into the implementation; (5) Working-memory pressure — code units too large to hold in context at once (> ~50 lines for a function without a compelling reason), mixed abstraction levels in one function, unexpected side effects in reads/getters; (6) Cognitive load patterns — deeply nested conditionals (> 3 levels), early returns suppressed, boolean parameters that flip function behavior.
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/D.md`. Research only, no writes to source files.

---

**Agent E — Concurrency & State**

OWNS: All concurrency and shared-state design findings. Orthogonal to A–D.

> Analyze `<TARGET>` as an adversarial concurrency critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN concurrency and state design. These concerns are orthogonal to structural layering (A–D).
>
> Auto-detect paradigm. In FP-dominant code, check for impure mutations. In OOP-dominant code, check for synchronization and state machine design.
>
> Interrogate: (1) Shared mutable state — any state accessible by multiple concurrent actors without explicit synchronization? (2) Race susceptibility — operations assuming sequential execution in a concurrent context: TOCTOU (time-of-check-time-of-use), check-then-act, read-modify-write; (3) Immutability strategy — is mutability the default or the exception? Are mutable data structures passed across thread/process boundaries? (4) State machine design — for stateful components, is state modeled explicitly (enum, sealed class, state machine library) or implicitly (boolean flags, nullable fields signaling state)? (5) Async/reactive design — are async boundaries explicit? Are there blocking calls inside async/reactive contexts? Is backpressure handled or ignored?
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/E.md`. Research only, no writes to source files.

---

**Agent F — Data & Persistence Design**

OWNS: Data model and persistence concerns. Orthogonal to A–E.

> Analyze `<TARGET>` as an adversarial data design critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN data model and persistence design. These concerns are orthogonal to structural layering.
>
> Interrogate: (1) Data model fit — is the data model (relational, document, graph, key-value, time-series) appropriate for the access patterns? Are there signs of impedance mismatch between the model and its queries? (2) ORM anti-patterns — N+1 queries, lazy-loading inside loops, raw SQL leaking through repository boundaries into domain code; (3) Repository pattern quality — do repositories abstract the storage engine so domain code has no storage-specific knowledge? (4) Schema evolvability — is the schema designed for forward/backward compatibility? Are migrations reversible? Is there a rollback plan for each migration? (5) Read/write model separation — are read-heavy and write-heavy paths sharing a model when separating them would reduce coupling? Is event sourcing applied where snapshotting would serve equally well at lower complexity cost? (6) Tenure and dedup in the schema — is `Tenure` a closed enum with an explicit `UNKNOWN`, stored alongside the confidence and the signals that produced it, so a past classification can be re-audited after the classifier changes? Is the logical (cross-portal) listing a first-class row that source rows hang off, or is clustering recomputed on every run? Is price history append-only? Is the excluded-tenure set enforced structurally (a query that cannot return it, a type that cannot represent it as a match) or left to every caller to remember — the latter is a structural finding, not a code smell. (7) Re-classification — when the classifier improves, can already-stored listings be re-run and re-notified, or is the first verdict permanent by accident?
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/F.md`. Research only, no writes to source files.

---

**Agent G — Resilience & Evolution**

OWNS: How well the system handles failure and absorbs structural change. Orthogonal to A–F.

> Analyze `<TARGET>` as an adversarial resilience critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN resilience and evolution design. These concerns are orthogonal to structural layering.
>
> Interrogate: (1) Extension points — can new behavior be added without modifying existing code (Open/Closed principle applied at the system level)? Are the right extension seams present, or does every new feature require shotgun changes? (2) Backwards compatibility — are breaking changes made without a deprecation path or migration guide? (3) Failure isolation — do component failures cascade, or are they bounded by bulkheads? Is circuit breaker or retry applied where appropriate? (4) Graceful degradation — does the system have a defined degraded-mode strategy, or is it all-or-nothing? (5) Feature flag readiness — can features be toggled without deployment? Is there a kill switch for new behavior? (6) Migration reversibility — are data and schema migrations designed to be rolled back, or are they one-way?
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/G.md`. Research only, no writes to source files.

---

**Agent H — Testability & Observability Design**

OWNS: Whether the structure enables testing and production insight. Orthogonal to A–G. Note: this agent critiques *structural choices* that make testing or observing hard — not test coverage gaps (that is `/inspect` Agent F).

> Analyze `<TARGET>` as an adversarial testability and observability critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN testability and observability design. You critique structural choices that make testing or observing the system hard, not test coverage gaps (that is /inspect Agent F).
>
> Interrogate testability: (1) Dependency injection — are dependencies injected (constructor/parameter injection), or are they hardcoded/global (making substitution in tests require monkey-patching or environment tricks)? (2) Test seam availability — can the system be put into a known state without running all of production infrastructure? Are there seams for substituting collaborators (time, randomness, I/O, external services)? (3) Determinism — are there hidden sources of non-determinism (wall-clock time, randomness, external API calls) reachable from pure business logic without isolation?
>
> Interrogate observability: (4) Log design — are logs structured (JSON/key-value)? Do they carry correlation/request IDs? Can a single request be traced end-to-end across components using only logs? (5) Error propagation — when a failure occurs deep in the stack, does the error surface with enough context (original cause, input values, component name) to diagnose without source access? (6) Instrumentation points — are the right metrics and tracing spans present for production diagnosis, or would a production incident require a code change to investigate?
>
> For each finding: Chesterton check first. All 4 required fields or drop.
>
> Write raw output to `$FORGE_DIR/raw/H.md`. Research only, no writes to source files.

---

**Agent I — UX/Presentation Layer**

OWNS: Design-time presentation quality — view-state coverage, component composition, design-time accessibility, UX pattern consistency.
DELEGATES: intra-module code smells → B; interface/contract design → C; runtime WCAG contrast/ARIA/keyboard-order (requires a running browser) → qa-sweep (never raise these as forge findings).
CONDITIONAL: rent-watch is a **CLI plus push notifications, with no web UI by design** (`spec/PROJECT_BRIEF.md` §12 Non-goals), so this agent is normally skipped — write `$FORGE_DIR/raw/I.md` with `NO FRONTEND DETECTED — Agent I skipped.` and return immediately. Two surfaces DO count as presentation if they exist, and only then does this agent run: the **notification payload** (score, `reasons[]`, ordering, truncation on a phone lock screen, priority) and the optional **read-only HTML digest** the brief allows later. Neither is a UI framework; both have real layout, ordering and legibility behaviour. Do NOT report the absence of a web UI as a finding — it is a ruled non-goal.

> Analyze `<TARGET>` as an adversarial UX/presentation critic. PROJECT_TYPE: PROJECT_TYPE. ADR_CONTEXT: ADR_CONTEXT. CURRENT_DATE.
>
> You OWN the presentation layer. Intra-module code smells belong to B. Interface/contract design belongs to C. Runtime measurements (contrast ratios, ARIA tree, keyboard tab-order) require a running browser and belong to qa-sweep — do NOT raise these as forge findings.
>
> First: check whether a presentation surface exists — a notification formatter (`src/core/notify/**`) or an HTML digest template. `.html`/`.css` files elsewhere in the tree do not count. If none found, write `NO FRONTEND DETECTED — Agent I skipped.` to your output file and stop. Never report the absence of a web UI as a finding: it is a ruled non-goal.
>
> Interrogate: (1) View-state coverage — for every interactive component or data-fetching unit, does the source define distinct loading, error, and empty states? Missing any of the three is a design gap. (2) Component composition — is data passed more than 2 levels deep via props without an explicit context/store mechanism? Flag prop-drilling chains of 3+ levels as a structural finding. (3) Design-time accessibility — are interactive non-native elements (div, span, li used as buttons/links) missing explicit `role` and keyboard handler attributes in source? Are form inputs missing associated labels in markup? Is `:focus` or `:focus-visible` defined in CSS for custom interactive elements? (4) UX pattern consistency — are error messages surfaced with the same visual pattern across all components? Do destructive actions (delete, reset, overwrite) require explicit confirmation in the component logic?
>
> For each finding: Chesterton check first (git log, ADRs, inline docs). Rationale found → skip. Not found → report with all 4 required fields (named principle + concrete alternative + cost-to-change + cost-to-keep). All 4 fields required or drop the finding.
>
> Write raw output to `$FORGE_DIR/raw/I.md`. Research only, no writes to source files.

---

## Step 3: Synthesis Agent S

After all analysis agents complete (or after the single focused agent if `--focus` was used), spawn exactly one synthesis agent. This agent sees all raw outputs simultaneously and is responsible for enforcing the ownership partition.

> You are the synthesis agent for `/forge`. Read all raw analysis files from `$FORGE_DIR/raw/` (A.md through I.md, or the subset that was run).
>
> **First: inventory raw files**. Run `ls "$FORGE_DIR/raw/"` and note which agent letters are present. For any expected agent whose file is missing (e.g., raw/C.md absent when agents A–E were supposed to run), add to the Summary section: `**Missing agents**: [C] — raw file not written (agent may have timed out or been rate-limited). Findings from this agent are absent from this report.` Do not fabricate findings for missing agents.
>
> **Primary responsibility**: The analysis agents ran in parallel and could not see each other's output. The same underlying structural fact may appear in multiple raw files under different principle names. You must deduplicate before producing the final report.
>
> **Deduplication rule**: For any underlying structural fact appearing in 2+ raw files, assign it to the single agent whose ownership rule most precisely matches the root nature of the finding. Collapse all other mentions into an "Also flagged by: [agents]" annotation on the canonical entry. When in doubt, use this ownership quick-reference:
> - Cross-module coupling / dependency direction → A
> - Intra-module design / code smells → B
> - Boundary/interface/contract design → C
> - Cognitive complexity / abstraction cost → D
> - Concurrency / shared state → E
> - Data model / persistence → F
> - Resilience / evolution / extension → G
> - Testability / observability design → H
> - UX/presentation layer (view-states, component composition, design-time a11y) → I
>
> **Drop incomplete findings**: Any finding in a raw file that lacks all 4 required fields (named principle + concrete alternative + cost-to-change + cost-to-keep) must be dropped from the final report. Do not include partial findings.
>
> **Report format**:
>
> ```markdown
> # /forge Report — <TARGET> — <DATE>
>
> ## Summary
> - **Agents run**: [letters]
> - **Total findings**: N (Unjustified: X | Questionable: Y)
> - **Highest-signal lens**: [agent letter — most Unjustified/Questionable findings]
> - **Deduplication**: M findings collapsed across agents
>
> ## Findings
>
> (Sorted: Unjustified first, then Questionable. Justified findings are omitted — they are silent successes.)
>
> ### [A] — [Short title of structural decision being challenged]
> **Verdict**: Unjustified | Questionable
> **Chesterton check**: [what was searched, what was or wasn't found in git/ADRs/docs]
> **Paradigm context**: [OOP / FP / procedural / mixed OOP+FP — which applies here and why]
> **Principle violated**: [named authority + specific principle — e.g., "Parnas (1972) — information hiding: the module exposes its sorting algorithm as part of its public interface"]
> **Location**: [file(s) + approximate line(s)]
> **Concrete alternative**: [specific better structure + 2-sentence implementation sketch]
> **Cost to change**: Low / Medium / High
> **Cost to keep**: [one sentence on coupling tax, cognitive tax, or evolution risk]
> **Also flagged by**: [other agents if deduplication merged entries — omit if not merged]
>
> ---
>
> ## Action List
>
> (One line per Unjustified or Questionable finding)
> - [ ] [A] Rename module X to expose only its interface, not its algorithm
> - [ ] [C] Replace positional argument coupling in Y with named config struct
> ```
>
> Write the final report to `$REPORT_PATH`. Announce the path when done. Research only, no writes to source files.

## Step 4: Announce

After the synthesis agent completes:

```
Forge complete → $REPORT_PATH

Findings: N total (X Unjustified, Y Questionable, Z Justified/dropped)
Deduplication: M cross-agent findings collapsed
Top findings:
  1. [agent] [title] — Unjustified
  2. [agent] [title] — Questionable
  3. [agent] [title] — Questionable

This report is read-only. Nothing was modified.
```
