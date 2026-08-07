#!/usr/bin/env bash
# tenure-guard.sh — PostToolUse (Edit|Write)
#
# Enforces the one non-negotiable rule of this repo (CLAUDE.md §1):
#   `logement social` (PLAI, PLUS) must NEVER be surfaced as a match.
#
# This hook is a tripwire, not a gate. It cannot understand intent, so it never blocks
# a write. It runs PostToolUse and exits 2 when it fires, which feeds its stderr back
# to Claude in the same turn — so the change gets explained (or reverted) before it
# ships, rather than the warning scrolling past in a transcript nobody re-reads.
# It greps; it does not reason. A clean run proves nothing.
#
# Fires when a write to config/ or src/ looks like it is:
#   - adding PLAI / PLUS / social tenure to an *allowed* / *included* list
#   - lowering or removing the fail-closed confidence threshold (0.6)
#   - disabling the classifier, or making the excluded set user-overridable
#   - weakening a classifier test/fixture

set -uo pipefail

# Rule 13 observability (global CLAUDE.md, shipped by scripts/claude-bootstrap/install.sh): a hook
# that runs unattended logs state-changing actions and errors to var/claude/logs/ IN THE REPO. Sourced
# rather than reimplemented so there is one log format and one destination. Never fatal — a logging
# failure must not take down the hook that is logging, hence the `|| true` and the no-op fallback.
_HELPERS="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}/scripts/claude-bootstrap/hooks/log-helpers.sh"
# shellcheck disable=SC1090
[[ -f "$_HELPERS" ]] && source "$_HELPERS" 2>/dev/null || true
declare -F log_obs >/dev/null 2>&1 || log_obs() { :; }

payload="$(cat)"

py() { command -v python3 >/dev/null 2>&1 && python3 "$@"; }

read -r file_path new_text <<<"$(
  py -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("\t"); raise SystemExit
i = d.get("tool_input", {}) or {}
fp = i.get("file_path") or i.get("notebook_path") or ""
parts = [
    i.get("content") or "",
    i.get("new_string") or "",
    i.get("new_source") or "",
]
for e in (i.get("edits") or []):
    parts.append(e.get("new_string") or "")
blob = " ".join(parts).replace("\n", " ").replace("\t", " ")
print(f"{fp}\t{blob}")
' <<<"$payload" 2>/dev/null
)" || true

# Nothing parseable (non-Edit/Write shape, or no python3) — stay silent.
[[ -z "${file_path:-}" ]] && exit 0

# The guard's OWN test feeds it the exact payloads it exists to catch — an emptied excluded set, a
# lowered floor, PLAI on an allow-list. Scanning that file would make the tripwire fire on every
# edit to it, forever, which is the fastest way to teach a reader to ignore it. Excluded by exact
# path, which is narrow enough that it cannot become a hiding place: it is a shell script, the guard
# only ever reads src/, config/ and tests/, and drift-scan S6 audits the guard's patterns from the
# outside without consulting this file at all.
case "$file_path" in
  */tests/test-tenure-guard.sh) exit 0 ;;
  # sabotage-check.sh is the same case for the same reason: its whole content is `sed` expressions
  # that lower FLOOR_BP, empty the excluded set and disable the classifier, because its job is to
  # prove the PHPUnit suite CATCHES each of those. It joined this list on 2026-08-06, when pattern 3
  # learned the integer basis-point form and started firing on the sabotage that sets FLOOR_BP to 10.
  */tests/sabotage-check.sh) exit 0 ;;
esac

