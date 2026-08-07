# Blast-Radius Reference

> Consulted at Phase 5 entry for any destructive or risky operation.
> Core insight: blast radius is **state-dependent** — the same command can be trivial
> in one context and unrecoverable in another. Run pre-flight checks first; they cost
> 3 seconds and the consequences of skipping them can be permanent.

---

## Pre-Flight State Checks

Run the applicable checks **before** executing, not after.

### Git state gate
```bash
git status --porcelain                  # uncommitted / untracked changes?
ls .git/MERGE_HEAD 2>/dev/null          # merge in progress?
ls .git/REBASE_HEAD 2>/dev/null         # rebase in progress?
ls .git/CHERRY_PICK_HEAD 2>/dev/null    # cherry-pick in progress?
```
If **any** of those files exist: `git reset`, `git checkout`, `git stash`, `git clean`
all have doubled blast radius — they can abort the in-progress operation silently.

### Docker state gate
```bash
# Before docker compose down: does it carry -v (volumes) or --rmi (images)?
# Before docker volume rm / docker system prune: list what would be removed.
docker volume ls
docker ps -a
```

### Scope / glob gate
Before any glob (`*.sh`, `-r dir/`, `pkill -f pattern`):
```bash
echo rm *.sh          # verify expansion before rm *.sh
pgrep -a pattern      # verify matches before pkill -f pattern
```

### Protected paths — never auto-delete / never bundle

> **rent-watch container note (2026-08-06):** none of the machinery in this section exists here —
> there is no `~/.claude/state/`, no `~/.claude/hooks/`, no bash firewall, and **no `deny` or `ask`
> tier at all** (the settings are allow-list only, by the developer's ruling: in a cloud session a
> `deny` rule is an unrecoverable dead end, because there is no terminal in which to run the command
> by hand). None of the named bypass sentinels or gate hooks are installed.
> `ask-human-question-guard.sh` in particular is ruled OUT, and the tool it guarded
> (`AskUserQuestion`) is forbidden outright because it times out here.
>
> Read the rest of this file for its **reasoning** — the pre-flight blast-radius checks, the
> backup-and-rollback discipline, and above all the state-dependence insight (the same command is
> trivial in one state and unrecoverable in another) all still apply. Do NOT read it for its
> inventory of files or its permission tiers; both are fiction in this container.
>
> **What actually protects this repo**, now that nothing is denied:
> 1. **Everything lives in git.** `git status --porcelain` before any overwrite is the real pre-write
>    check, and it is cheap. Rule 8 is the operative safety rule here, not the deny list.
> 2. **`git push` is authorised but `--force` is not.** Ordinary pushes are autonomous; rewriting
>    published history is not, and no rule file will stop you — the discipline is yours.
> 3. **The container is disposable.** A destroyed working tree costs one `git checkout`; the only
>    genuinely irreversible actions are the outward-facing ones (force-push, rewriting published
>    history, a deploy).
>    Weight your caution accordingly: be relaxed about local state, strict about anything that leaves.
> 4. **rent-watch has two irreversible actions of its own, and neither involves git.** (a) A **request
>    to a real landlord site or portal** leaves this container and is logged by someone else — a
>    careless loop is a rate-limit ban on the developer's IP, and on a private portal it is a ToS
>    problem. Verify an endpoint once, deliberately; never bulk-probe. (b) A **live notification**
>    reaches the developer's phone. `scout test-notify` exists for that reason; a `--watch` run
>    started to "see if it works" can page them repeatedly at 3am. Both are `ask`-worthy under
>    `/ask-human` even though nothing in the settings will stop you.

**None of that sentinel machinery exists here** — restating the banner above because this paragraph is
where a reader would otherwise assume protection. Upstream, `~/.claude/state/` and
`~/.claude/projects/<slug>/state/` held persistent safety sentinels (`ask-human-gate-bypass`,
`ask-bash-firewall-bypass`, `ask-human-question-guard-bypass`, `autonomous-3c-bypass`), each
`ask`-gated in `settings.json`, matched by a bash-firewall `danger_patterns` substring, and guarded
against deletion by `claude-cleanup.sh`. In this container **none of those paths, tiers, hooks or
scripts is present** [Verified: `ls ~/.claude/state ~/.claude/hooks` → both absent;
`jq '.permissions|keys' .claude/settings.json` → `["allow","defaultMode","deny"]`, no `ask`]. There is
therefore nothing to protect and nothing protecting you: **no bypass can be toggled, and no mechanism
prevents a destructive command.** The discipline in this file is the only control.

---

## Category Reference

### Git — Context-Dependent

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `git reset HEAD` / `git reset` | Removes `MERGE_HEAD`/`REBASE_HEAD`/`CHERRY_PICK_HEAD` → silently aborts in-progress op | Any in-progress merge / rebase / cherry-pick |
| `git checkout <branch>` | Silently discards uncommitted changes if conflicting | Dirty working tree |
| `git clean -fd` | Removes untracked source files, not just build artifacts | Any untracked WIP |
| `git commit --amend` | Rewrites commit hash — breaks anyone who already pulled | After `git push` |
| `git add -A` | Stages accidental deletions alongside intentional changes | Dirty tree with inadvertent deletes |
| `git fetch --prune` | Removes remote-tracking refs without warning | Shared branches deleted upstream |
| `git stash drop` | Permanent — no recycle bin | Stash contains unsaved context |

