# Thinking Frameworks & Reasoning Reference

> Standalone reference — NOT auto-loaded at session start. Maintained as a separate file so the framework library can evolve independently of the operational config. Use Read or `@THINKING.md` when you want frameworks loaded explicitly.
>
> **Maintenance rule**: edit the REPO copy — `scripts/claude-bootstrap/THINKING.md`. Never edit `~/.claude/THINKING.md`: `install.sh` copies one-directionally and **unconditionally** (the repo is the truth, ruled 2026-08-06), so a hand-edit there is silently destroyed at the next SessionStart. After adding a framework, `wc -l scripts/claude-bootstrap/THINKING.md`; if over 120 lines, consolidate duplicates. (Upstream also prescribed `/audit --section=B` here to catch drift; `/audit` is **not installed** in this container — check consistency against `CLAUDE-global.md` by hand instead.)

## Software Craftsmanship & Thinking Frameworks

Apply named mental models actively. When using a framework, mention it **briefly inline** — e.g., *"Applying Chesterton's Fence: before removing this, let's understand why it was added..."* — not as section headers or verbose explanations.

### Core Working Set (always active)

These 12 frameworks form the default mental toolkit — reach for them first in every phase:

| Framework | When it fires |
|-----------|---------------|
| **Occam's Razor** | First explanation is the simplest plausible one |
| **Chesterton's Fence** | Understand existing code/config before removing it |
| **Five Whys** | Drill past symptoms to root cause |
| **Popper's Falsifiability** | Every hypothesis is a testable statement — then test it |
| **Inversion (Pre-Mortem)** | Ask "what would guarantee failure?" and prevent it |
| **KISS** | Complexity must justify itself; simplest solution first |
| **Fail Fast** | Detect errors at the earliest possible point |
| **Broken Windows** | Fix small problems immediately; one ignored warning invites decay |
| **Hyrum's Law** | Every observable behavior is a contract for someone |
| **Gall's Law** | Start simple; complex systems that work evolved from simple ones |
| **One-Way vs Two-Way Doors** | Irreversible decisions deserve analysis; reversible ones deserve speed |
| **Theory of Constraints** | Find the single tightest bottleneck; optimizing anything else is waste |

### Full Reference — Thinking Razors (analysis, evaluation, debugging)
- **Occam's Razor**: Favor the simplest explanation first — a typo or missing config before suspecting race conditions or framework bugs
- **Hanlon's Razor**: Assume confusing code was written under time pressure, not malice — respond with teaching
- **Hitchens's Razor**: Reject unsubstantiated "this is faster" or "users need this" — demand benchmarks or evidence
- **Sagan's Standard**: Extraordinary claims require extraordinary proof — load tests, prototypes, migration plans
- **Popper's Falsifiability**: Write every hypothesis as a testable statement ("if X is the cause, disabling Y stops the crash") — then test it
- **Duck Test**: Trust observable behavior over documentation — if it quacks like a bug, it's a bug
- **Alder's Razor**: If a style debate cannot be settled by experiment, settle it with a linter or convention and move on

### Full Reference — Engineering Laws (architecture & design)
- **Gall's Law**: Complex systems that work evolved from simple ones that worked — start simple, iterate
- **Hyrum's Law**: With enough users, every observable behavior becomes a contract — treat output formats and APIs as commitments
- **Kernighan's Law**: Debugging is 2x harder than writing — write code simpler than you think necessary
- **Tesler's Law**: Complexity is conserved, only moved — decide deliberately where it should live
- **KISS**: Write the simplest implementation that works; complexity must justify itself
- **Postel's Law**: Accept liberal input; emit strict, consistent output
- **Fail Fast**: Detect errors at the earliest possible point
- **Principle of Least Surprise (POLA)**: Behavior must be predictable — consistent APIs, output formats, conventions
- **Broken Windows**: One ignored warning invites decay — fix small problems immediately to prevent rot

### Full Reference — Debugging Mental Models (systematic troubleshooting)
- **Binary Search Debugging**: Halve the problem space systematically
- **Five Whys**: Drill past symptoms to root cause
- **Sherlock Holmes Principle**: Eliminate the impossible; whatever remains, however improbable, is the truth
- **Rubber Duck Debugging**: Articulate the problem in full sentences — the explanation often reveals the answer

### Full Reference — Decision Frameworks (trade-offs)
- **One-Way vs Two-Way Doors**: Irreversible decisions deserve deep analysis; reversible ones deserve speed
- **Pareto Principle (80/20)**: Focus effort on the 20% that produces 80% of value
- **Lindy Effect**: The longer a technology survived, the longer it will — prefer battle-tested over trendy
- **Premature Optimization**: Optimize only the measured critical path (Knuth)
- **Eisenhower Matrix**: Urgent + important = do now; important only = schedule; urgent only = delegate; neither = drop
- **Sunk Cost Fallacy**: Don't continue a failing approach because of time already invested — pivot when evidence demands it
- **Second-Order Thinking**: Ask "and then what?" — trace consequences beyond the immediate change
- **Goodhart's Law**: When a metric becomes a target it ceases to be useful — design checks that measure real outcomes, not gameable proxies

### Full Reference — Creative & Strategic Thinking (brainstorm phases)
- **First Principles**: Break problems to fundamental truths and reason up — ignore "how others did it"
- **Inversion (Pre-Mortem)**: Ask "what would guarantee failure?" and prevent those things
- **Chesterton's Fence**: Before removing code/config, understand why it was put there
- **Map Is Not The Territory**: Diagrams, types, and tests are useful simplifications, not reality — verify against running behavior
- **Theory of Constraints**: Find the single tightest bottleneck first — optimizing anything else produces zero improvement

### Phase-to-Framework Mapping
- **Phase 0 (Context Loading)**: Chesterton's Fence (understand current state before changing)
- **Phase 1 (Brainstorm)**: First Principles, Inversion, Chesterton's Fence
- **Phase 2 (Understand)**: Five Whys, Binary Search, Sherlock Holmes, Rubber Duck
- **Phase 3 (Refined Brainstorm)**: One-Way/Two-Way Doors, Pareto, Lindy, Eisenhower
- **Phase 4 (Plan)**: Theory of Constraints, Gall's Law, Tesler's Law
- **Phase 5 (Implement)**: KISS, Kernighan's Law, Postel's Law, Fail Fast, POLA
- **Phase 6 (Second Sweep)**: Hyrum's Law, Duck Test, Broken Windows
- **Phase 7 (Artifacts)**: Chesterton's Fence (understand existing docs/memory before changing)
- **All phases**: Occam's Razor, Hanlon's Razor, Popper's Falsifiability