case "$file_path" in
  */config/*|*/src/*|*/tests/*|*config/*|*src/*|*tests/*) ;;
  *) exit 0 ;;
esac

blob="$(printf '%s' "${new_text:-}" | tr '[:upper:]' '[:lower:]')"
[[ -z "$blob" ]] && exit 0

hits=()

# 1. Social tenure appearing near an inclusion keyword.
# `pls` joined the alternation on 2026-08-06: the Q4 answer ruled PLS out of scope, so it is an
# excluded term like the rest, and drift-scan S6 now asserts this pattern covers it.
if grep -Eq '(allow|allowed|include|included|accept|accepted|in_scope|whitelist|enabled)[^.]{0,80}(plai|plus|pls|logement social|conventionn|anru|anah)' <<<"$blob" \
|| grep -Eq '(plai|plus|pls|logement social|conventionn|anru|anah)[^.]{0,80}(allow|allowed|include|included|accept|accepted|in_scope|whitelist|: *true)' <<<"$blob"; then
  hits+=("social tenure (PLAI/PLUS/PLS/conventionné/ANRU/ANAH) appears next to an inclusion keyword")
fi

# 2. Social tenure being removed from the excluded set.
#
# The empty-list alternatives are anchored to the SHAPES THAT ACTUALLY EMPTY SOMETHING, because a
# bare `[]` anywhere within 80 characters is not one of them in PHP: `$flat[] = …` is an append —
# the exact opposite of clearing — and `!== []` is a comparison. Both tripped the guard while the
# first PHP in this repo was being written.
#
# Anchoring to `= []` alone was NOT enough, and the first attempt at this comment wrongly claimed it
# lost no detection. A 2026-08-06 review showed `public static function excluded(): array { return
# []; }` — precisely how you would empty an excluded-set accessor in the language this repo now
# uses — going silent. `return []` and `=> []` are therefore listed explicitly, and the assignment
# form excludes a preceding `!`, `=`, `<` or `>` so comparisons stay quiet. Every shape here, and
# every shape that must NOT fire, has a case in tests/test-tenure-guard.sh.
#
# The alternation also covers the empty/null idioms of the languages this repo ACTUALLY uses. It
# carried `= *none` — a Python idiom, and the only Python here is the superseded prototype — while
# missing the YAML and JSON nulls that `config/criteria.yaml` and `config/sources.yaml` will be
# written in, and PHP's `array()`. That inversion was a standing gap, not a regression from the
# narrowing: the pre-narrowing pattern missed them too.
#
# SUPPRESSED ONLY FOR THIS REPO'S OWN NON-TENURE CONFIG SUBJECTS, and the shape of that exemption
# matters. `exclude|blocked|never` are ordinary words in ordinary config: `blocked_landlords: null`
# and a `communes:` block with `excluded: []` — the single most likely line config/criteria.yaml
# will ever contain — both fired. The guard read as silent on the whole tree only because the tree
# is five files with no config/ yet; six plausible next-milestone files produced six false positives.
#
# The obvious narrowing — "require a tenure token nearby" — was tried and REJECTED: it silenced
# `public static function excluded(): array { return []; }`, which is precisely how you would empty
# an excluded-set accessor in the language this repo uses and which names no tenure at all. §1
# detection must not pay for noise reduction.
#
# So the suppression is a closed list of subjects that are demonstrably NOT tenure — commune,
# landlord, source, postcode — drawn from this repo's own criteria/sources schema, and it applies
# only when NO tenure token appears anywhere in the write. Forgetting an entry costs a false
# positive, never a missed detection, which is the direction a §1 tripwire must fail in.
_empty='(remove|delete|drop|pop|clear|[^!=<>] *= *\[\]|=> *\[\]|return *\[\]|= *none|= *null|= *array\(\)|= *\(\)|: *\[\]|: *\{\}|: *null|: *~)'
_tenure='(plai|plus|pls|logement social|conventionn|anru|anah|hlm|tenure)'
_not_tenure='(commune|landlord|bailleur|source|postcode|code postal|quartier|ville|departement|arrondissement|keyword|portal)'
if grep -Eq "(exclude|excluded|denied|blocked|forbidden|never)[^.]{0,80}$_empty" <<<"$blob" \
&& { grep -Eq "$_tenure" <<<"$blob" || ! grep -Eq "$_not_tenure" <<<"$blob"; }; then
  hits+=("the excluded-tenure set looks like it is being emptied or shrunk")
fi

# 3. Fail-closed confidence threshold being weakened.
#
# TWO REPRESENTATIONS, AND FOR A WHILE THIS ONLY KNEW THE ONE NOTHING USES. The rule is written as
# "confidence < 0.6" in CLAUDE.md, so the first pattern looked for `0.[0-5]` — but the classifier
# stores confidence in INTEGER BASIS POINTS (`FLOOR_BP = 60`) precisely so that PHP and phorj cannot
# disagree on a float, and a run of `0.6` appears nowhere in src/. A 2026-08-06 review set the floor
# to 0 and watched the tripwire stay silent. The float form is kept because config/criteria.yaml and
# the notification payload will both express confidence as a fraction; the integer form is what the
# code actually uses. Both must be covered, and tests/test-tenure-guard.sh has a case for each.
#
# The integer alternation anchors the number to the operator (`= *` / `< *`) rather than searching
# the line, so `FLOOR_BP = 60` cannot match by starting at its trailing `0` — which is exactly what a
# bare `[0-5]?[0-9]` does, and what made the first attempt at this pattern fire on the correct value.
#
# It also requires the `_bp` suffix rather than a bare `floor`/`minimum`. "Floor" is OVERLOADED in
# this repo: `RawListing::$floor` is the building storey, and its docblock says `$floor = 0` is the
# rez-de-chaussée. A first draft without the suffix fired on that docblock, on `$confidenceBp = 0`
# (assigning a computed result, not a threshold) and on two more correct files — four of the five
# source files in the repo. A tripwire that fires on correct code is read as noise within a day,
# which costs more than the gap it closed. Assignment is watched only on a `*_bp` FLOOR constant;
# comparison is watched on `confidenceBp` itself, where `<` is the only interesting operator.
if grep -Eq 'confidence[^.]{0,40}(0\.[0-5][0-9]?|< *0\.[0-5])' <<<"$blob" \
|| grep -Eq '(confidence|tenure|classif)[a-z_]{0,12}(floor|threshold|seuil)_bp[^.]{0,20}= *([0-5]?[0-9])([^0-9.]|$)' <<<"$blob" \
|| grep -Eq '(^|[^a-z_])floor_bp[^.]{0,20}= *([0-5]?[0-9])([^0-9.]|$)' <<<"$blob" \
|| grep -Eq 'confidence_?bp[^.]{0,20}< *([0-5]?[0-9])([^0-9.]|$)' <<<"$blob"; then
  hits+=("classifier confidence threshold looks lowered below the fail-closed floor (0.6, i.e. 60 basis points)")
fi

# 3b. An excluded tenure being reclassified as not-excluded.
#
# Pattern 2 watches the excluded SET being emptied. This watches a single member being let out
# through the accessor instead — `self::PLS => false` inside `isExcluded()` leaves the set literal
# intact, passes pattern 2 untouched, and is the smallest possible edit that breaks §1. Found by the
# same review that found the floor gap: six real relaxations went silent, and this was the quietest.
#
# The token must be ADJACENT to the arrow — either enum-qualified (`self::PLS => false`) or alone
# inside its own quotes (`'PLAI' => false`). Allowing arbitrary text between fired on
# `'explicit PLAI' => false`, the coverage tracker in TenureCorpusTest, whose `false` means "this
# shape has not been seen yet" and is the opposite of a relaxation. `[^.;}]` on the accessor rule
# keeps `isExcluded(); … return false` — two unrelated statements — from being read as one.
if grep -Eq '(^|[^a-z0-9_])(self|static|tenure)::(plai|plus|pls|anru|anah|conventionne|social)[, ]*=> *false' <<<"$blob" \
|| grep -Eq "['\"](plai|plus|pls|anru|anah|conventionne|social)['\"] *(=>|->|:) *false" <<<"$blob" \
|| grep -Eq '(is_?excluded|excluded)[^.;}]{0,20}(=>|return) *false' <<<"$blob"; then
  hits+=("an excluded tenure looks like it is being reclassified as not-excluded")
fi

# 4. UNKNOWN being treated as notifiable.
# `never|not|pas|jamais|instead of` in between means the text is RESTATING the rule, not breaking
# it — `/** UNKNOWN never reaches the notify channel */` is the comment any notify module will carry.
if grep -Eq 'unknown[^.]{0,60}(notify|match|emit|send|alert)' <<<"$blob" \
&& ! grep -Eq 'unknown[^.]{0,60}(never|not |n.t |pas |jamais|instead of|rather than|au lieu)[^.]{0,60}(notify|match|emit|send|alert)' <<<"$blob"; then
  hits+=("UNKNOWN tenure looks like it is being routed to notification instead of the \"à vérifier\" digest")
fi

# 5. Classifier turned off / bypassed.
# `no` dropped from the verb list: `a source with no tenure hint`, `no tenure signal` and `no
# tenure classifier` are all ordinary prose ABOUT the rule, and CLAUDE.md § Gotchas already records
# this firing on the repo's own comments. A real bypass is spelled skip/bypass/disable/without.
if grep -Eq '(skip|bypass|disable|without|sans)[_ -]?(tenure|classif)' <<<"$blob"; then
  hits+=("the tenure classifier looks like it is being skipped or disabled")
