# Claude bundle — cross-repo audit and unification plan

> Audit of the Claude global/project bundle across all five `tmessaoudi-official` repos, to find what
> each copy is missing. Written 2026-08-06 from **fresh full clones** of all five at their then-HEADs.
> **This file is the portable artefact**: the same tables apply when unifying any of the other repos,
> with the "rent-watch" column swapped for the target.
>
> Two sibling repos wrote their own version of this audit on the same day
> (`phorj/docs/plans/claude-bundle-cross-repo-audit.plan.md`, and the body of `twes-in`'s commit
> `a1786f3`). **Both recorded the chronology incorrectly** — see § Chronology. Prefer this file's
> measured version, and correct theirs when unifying them.

## Decisions Log

- [2026-08-06] AGREED: audit all five repos and port the divergences into rent-watch (developer request).
- [2026-08-06] AGREED (P2, applied): make `install.sh`'s credential-exfiltration warning explicit —
  name the exact block, say the repo is public, say why it must not come back even commented out.
  Ported from phorj's 2026-08-06 wording.
- [2026-08-06] VERIFIED, no action: rent-watch already carries all three of phorj's P0/P1 fixes (no
  credential block, `LATEST_IS_MANUAL` on both write paths, `log_obs` → `var/claude/logs/`), inherited
  from twes-in rather than re-derived.
- [2026-08-06] VERIFIED, no action: `stack`'s committed bundle tarball is properly scrubbed — every
  credential value is a placeholder; the only non-placeholder values are `*_SSL_VERIFY`. Committing it
  to a public repo is safe.
- [2026-08-06] OPEN: whether to adopt stack's `claude-setup/<bundle>.tar.gz` pattern here.
- [2026-08-06] AGREED (P1, applied): **the repo is always the truth** — `install.sh` copies the three
  framework docs UNCONDITIONALLY, replacing `cp -u`. Developer ruling: *"it would be better to always
  copy what is in the repo to the global folder! the repo is always the truth!"* A file predating the
  hook is snapshotted once to `<name>.pre-bootstrap.bak` and never re-written, so unconditional copying
  cannot destroy a global framework irrecoverably. New suite `test-install.sh`, 17 assertions, both
  guards sabotage-verified. **This is now a port-OUT item for all four siblings** — they all still ship
  `cp -u` and the same false header claim.

## Chronology — MEASURED, and both siblings have it wrong

Two different questions get conflated, which is how both sibling audits went wrong:

| repo | `.claude/settings.json` first appears | **`scripts/claude-bootstrap/` first appears** (= the container bundle) | bundle order |
|---|---|---|---|
| **stack** | 2026-04-17 | 2026-08-06 10:03 | **4th** |
| **pdfturbo** | 2026-06-11 | 2026-07-28 | **2nd** |
| **phorj** | 2026-06-16 | **2026-07-23** | **1st — the origin of the container port** |
| **twes-in** | 2026-08-02 | 2026-08-02 | **3rd** |
| **rent-watch** | 2026-08-06 | 2026-08-06 12:00 | **5th — newest, and the current reference** |

*Measured with `git log --reverse --diff-filter=A -- <path>` on fresh full clones.*

- **phorj's plan says "stack 2026-03-31 … oldest first" and orders stack 1st.** That uses the
  `.claude/` date, not the bundle date. stack's `.claude/` is indeed the oldest (April) but its
  *container bundle* is the second-newest (August 6th, ~2h before rent-watch). Ordering the audit by
  the wrong column is what let it conclude "phorj is a week stale" while treating stack as the ancestor.
- **twes-in's commit body has the ORDER right** (phorj → pdfturbo → twes-in → stack → rent-watch) but
  every date is off by roughly a day (`phorj 07-22` vs 07-23, `pdfturbo 07-27` vs 07-28, `stack 08-05`
  vs 08-06) — most likely author-date vs commit-date, or a timezone.

**The lineage that actually matters:** phorj invented the container port; pdfturbo hardened it (removed
the credential copy-out, added `apply-pending-settings.sh`); twes-in added the `LATEST_IS_MANUAL` guard,
the handoff test suite and the repo-local `log_obs`; stack invented `/cross-check --drift`; rent-watch
combined twes-in's bootstrap with stack's `cross-check`, added write-time hooks, made the install
unconditional, and ported `/repair` — the one skill every earlier port had wrongly rejected.