### Docker — Data Destruction

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `docker compose down -v` | Destroys **all** named volumes (DBs, uploads, persistent data) — `-v` is the killer flag | Any persistent data present |
| `docker system prune` | Removes **all** unused resources across **all** projects on the host | Other projects share the daemon |
| `docker volume rm` | Permanent, instant, no confirmation | Volume holds database data |
| `docker network rm` | Disconnects **all** containers on that network simultaneously | Running containers depend on it |
| `docker rmi` | Can break containers that aren't running but reference the image | Derived or stopped containers exist |
| `docker compose down` (no `-v`) | Removes containers only — volumes preserved | Safe — kept here for contrast |

### Shell / Filesystem

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `rm -rf symlink/` | Trailing `/` → deletes contents of symlink **target**, not the symlink | Target is a shared directory |
| `chmod -R 777 .` | Makes `.git/`, `.env`, SSH keys world-writable | Any secrets or git history in tree |
| `sed -i 's/x/y/g' *.sh` | Glob expands to unexpected files; `-i` has no undo | Pattern matches broadly |
| `mv src dst` | Silently overwrites `dst` — no prompt by default | `dst` already exists |
| `cat > file` | Truncates `file` **immediately** before any data arrives | `file` has content you need |
| `find . -exec rm {} \;` | Executes on every matched file | Condition or path too broad |
| `cp -r src/ dst/` | Behavior differs if `dst/` already exists vs doesn't | Destination state unknown |

### Environment / Shell State

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `source script.sh` | Permanently mutates current shell (PATH, functions, traps) — cannot be undone | Script has side effects |
| `ssh-add -D` | Removes **all** keys from agent, not just the intended one | Multiple keys loaded |
| `git config --global` | Affects **all** repos on the machine | Intended change is repo-scoped |
| `export VAR=` | Sets empty string — different from `unset VAR` in many tools | Tool checks `[[ -n "$VAR" ]]` |
| `eval "$(cmd)"` | Code injection if `cmd` output contains shell metacharacters | `cmd` reads external input |

### Process / Signals

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `kill -9 PID` | No SIGTERM cleanup — can corrupt files mid-write | Process has open file handles or locks |
| `pkill -f pattern` | Matches full **command line**, not just process name — far broader | Pattern is a common substring |
| `killall name` | Kills **all** processes with that name, all users | Multi-process or multi-user system |
| `nohup cmd &` | Persists after logout with no monitoring or signal handling | One-off commands meant to be temporary |

### Make

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `make clean` | `CLEANFILES` may include generated files that are slow to recreate | Generated files aren't in version control |
| `rm -f seen.json` / deleting the listings DB (`$RENT_WATCH_DB`, default `state/rent-watch.sqlite3`) | Wipes the seen-set, so the **next run re-notifies every listing it can still see** — a self-inflicted notification flood, and price history is gone for good | Whenever the store holds anything; the flood is the point of failure, not the file loss |
| `scout run --watch` left running | Polls real landlord sites on a loop and pages the developer's phone on every match | Always — start it deliberately, never "just to check" |
| `rm -rf var/` | **Safe, and named here so nobody widens it.** `var/` is Claude reports and PreCompact handoffs — container-lifetime by design. The store is deliberately NOT there, which is why the path above says `state/`. If you ever see the database under `var/`, that is the finding | Never destructive as written; the risk is someone "tidying up" `state/` by analogy |
| Re-running a source after a field-map edit with `--no-state` | Bypasses the seen-set by design, so every match re-notifies | Any time the notification channel is live; use a fixture or `--dry-run` instead |
| Overwriting a committed fixture under `tests/fixtures/` | The frozen payload IS the test's ground truth; replacing it can make a regression test pass by redefining the expected answer | Always suspicious — a fixture changes only with a stated reason |
| Re-labelling a classifier corpus entry | Same failure, higher stakes: it can silently make a social-housing false positive "correct" | Always — this needs evidence in the commit message |

### Databases

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `TRUNCATE table` | No `WHERE` possible; resets sequences; faster than `DELETE` but equally unrecoverable | Any data you might want back |
| `UPDATE SET col=val` without `WHERE` | Updates **all** rows — parser never warns | Forgetting the WHERE clause |
| `DELETE FROM table` without `WHERE` | Deletes all rows; transaction-safe only if not yet committed | Same |
| `VACUUM FULL` (Postgres) | Exclusive table lock — blocks **all** reads and writes for duration | Actively queried table |
| `DROP TABLE CASCADE` | Also drops dependent views, foreign keys, sequences | Dependent objects exist |

### Package Managers

| Command | Hidden side effect | Dangerous context |
|---------|--------------------|-------------------|
| `npm install` (no args) | Modifies `package-lock.json` — lock drift in CI | CI expects a pinned lockfile |
| `pip install --upgrade pkg` | Can break pinned transitive deps elsewhere in the env | Complex dependency graph |
| `apt-get upgrade` | Can upgrade kernel, restart services, break running programs | Any non-throwaway system |

### Config Artifact Updates — NOT APPLICABLE HERE

Upstream this section listed manual registries to keep in step when adding a skill, plugin or bin
script — `_CE_GENERIC_SKILLS` and `_CE_ORIGIN_ONLY_FILES` in
`~/.claude/bin/lib/claude-export/config/defaults.sh`, a `plugins/marketplaces/local/` tree, and an
`/audit` Agent I backstop to catch drift between them.

**None of it exists here** [Verified: `ls ~/.claude/bin` → absent; `/audit` is listed as not installed
in `CLAUDE-global.md` § "Global Skills Reference"]. There is no bundle-export pipeline and no registry
to keep in sync: this project's skills and agents are read in place from `.claude/`, so adding one
requires no registry entry at all. The only real coupling is that a **newly created** top-level
`.claude/skills/` directory is not watched until the CLI restarts — see
`scripts/claude-bootstrap/README.md` § "Known limits".