fi

# 6. Making the hard exclusions configurable.
# A leading `#` or `//` means the line is a COMMENT describing the source, not a key enabling it:
# `# config: CDC Habitat publishes PLUS and PLAI alongside LLI` is exactly the note config/sources.yaml
# will carry about a mixed-tenure landlord, and firing on it teaches the reader to ignore the guard.
if grep -Eq '(config|option|setting|toggle|flag|param)[^.]{0,60}(plai|plus|pls|logement social|allow_social|include_social)' <<<"$blob" \
&& ! grep -Eq '^ *(#|//|\*)[^\n]{0,120}(config|option|setting|toggle|flag|param)[^.]{0,60}(plai|plus|pls|logement social)' <<<"$blob"; then
  hits+=("social tenure looks like it is becoming a config toggle — CLAUDE.md forbids this")
fi

# 7. Weakening classifier tests.
#
# MATCHED CASE-INSENSITIVELY, because bash `case` globs are not. The repo's classifier tests are
# `tests/php/Core/TenureCorpusTest.php` and `TenureClassifierTest.php` — CamelCase — so
# `*tests*tenure*` and `*tenure*test*` matched NEITHER, and this pattern had never once executed
# against the only two files where a skipped classifier test can happen. CLAUDE.md §1 rates that P0.
lc_path="$(printf '%s' "$file_path" | tr '[:upper:]' '[:lower:]')"
case "$lc_path" in
  *tests*tenure*|*tenure*test*|*tests/fixtures*)
    # ACTUAL SKIP CONSTRUCTS, not the WORD "skip". The bare-word version only became reachable when
    # the case above learned to match CamelCase paths — and it immediately fired on both real
    # classifier test files, on prose: one docblock explaining a deleted `→ skip` branch, and one
    # QUOTING CLAUDE.md's own "a skipped, xfailed, deleted or relabelled fixture is P0". A tripwire
    # that fires on the file's own statement of the rule it enforces is noise on every single edit
    # to the two files it most needs to be believed about.
    if grep -Eq '(marktestskipped|marktestincomplete|pytest\.mark\.(skip|xfail)|unittest\.skip|@expectedfailure|@skip|\.skip\(|xit\(|xdescribe|#\[ignore\]|(^|[^a-z])(todo|fixme):|# *disabled)' <<<"$blob"; then
      hits+=("a tenure test looks like it is being skipped or marked xfail — fix the classifier, not the test")
    fi
    ;;
esac

[[ ${#hits[@]} -eq 0 ]] && exit 0

# WARN, not INFO: this is the repo's one non-negotiable rule, and a firing here is the single most
# important line the log can carry — it must be greppable after the session is gone.
log_obs WARN tenure-guard "FIRED on $file_path — ${#hits[@]} signal(s): ${hits[*]}" || true
{
  echo "⛔ tenure-guard: this write may relax the non-negotiable social-housing rule."
  echo "   file: $file_path"
  for h in "${hits[@]}"; do
    echo "   • $h"
  done
  echo
  echo "   CLAUDE.md §1: PLAI/PLUS/conventionné/ANRU/ANAH must NEVER be surfaced as a match,"
  echo "   and that is not a config toggle. Confidence < 0.6 on a mixed-tenure source means"
  echo "   UNKNOWN → \"à vérifier\" digest, never a notification."
  echo
  echo "   If this change is intentional, say so explicitly to the user and explain why"
  echo "   before continuing. If it is not, revert it."
} >&2

# Exit 2 feeds the message above back to Claude rather than only to the transcript.
exit 2