Every repo shares the SAME wiring — `SessionStart → install.sh`, `PreCompact → precompact-handoff.sh`
(twice, manual + auto matchers). **The mechanism is already unified everywhere.** What diverges is
content.

## Feature matrix — measured across all five

| capability | stack | pdfturbo | phorj | twes-in | rent-watch |
|---|---|---|---|---|---|
| `scripts/claude-bootstrap/` wiring | ✅ | ✅ | ✅ | ✅ | ✅ |
| `test-precompact-handoff.sh` | ❌ | ❌ | ✅ 34 | ✅ 35 | ✅ 35 |
| `<!-- manual -->` handoff guard | ❌ | ❌ | ✅ | ✅ | ✅ |
| `log_obs` → in-repo `var/claude/logs/` | ❌ | ❌ | ✅ | ✅ | ✅ |
| credential copy-out block absent | ✅ | ✅ | ✅ (deleted 08-06) | ✅ | ✅ |
| THINKING.md "edit the REPO copy" rule | ❌ | ❌ | ❌ | ✅ | ✅ |
| `## Memory System Toggles — NOT APPLICABLE` | ❌ | ❌ | ❌ | ✅ | ✅ |
| 3-lens reviewer panel | ❌ 2 (1 is a lead-dev) | ✅ 3 | ❌ **1** | ✅ 3 | ✅ 3 |
| `permissions.deny` EMPTY (full autonomy) | ✅ | ✅ | ✅ | ✅ | ✅ (was 4 `.env` entries; **removed** 2026-08-06) |
| `/repair` + `drift-scan.sh` | ❌ | ❌ | ❌ | ❌ | ✅ **only one** |
| project hooks use `log_obs` (Rule 13) | ? | ? | n/a | n/a | ✅ |
| write-time `PostToolUse` hooks | ✅ 5 | ✅ 2 | ❌ 0 | ❌ 0 | ✅ 3 |
| `/cross-check` | ✅ | ❌ | ✅ | ✅ (08-06) | ✅ |
| `/converge` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `/retrospective` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `/forge` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `/qa-sweep` | ❌ | ✅ | ❌ | ❌ | ❌ (rejected: no UI) |
| bundle tarball committed | ✅ | ❌ | ❌ | ❌ | ❌ |
| unconditional install (repo is truth) | ❌ | ❌ | ❌ | ❌ | ✅ **only one** |
| `test-install.sh` | ❌ | ❌ | ❌ | ❌ | ✅ **only one** |

Core skill set is 13 and identical across pdfturbo/phorj/twes-in/rent-watch. stack carries 10 of the 13
plus 10 stack-specific domain skills; rent-watch adds `/add-source`.

## What rent-watch was missing — the whole list

**Two items, both applied.** P1: `install.sh` used `cp -u` — fixed, see the Decisions Log entry and
§ "port OUT" item 0. P2, documentation-of-security: `install.sh`'s header said only *"the upstream
port this was adapted from did exactly that; the block was removed here on purpose"*. It now names the
exact two-line block, states that this repo is public, says it must not return even commented out, and
cites phorj's deletion. Ported from phorj's 2026-08-06 wording, which is better than what rent-watch
inherited.

**Nothing else.** Every other divergence runs the other way — see below.

## What to port OUT of rent-watch, per repo

This is the actionable half when the developer runs this exercise on the siblings.

### → ALL FOUR siblings (P1 — the newest ruling, and none of them has it)

0. **`install.sh` still uses `cp -u`, and its header still carries the false claim.** Ruled 2026-08-06:
   the repo is always the truth, so the copy must be unconditional. `cp -u` was nondeterministic — after
   a fresh clone the repo file is newer so it clobbered anyway (the header says it does not), and after a
   hand-edit of the target it silently did nothing so the repo stopped being the truth. Port
   rent-watch's `install_doc()` helper (unconditional `cp -f` + one-time `.pre-bootstrap.bak` snapshot)
   and its `test-install.sh`. The snapshot's *never-rewrite* guard matters specifically because all five
   repos share this hook: opening a sibling installs its copy over yours, so on the next session the
   target differs from your source again and a naive snapshot would overwrite the original.

