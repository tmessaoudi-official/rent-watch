#!/usr/bin/env bash
# rent-watch Claude-container bootstrap — restores the developer's global reasoning framework into the
# EPHEMERAL remote container (a fresh ~/.claude every session), so the project CLAUDE.md's routing
# reference ("the global reasoning framework, ~/.claude/CLAUDE.md") resolves everywhere.
#
# THE REPO IS ALWAYS THE TRUTH (developer ruling, 2026-08-06). The three docs below are copied
# UNCONDITIONALLY on every run. Idempotent — the same bytes land every time — and deterministic, which
# is the point of the ruling.
#
# This replaced `cp -u`, whose header used to claim "a hand-edited (newer) ~/.claude file on a real
# workstation is never clobbered". That claim was FALSE, and the behaviour was nondeterministic:
# `cp -u` copies when the SOURCE is newer, and a fresh `git clone` stamps every file with the clone
# time — so on a real workstation it clobbered anyway, while after a hand-edit of the target it
# silently did nothing and the repo stopped being the truth. Neither outcome was chosen; both depended
# on mtimes nobody was tracking. `scripts/claude-bootstrap/test-install.sh` pins the new contract.
#
# The one thing unconditional copying must not do is destroy a global framework with no way back, so a
# file that predates this hook is snapshotted ONCE to <name>.pre-bootstrap.bak and never touched again.
# That is a safety net, not a second source of truth: it is never read back, and the repo still wins
# every session.
#
# Wired as a SessionStart hook in .claude/settings.json; safe to run by hand.
#
# SCOPE IS DELIBERATELY NARROW: this script copies three documentation files INTO ~/.claude and does
# nothing else. It must never copy anything OUT of ~/.claude into the repo — `~/.claude.json` holds the
# oauth account, userID and machineID, and THIS REPO IS PUBLIC and one `git add -A` away from history.
#
# The upstream port this descends from (phorj) carried, commented out, a block doing exactly that:
#     # cp -R /root/.claude /root/.claude.json <repo>/claude-bundle
#     # git add claude-bundle && commit && push --force-with-lease
# It is absent here by construction and must never be reintroduced, not even commented out: a disabled
# credential-exfiltration path inside a SessionStart hook is one uncomment away from publishing the
# developer's oauth tokens. phorj deleted its copy outright on 2026-08-06 for the same reason, having
# verified it never ran. `/claude-bundle/` is additionally gitignored here as a belt-and-braces guard,
# so even an accidental copy cannot be committed.
#
# The repo-native skills (.claude/skills/*) and agents (.claude/agents/*) need NO install — Claude
# Code reads them in place from the clone.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
dest="${HOME}/.claude"

mkdir -p "$dest"

# install <repo-source> <target-name>
#   1. If the target exists, predates this hook, and differs from what we are about to write, take a
#      one-time snapshot. "Predates this hook" is inferred from the absence of the snapshot itself —
#      once it exists we never write it again, so a later run cannot overwrite the original with our
#      own copy. That ordering is the whole trick, and test-install.sh asserts the converse.
#   2. Copy unconditionally. The repo is the truth.
install_doc() {
  local src="$1" name="$2"
  local target="$dest/$name"
  local backup="$target.pre-bootstrap.bak"

  if [[ -f "$target" && ! -e "$backup" ]] && ! cmp -s "$src" "$target"; then
    cp -p "$target" "$backup" 2>/dev/null \
      && printf 'claude-bootstrap: kept your previous %s as %s\n' "$name" "${backup##*/}" >&2
  fi

  cp -f "$src" "$target"
}

install_doc "$here/CLAUDE-global.md" CLAUDE.md
install_doc "$here/THINKING.md"      THINKING.md
install_doc "$here/BLAST-RADIUS.md"  BLAST-RADIUS.md

# var/claude/ is the in-repo, gitignored home for everything the review skills and the PreCompact
# handoff hook write. Created here so a skill never has to guess whether it exists.
mkdir -p "${CLAUDE_PROJECT_DIR:-$here/../..}/var/claude"

exit 0
