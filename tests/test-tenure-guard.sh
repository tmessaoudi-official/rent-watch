#!/usr/bin/env bash
# test-tenure-guard.sh — does the §1 tripwire still fire on the things it is for?
#
# `.claude/hooks/tenure-guard.sh` is a PostToolUse grep. `CLAUDE.md` is explicit that it is "a
# tripwire on this rule, not a guarantee" and that "a clean run proves nothing" — but a tripwire
# that has quietly stopped tripping is worse than no tripwire, because its silence is read as
# safety. This asserts both halves:
#
#   MUST FIRE   — the writes that would actually relax the rule
#   MUST NOT    — ordinary PHP that merely looks like them to a regex
#
# The second half exists because the first PHP written in this repo tripped the guard four times in
# one session, every time on prose or on `$array[] =` (PHP's append, which the pattern read as an
# empty-list literal). A guard that cries wolf on every commit gets ignored, so its false-positive
# surface is part of its contract and is tested here.
#
# Run: bash tests/test-tenure-guard.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
guard="$repo/.claude/hooks/tenure-guard.sh"

pass=0
fail=0

logdir="$(mktemp -d)"
[[ -d "$logdir" ]] || { printf 'cannot create a scratch dir for the observability log\n' >&2; exit 1; }
trap 'rm -rf "$logdir"' EXIT

# Feed a synthetic PostToolUse payload to the guard; exit 2 means it fired.
fired() {
  local path="$1" content="$2"
  local payload
  payload="$(CONTENT="$content" FP="$path" python3 -c '
import json, os
print(json.dumps({"tool_name": "Write",
                  "tool_input": {"file_path": os.environ["FP"], "content": os.environ["CONTENT"]}}))')"

  # OBS_LOG is redirected to a scratch file, and that is not tidiness. Without it every run of this
  # test appended ten synthetic `FIRED on …/Thing.php` lines to the REAL
  # var/claude/logs/hooks-errors.log — byte-identical in shape to a genuine §1 firing, against a
  # file that does not exist. tenure-guard.sh calls that line "the single most important line the
  # log can carry — it must be greppable after the session is gone", and a rehearsal indexed
  # alongside the real thing destroys exactly that property.
  OBS_LOG="$logdir/hooks-errors.log" CLAUDE_PROJECT_DIR="$repo" bash "$guard" <<<"$payload" >/dev/null 2>&1
  [[ $? -eq 2 ]]
}

# `$3` is an OPTIONAL path, mirroring expect_silence. It was missing until review round 5, so the
# one case that passed a path — the lookalike guarding the two exact-path self-exclusions — silently
# tested `Thing.php` instead. Demonstrated: widening the exclusion to a bare glob let
# `src/php/Core/sabotage-check.sh` shelter `FLOOR_BP = 10` while this suite still reported 35/35.
expect_fire() {
  local label="$1" content="$2" path="${3:-$repo/src/php/Core/Thing.php}"
  if fired "$path" "$content"; then
    printf '  \033[32mok\033[0m   FIRES     %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m SILENT    %s  <-- the tripwire has a blind spot here\n' "$label"
    fail=$((fail + 1))
  fi
}

expect_silence() {
  local label="$1" content="$2" path="${3:-$repo/src/php/Core/Thing.php}"
  if fired "$path" "$content"; then
    printf '  \033[31mFAIL\033[0m FIRES     %s  <-- false positive; the guard will be ignored\n' "$label"
    fail=$((fail + 1))
  else
    printf '  \033[32mok\033[0m   silent    %s\n' "$label"
    pass=$((pass + 1))
  fi
}

printf '\n== tenure-guard: fires on relaxation, silent on ordinary code ==\n\n'

# ── MUST FIRE ────────────────────────────────────────────────────────────────────────────────────
expect_fire "excluded set assigned an empty list" \
  'const EXCLUDED_TENURES = [];'
