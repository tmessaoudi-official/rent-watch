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
- [2026-08-06] OPEN: whether `install.sh` should stop relying on `cp -u` mtime (clobbers a real
  workstation's `~/.claude/CLAUDE.md` after a fresh clone — reproduced, see § Open 1).

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
combined twes-in's bootstrap with stack's `cross-check` and added the `.env` deny plus write-time hooks.

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
| `permissions.deny` for `.env` | ❌ | ❌ | ❌ | ❌ | ✅ **only one** |
| write-time `PostToolUse` hooks | ✅ 5 | ✅ 2 | ❌ 0 | ❌ 0 | ✅ 3 |
| `/cross-check` | ✅ | ❌ | ✅ | ✅ (08-06) | ✅ |
| `/converge` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `/retrospective` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `/forge` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `/qa-sweep` | ❌ | ✅ | ❌ | ❌ | ❌ (rejected: no UI) |
| bundle tarball committed | ✅ | ❌ | ❌ | ❌ | ❌ |

Core skill set is 13 and identical across pdfturbo/phorj/twes-in/rent-watch. stack carries 10 of the 13
plus 10 stack-specific domain skills; rent-watch adds `/add-source`.

## What rent-watch was missing — the whole list

**One item, applied.** P2, documentation-of-security: `install.sh`'s header said only *"the upstream
port this was adapted from did exactly that; the block was removed here on purpose"*. It now names the
exact two-line block, states that this repo is public, says it must not return even commented out, and
cites phorj's deletion. Ported from phorj's 2026-08-06 wording, which is better than what rent-watch
inherited.

**Nothing else.** Every other divergence runs the other way — see below.

## What to port OUT of rent-watch, per repo

This is the actionable half when the developer runs this exercise on the siblings.

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

8. **`permissions.deny` for `.env`.** rent-watch is the only repo with it. The sibling ruling that
   `deny` is an unrecoverable dead end in a cloud session is about *commands*; a `Read`/`Edit` path deny
   on `.env` has no dead-end failure mode and is a real guard.

## Open — needs a ruling

1. **`install.sh` relies on `cp -u` mtime, which clobbers a real workstation.** `cp -u` copies when the
   source is newer, and a fresh `git clone` stamps every file with the clone time — so cloning any of
   these repos onto the developer's own machine and opening it in Claude Code **overwrites their own
   `~/.claude/CLAUDE.md`** with that repo's container-adapted copy. Reproduced:

   ```
   home/CLAUDE.md  (mtime 2026-07-01): "MY OWN hand-maintained global framework"
   repo/CLAUDE-global.md (mtime now):  "rent-watch container-adapted framework"
   $ cp -u repo/CLAUDE-global.md home/CLAUDE.md && cat home/CLAUDE.md
   rent-watch container-adapted framework      ← clobbered
   ```

   The header comment claims the opposite (*"a hand-edited newer `~/.claude` file on a real workstation
   is never clobbered"*), which is true only if the file is newer than the clone. **All five repos share
   this.** Proposed fix: gate on the ephemeral container rather than on mtime — install when the target
   is absent, or when a container marker is present; otherwise print a one-line notice and skip.
2. **Adopt stack's `claude-setup/<bundle>.tar.gz`?** stack commits the 517 KB scrubbed bundle so it can
   be re-imported on any machine. Verified safe (all credential values are placeholders). Pro: the
   bundle travels, `/install` works from a clone. Con: a 517 KB binary in git, and it embeds internal
   MCP service topology (scrubbed to `<mcp-client-N>` names).
3. **`SubagentStop` reminder hook** (stack only). Its current form fires for one named stack agent, so
   it is not portable as-is. The generic pattern — emit `{"systemMessage": …}` when a subagent finishes
   — has low value here, since rent-watch's reviewers already end with an explicit `PANEL VERDICT` line.
   Recommend skipping unless the panel starts being run and then ignored.
