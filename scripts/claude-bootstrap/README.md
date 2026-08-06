# `scripts/claude-bootstrap/` — Claude Code container bootstrap

Everything here exists because **a Claude Code cloud session gets a fresh `~/.claude/` every time** and
never reads the developer's own. Anything the reasoning framework needs at `~/.claude/` has to travel
*in the repo* and be reinstalled at session start.

Adapted 2026-08-06 from the developer's own already-container-adapted ports in
[`twes-in`](https://github.com/tmessaoudi-official/twes-in),
[`pdfturbo`](https://github.com/tmessaoudi-official/pdfturbo),
[`stack`](https://github.com/tmessaoudi-official/stack) and
[`phorj`](https://github.com/tmessaoudi-official/phorj), plus the machine bundle
`claude-setup-global-20260722` itself. **twes-in was the primary source** — it is the newest port, it is
an application repo rather than a language or a tool, and it had already removed the credential-leak
vector present in the earliest port (see § "Why `install.sh` is deliberately one-directional").
`/cross-check` came from `stack`, which is where its `--drift` mode was invented.

**Licence note.** The scripts in this directory carried `SPDX-License-Identifier: AGPL-3.0-or-later`
in twes-in, which is AGPL-3.0-or-later plus a commercial licence. rent-watch declares **no licence at
all** yet, so those headers were **stripped** rather than propagated — silently importing AGPL into an
unlicensed private repo would be a licensing decision made by accident. All four repos share one
copyright holder, so relicensing is the developer's to make; it is recorded as an open question in
`docs/OPEN-QUESTIONS.md`. If rent-watch adopts AGPL, restore the headers.

## What's here

| File | Role |
|---|---|
| `install.sh` | **SessionStart hook.** Copies the three docs below into `~/.claude/` **unconditionally** — the repo is the truth — and creates `var/claude/`. Nothing else. |
| `CLAUDE-global.md` | The global reasoning framework → installed as `~/.claude/CLAUDE.md`. The 8-phase workflow, the four-dimension Completion Gate, the 18 Core Operating Rules, evidence grades. |
| `THINKING.md` | 33 named mental models → `~/.claude/THINKING.md`. Reference only, not auto-loaded — read it or `@THINKING.md` when you want the frameworks in context. |
| `BLAST-RADIUS.md` | State-dependent destructive-command reference → `~/.claude/BLAST-RADIUS.md`. |
| `hooks/precompact-handoff.sh` | **PreCompact hook.** Writes `var/claude/handoff/{latest,handoff-<stamp>}.md` before compaction. Deterministic — no LLM call. |
| `hooks/test-precompact-handoff.sh` | Test suite for the above (35). Run it after any edit to the hook. |
| `test-install.sh` | Test suite for `install.sh` (17) — pins the repo-is-truth contract and the one-time snapshot. |
| `hooks/log-helpers.sh` | `log_obs()`, shared by the hooks. |
| `apply-pending-settings.sh` | The `.claude/settings.json` hand-over relay. See below — currently **not needed here**, kept deliberately. |

The repo-native skills (`.claude/skills/`) and reviewer agents (`.claude/agents/`) need **no** install —
Claude Code reads them in place from the clone.

## What was rejected from the bundle, and why

The machine bundle held 48 skills, 39 hooks, 34 `bin/` scripts, 48 `mcp/` files and a
`settings.json.template`. Almost none of it travels. This list exists so none of it is re-imported by
mistake — each entry is a landmine that was tested upstream, not a matter of taste. The reasoning is
inherited from the pdfturbo and twes-in ports; only the rent-watch-specific notes are new.

- **All 39 hooks — zero registered.** Every one is an interrupt (the three ask-human gates, the
  question guard), a hard deadlock (`advisor-completion-guard` waits on a tool that does not exist
  here), terminal-only output nobody can see in a web session (statusline, banner, context-bar,
  git-status, subagent-status), or a write to a filesystem that evaporates (`edit-log`,
  `session-remember` × 20 files). The three hooks registered in `.claude/settings.json` are
  **rent-watch's own**, written here, not ported.
- **`ask-human-question-guard.sh` is ruled OUT specifically**, and the tool it guarded
  (`AskUserQuestion`) is forbidden outright because it times out in this container. A Stop hook that
  blocks any turn ending in a prose `?` is actively wrong under the plain-text question protocol.
- **`settings.json.template` — rejected wholesale, not cherry-picked.** Its
  `PreToolUse: rtk hook claude` would block *every* Bash call (`rtk` is absent, and a non-zero
  PreToolUse exit blocks before permission rules are evaluated); its `deny: Bash(git push *)` would
  revoke this repo's push authorisation; its `"model": "opus"` would override the session model; its 16
  `enabledPlugins` are user-scoped and do not transfer.
- **31 of the 48 skills.** They operate on a *persistent* `~/.claude/` (`audit`, `cleanup`, `bundle`,
  `install`, the seven `memory-*`, `lean*`, `model-audit`, `repair`, `sr-health`, `pre-session-health`,
  `skill-extractor`, `templatize`, `consolidate`, `bootstrap`, `adapt-project`, `command-audit`), or
  need absent tooling (`validate-infra`), or orchestrate skills that are themselves out
  (`mega-analysis`), or would **shadow a working built-in** (`loop`).
- **`qa-sweep` — rejected here, unlike in pdfturbo.** pdfturbo ported it because it had a real UI to
  crawl. rent-watch is a CLI plus push notifications and a web UI is a ruled non-goal
  (`spec/PROJECT_BRIEF.md` §12), so there is no UI state space to sweep. Do not port it "for later".
- **`bin/` — 34 files, ~190 KB.** Authoring/installing/pruning a persistent `~/.claude/`. Zero
  applicability to an ephemeral container.
- **`mcp/` — 48 files, ~420 KB.** A Python X11/Wayland GUI driver (no display here) plus
  Jira/Confluence/GitLab/Trivy topology — irrelevant to a rental-listings watcher, and internal service
  names and ports do not belong in this repo. The bundled `.env` files were deliberately not read.
- **`refs/MODELS.md`.** Lists `opus-4-8`/`sonnet-4-6` as current with no Opus 5; importing it would
  make model advice propose downgrades.

**What WAS taken:** the three docs `install.sh` copies, the PreCompact handoff hook and its test suite,
`apply-pending-settings.sh`, and 13 skills — `aggregate-findings`, `ask-human`, `converge`,
`cross-check`, `expanding-context`, `forge`, `gaps`, `handoff`, `inspect`, `pre-commit`,
`retrospective`, `sleuth`, `sweep`. Each had its provenance banner re-grounded from twes-in's invoicing
domain to rent-watch's: the tenure classifier, silent source breakage, dedup, legal posture and secrets
hygiene. The three reviewer agents in `.claude/agents/` were **written for this repo**, not ported —
twes-in's `domain-correctness` / `tenancy-security` lenses have no analogue here.

**Nothing from the bundle is now pending.** Every remaining item above is a deliberate rejection.

### Cross-repo state

All five `tmessaoudi-official` repos share this bootstrap, and their copies have diverged in content.
[`docs/plans/claude-bundle-cross-repo-audit.plan.md`](../../docs/plans/claude-bundle-cross-repo-audit.plan.md)
holds the measured chronology, a feature matrix across all five, and a per-repo list of what to port
**out of** this repo — rent-watch is currently the newest copy, so most divergences run outward. Read it
before porting anything in either direction; two sibling repos wrote their own audit on 2026-08-06 and
both recorded the integration chronology incorrectly.

## Why `install.sh` is deliberately one-directional

It copies three files **into** `~/.claude/` and never copies anything **out**. `~/.claude.json` holds
the OAuth account, `userID` and `machineID`, and the working tree is one `git add -A` away from git
history. The earlier of the two upstream ports *did* copy `/root/.claude` and `/root/.claude.json` into
the repo on every session start, with a commented-out `git push --force-with-lease` beneath it. That
block is not reproduced here. **Do not reintroduce it.** `/claude-bundle/` is gitignored as a
belt-and-braces guard, so that even an accidental copy cannot be committed.

## The repo is the truth — `install.sh` copies unconditionally

Ruled 2026-08-06. Every run copies all three docs over whatever is at `~/.claude/`, regardless of
timestamps. Idempotent (the same bytes land every time) and, more importantly, **deterministic**.

This replaced `cp -u`, and the header it replaced was **wrong on its own terms**. It claimed *"a
hand-edited newer `~/.claude/CLAUDE.md` on a real workstation is never clobbered"*. But `cp -u` copies
when the SOURCE is newer, and a fresh `git clone` stamps every file with the clone time — so on a real
workstation it clobbered anyway. Reproduced:

```
home/CLAUDE.md        (mtime 2026-07-01): "MY OWN hand-maintained global framework"
repo/CLAUDE-global.md (mtime now):        "rent-watch container-adapted framework"
$ cp -u repo/CLAUDE-global.md home/CLAUDE.md && cat home/CLAUDE.md
rent-watch container-adapted framework      ← clobbered
```

The converse was just as bad: hand-edit the target and `cp -u` silently did nothing forever, so the
repo quietly stopped being the truth. Both outcomes depended on mtimes nobody was tracking.

**The safety net.** A file that predates this hook is snapshotted once to
`<name>.pre-bootstrap.bak`, and that snapshot is never written again. It is not a second source of
truth — nothing ever reads it back — it exists so that unconditional copying cannot destroy a global
framework with no way back. The "never written again" half is load-bearing in the **multi-repo** case:
all five sibling repos ship this hook, so opening `twes-in` installs its copy over ours, and on the
next rent-watch session the target differs from our source again. Without the guard we would snapshot
*twes-in's* copy on top of the irreplaceable original. `test-install.sh` asserts exactly that sequence
— it was added because sabotage-verification showed the simpler assertion passed without the guard.

## `.claude/settings.json` — the relay, and why it is dormant here

Upstream documents this file as **classifier-blocked**: Claude Code prevents Claude from editing its own
permission surface, so the change has to travel through the repo — Claude commits
`settings.json.pending`, the developer applies it locally with `apply-pending-settings.sh`, commits and
pushes, and Claude pulls to re-sync.

**In this container that block did not apply** — a direct `Write` to `.claude/settings.json` succeeded
on 2026-07-29, so no pending file was needed and none exists. [Verified: the `Write` call returned
success and the file is tracked.]

`apply-pending-settings.sh` is kept anyway, for two reasons: the restriction is environment-dependent
and may reappear without notice, and the script is completely inert when there is no pending file (it
prints "Nothing to apply" and exits 0). If a future session finds `Write` denied, it writes
`settings.json.pending` instead and the loop below applies:

1. Claude writes `scripts/claude-bootstrap/settings.json.pending` and pushes.
2. The developer pulls, then runs:

   ```bash
   bash scripts/claude-bootstrap/apply-pending-settings.sh
   ```

   It validates the JSON *before* touching the live file, backs the old one up, copies it into place,
   re-validates, and **deletes the pending copy** so the repo never carries two settings files. It
   stages, commits and pushes nothing — it prints the commands and leaves them to you.
3. The developer commits + pushes. Claude pulls to re-sync.

`.claude/settings.json.bak.*` is gitignored — never commit a backup.

## The PreCompact handoff

Context compaction loses working state. Only committed repo state survives — but a compaction
mid-change is exactly the moment when the useful state is *not* yet committable. `precompact-handoff.sh`
writes it to a gitignored file inside the repo (`var/claude/handoff/`, not `~/.claude/projects/`, which
dies with the container) so the post-compaction context can read it back.

It is **deterministic by default** — git state plus the transcript, parsed with `jq`, no LLM call. Set
`RENTWATCH_HANDOFF_LLM=1` to append a narrative summary via `claude -p`; it is off by default because a
nested CLI re-primes the full system prompt, which upstream measured at roughly **$0.14 per
invocation** (~70k cache-creation tokens for a three-word prompt).

The hook **always exits 0**. That is the PreCompact contract — a hook that blocks compaction is worse
than a missing handoff — and it is why the script uses `set -uo pipefail` without `-e`. Every failure
path still logs a reason via `log_obs`.

Env knobs: `RENTWATCH_HANDOFF_DIR`, `RENTWATCH_HANDOFF_LLM`, `RENTWATCH_HANDOFF_MODEL`.

## Verifying the bootstrap by hand

```bash
bash scripts/claude-bootstrap/install.sh
ls -l ~/.claude/{CLAUDE.md,THINKING.md,BLAST-RADIUS.md}
head -40 ~/.claude/CLAUDE.md          # should open with the rent-watch adaptation header
bash -n scripts/claude-bootstrap/*.sh scripts/claude-bootstrap/hooks/*.sh
bash scripts/claude-bootstrap/hooks/test-precompact-handoff.sh   # 35
bash scripts/claude-bootstrap/test-install.sh                    # 17
```

## Known limits

- **New skills need a session restart to appear.** Claude Code watches an existing `.claude/skills/`
  directory live, but a *newly created* top-level skills directory is not watched until the CLI
  restarts. The `CLAUDE.md` sections bind immediately; the slash commands appear next session.
- **`allow` rules in `.claude/settings.json` are inert in cloud sessions.** They require an accepted
  workspace-trust dialog, which a cloud session never shows; the CLI logs
  `Ignoring N permissions.allow entries … this workspace has not been trusted`. They still work
  locally. `defaultMode` is the key that actually takes effect. Don't grow the allow list expecting
  cloud effect.
- **`disallowed-tools` binds per-turn, not per-session.** It removes `AskUserQuestion` while a skill is
  active; the grant clears on the next user message. Outside a skill, the discipline is yours.
- **No `deny` rules at all**, deliberately — in a cloud session a denied command is an unrecoverable
  dead end, because there is no terminal in which to run it by hand. Nothing mechanically prevents a
  force-push; `CLAUDE.md` § "Git autonomy" is the control.