9. **`/repair` + `.claude/skills/rw-repair/drift-scan.sh`.** Every earlier port rejected `/repair` as
   "operates on a persistent `~/.claude/`". That was inherited, not checked: its five drift categories
   are about the project's own docs versus the project's own filesystem. It is ported here **on
   evidence** — one session found five drift defects by hand that the scan catches mechanically, the
   worst being a shipped framework that told the next session it lacked a skill it had. Because
   `install.sh` now copies unconditionally, every repo has that failure mode. The scanner is
   citation-aware (it must not fire on its own changelog) and sabotage-verified four ways.
10. **Wire `log_obs` into project hooks.** rent-watch's three hooks had none, violating Rule 13 of the
   framework they ship. stack (5 hooks) and pdfturbo (2) should be checked for the same gap — `phorj`
   and `twes-in` have no project hooks, so it does not apply there.

### → `stack` and `pdfturbo` (both P1, both genuinely broken today)

1. **`log_obs` writes to `~/.claude/logs/hooks-errors.log`** — wiped when the container is reclaimed,
   so every line a hook logs in a real session is unreadable. Port
   `hooks/log-helpers.sh`: default to `${CLAUDE_PROJECT_DIR:-$PWD}/var/claude/logs/`, keep `$OBS_LOG`
   for tests.
2. **`/handoff` documents a `<!-- manual -->` marker their hook does not implement** — following the
   documented ritual silently loses the note at the next compaction. Port `LATEST_IS_MANUAL`, honoured
   on **both** write paths (the default and the opt-in LLM one).
3. **No `test-precompact-handoff.sh` at all.** Port it; it is the only executable guard the bundle has.

### → `phorj` (P1)

4. **The mandated 3-lens panel is 1 agent.** phorj's own plan flags this as its largest gap and
   correctly says the two missing lenses are *authoring* tasks, not copies. rent-watch's three agents
   are the right shape to copy structurally: one generic `completeness-reviewer` + two domain lenses,
   each ending in `PANEL VERDICT: CLEAN|FINDINGS` and requiring two consecutive clean rounds.
5. **THINKING.md maintenance rule** — phorj's still says *"run `wc -l ~/.claude/THINKING.md`"*. That is
   the trap: `cp -u` makes a hand-edit of the installed copy permanently newer than the repo copy, so it
   diverges silently and unrecoverably. Port rent-watch's wording (edit the REPO copy).
6. **`## Memory System Toggles`** presented as live machinery. Port rent-watch's
   `— NOT APPLICABLE HERE` section, which states the pipeline is absent and what replaces it.

### → `phorj` and `twes-in` (P2)

7. **No write-time `PostToolUse` hooks.** rent-watch has 3, stack 5, pdfturbo 2. A lint hook that exits
   2 feeds its findings back into the turn, which is strictly better than a git hook that fires after
   the fact.

### → all four (P2)

8. **~~`permissions.deny` for `.env`~~ — WITHDRAWN, do not port.** rent-watch briefly had four such
   entries and they were **removed** on 2026-08-06 by developer ruling: *"there should be no permissions
   denies in this env… if you are denied to do something I can't run it myself, so there must be full
   autonomy."* I had argued the sibling ruling was about *commands* and that a path deny had no
   dead-end failure mode. That was wrong on the actual constraint — a denied `Read` still blocks a
   legitimate audit with no terminal to unblock it. **All four siblings are already correct here**;
   rent-watch was the outlier. `drift-scan.sh` now asserts `deny` is empty so it cannot creep back.

## Open — needs a ruling

1. **Adopt stack's `claude-setup/<bundle>.tar.gz`?** stack commits the 517 KB scrubbed bundle so it can
   be re-imported on any machine. Verified safe (all credential values are placeholders). Pro: the
   bundle travels, `/install` works from a clone. Con: a 517 KB binary in git, and it embeds internal
   MCP service topology (scrubbed to `<mcp-client-N>` names).
2. **`SubagentStop` reminder hook** (stack only). Its current form fires for one named stack agent, so
   it is not portable as-is. The generic pattern — emit `{"systemMessage": …}` when a subagent finishes
   — has low value here, since rent-watch's reviewers already end with an explicit `PANEL VERDICT` line.
   Recommend skipping unless the panel starts being run and then ignored.