expect_fire "excluded-set ACCESSOR emptied (PHP: return [];)" \
  'public static function excluded(): array { return []; }'
expect_fire "excluded set emptied as a PHP array value" \
  "\$config = ['excluded' => []];"
expect_fire "excluded set emptied with PHP's legacy array()" \
  'const EXCLUDED_TENURES = array();'
expect_fire "excluded set nulled in YAML" \
  'excluded_tenures: null'
expect_fire "excluded set nulled with a YAML tilde" \
  'excluded_tenures: ~'
expect_fire "excluded set emptied as a YAML map" \
  'excluded_tenures: {}'
expect_fire "excluded set nulled in JSON" \
  '"excluded_tenures": null'
expect_fire "excluded set emptied as a bash array" \
  'EXCLUDED_TENURES=()'
expect_fire "excluded set assigned empty in YAML style" \
  'excluded_tenures: []'
expect_fire "excluded set set to none" \
  'excluded_tenures = None'
expect_fire "PLAI added to an allow-list" \
  'allowed_tenures = ["LLI", "PLAI"]'
expect_fire "PLS added to an allow-list (Q4 ruled PLS out on 2026-08-06)" \
  'allowed_tenures = ["LLI", "PLS"]'
expect_fire "PLS becoming a config toggle" \
  'config option include_pls to surface pls listings'
expect_fire "social tenure enabled in config" \
  'plai: true  # enabled'
expect_fire "fail-closed floor lowered, expressed as a float" \
  'const CONFIDENCE_FLOOR = 0.3;'
# The representation the CODE actually uses. `0.6` appears nowhere under src/ — confidence is stored
# in integer basis points so PHP and phorj cannot disagree on a float — so a guard that only knew
# the float form was watching a representation nothing uses. Setting FLOOR_BP to 0 was silent.
expect_fire "fail-closed floor lowered, expressed in integer basis points" \
  'private const int FLOOR_BP = 30;'
expect_fire "fail-closed floor removed entirely" \
  'private const int FLOOR_BP = 0;'
expect_fire "the floor weakened at the comparison instead of the constant" \
  'if ($confidenceBp < 20) { return Tenure::UNKNOWN; }'
expect_fire "an excluded tenure let out through the accessor" \
  'public function isExcluded(): bool { return match($this) { self::PLS => false, default => true }; }'
expect_fire "an excluded tenure declared non-excluded in a table" \
  "'PLAI' => false,"
# Pattern 7 against the CamelCase paths the classifier tests actually have. Bash `case` globs are
# case-sensitive, so `*tests*tenure*` matched neither of these and the pattern had never run on the
# only two files where a skipped classifier test is possible — which CLAUDE.md §1 rates P0.
expect_fire "a skipped test in the CamelCase corpus suite" \
  'public function testCorpusCaseClassifiesAsLabelled(): void { $this->markTestSkipped("todo"); }' \
  "$repo/tests/php/Core/TenureCorpusTest.php"
expect_fire "a skipped test in the CamelCase classifier suite" \
  '$this->markTestSkipped("todo: revisit PLS handling");' \
  "$repo/tests/php/Core/TenureClassifierTest.php"
expect_fire "UNKNOWN routed to notification" \
  'if unknown: notify(listing)'
expect_fire "classifier bypassed" \
  'skip_tenure = True'

# ── MUST NOT FIRE ────────────────────────────────────────────────────────────────────────────────
expect_silence "PHP array append inside the conflict rule" \
  '$flat[] = new TenureSignal(tier: 2, tenure: Tenure::PLAI); if ($t->isExcluded()) { return $x; }'
expect_silence "an ordinary PHP empty-array comparison" \
  'if ($objections !== []) { return $this->verdict(Tenure::UNKNOWN, 0, $flat, $source); }'
expect_silence "the excluded set being asserted in a test" \
  "self::assertSame(['ANAH', 'ANRU', 'PLAI', 'PLS', 'PLUS'], \$excluded);"
