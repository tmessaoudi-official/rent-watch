#!/usr/bin/env bash
# rent-watch Claude-container bootstrap — restores the developer's global reasoning framework into the
# EPHEMERAL remote container (a fresh ~/.claude every session), so the project CLAUDE.md's routing
# reference ("the global reasoning framework, ~/.claude/CLAUDE.md") resolves everywhere.
#
# Idempotent + conservative: `cp -u` copies only when the repo copy is NEWER than the target, so a
# hand-edited (newer) ~/.claude file on a real workstation is never clobbered. Silent no-op when
# already current. Wired as a SessionStart hook in .claude/settings.json; safe to run by hand.
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

cp -u "$here/CLAUDE-global.md"  "$dest/CLAUDE.md"
cp -u "$here/THINKING.md"       "$dest/THINKING.md"
cp -u "$here/BLAST-RADIUS.md"   "$dest/BLAST-RADIUS.md"

# var/claude/ is the in-repo, gitignored home for everything the review skills and the PreCompact
# handoff hook write. Created here so a skill never has to guess whether it exists.
mkdir -p "${CLAUDE_PROJECT_DIR:-$here/../..}/var/claude"

exit 0