expect_silence "a float epsilon that is not a threshold" \
  'self::assertEqualsWithDelta($a, $b, 0.0001);'
# The CORRECT value must stay quiet, or the tripwire fires on every edit that touches the floor and
# is learned-to-be-ignored within a day. `[0-5]?[0-9]` on its own matches the trailing `0` of `60`,
# which is why the integer alternation anchors the number to the operator.
expect_silence "the fail-closed floor at its correct value" \
  'private const int FLOOR_BP = 60;'
expect_silence "a confidence ceiling, which is not the floor" \
  'private const int CEILING_BP = 99;'
expect_silence "isExcluded returning true, which is the rule working" \
  'if ($result->tenure->isExcluded()) { return Outcome::REJECT; }'
expect_silence "routing UNKNOWN to the digest, which is the rule" \
  'if ($tenure === Tenure::UNKNOWN) { return Outcome::DIGEST; }'
# THE SIX NEXT-MILESTONE FALSE POSITIVES (review round 5). Every one of these is correct code that
# has nothing to do with §1, and every one fired. The guard read as clean on the whole tree only
# because the tree is five files: no config/, no adapter, no notify module, no health module —
# which is exactly where the guard's vocabulary collides with ordinary code. A tripwire that fires
# on correct code is read as noise within a day, which costs more than the gap it closed.
expect_silence "a communes block in criteria.yaml" \
  'communes:
  included: [Cergy, Nanterre]
  excluded: []' \
  "$repo/config/criteria.yaml"
expect_silence "a non-tenure denylist in criteria.yaml" \
  'blocked_landlords: null' \
  "$repo/config/criteria.yaml"
expect_silence "a notify module restating the routing rule" \
  '/** UNKNOWN never reaches the notify channel — it goes to the digest instead. */' \
  "$repo/src/php/Core/Notify/Formatter.php"
expect_silence "a health module drop threshold in basis points" \
  'private const int DROP_THRESHOLD_BP = 30;' \
  "$repo/src/php/Core/Health.php"
expect_silence "an adapter docblock mentioning an absent tenure hint" \
  '/** A source with no tenure hint still runs the classifier. */' \
  "$repo/src/php/Adapters/HttpJson.php"
expect_silence "a sources.yaml comment describing a mixed-tenure landlord" \
  '# config: CDC Habitat publishes PLUS and PLAI alongside LLI' \
  "$repo/config/sources.yaml"

# Pattern 7 must not fire on PROSE ABOUT skipping — both of these are real lines from the two
# classifier test files, and the second is that file's own docblock quoting CLAUDE.md's rule.
expect_silence "a docblock describing a deleted skip branch" \
  'position > $s->position -> skip, and sabotage-verification proved that clause unreachable' \
  "$repo/tests/php/Core/TenureClassifierTest.php"
expect_silence "a docblock quoting CLAUDE.md's own rule about skipped fixtures" \
  'A skipped, xfailed, deleted or relabelled fixture is a P0 finding unless the old label was wrong.' \
  "$repo/tests/php/Core/TenureCorpusTest.php"

expect_silence "a file outside src/, config/ and tests/" \
  'excluded_tenures = []' \
  "$repo/README.md"
expect_silence "the guard's own test file, which is full of these payloads by design" \
  'allowed_tenures = ["LLI", "PLAI"]' \
  "$repo/tests/test-tenure-guard.sh"
expect_silence "the sabotage script, whose whole content is deliberate relaxations" \
  "s/public const int FLOOR_BP = 60;/public const int FLOOR_BP = 10;/" \
  "$repo/tests/sabotage-check.sh"
# The exclusions above are by EXACT PATH, so they must not shelter a lookalike elsewhere.
expect_fire "a file merely named like the excluded ones" \
  'private const int FLOOR_BP = 10;' \
  "$repo/src/php/Core/sabotage-check.sh"

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"

[[ $fail -eq 0 ]]
