#!/usr/bin/env bash
# test-vehicle-guard.sh — does the §1 tripwire cover the CAR domain's excluded set?
#
# `CLAUDE.md` records this as owed: "The §1 tripwire hook does not cover the vehicle set — a
# tests/test-vehicle-guard.sh is owed". The car domain carries its own non-overridable excluded set
# in `VehicleClassifier` — `accidenté`, `gagé`, `opposition`, `épave`, `VEI`, `VGE`, `procédure VE`,
# `économiquement irréparable`, `pour pièces`, `sans carte grise`, `CT non fourni`, `non roulant` —
# and until 2026-08-31 nothing watched it at all. A relaxation there is the same shape as a housing
# one and arguably worse: a wrecked or impounded car surfaced as a match is not just a wasted trip.
#
# THE GUARD IS THE EXISTING `tenure-guard.sh`, EXTENDED — not a second hook. The plan's name for
# this file implies a `vehicle-guard.sh` beside it; one hook is right because the relaxation SHAPES
# are identical (an allow-list, an emptied set, a weakened test) and only the vocabulary differs.
# Two hooks would be two log formats, two self-exclusion lists and two places to forget. The file
# keeps the name the plan asked for so the owed item is findable.
#
# Both halves, same contract as `tests/test-tenure-guard.sh`:
#
#   MUST FIRE   — writes that would actually relax the vehicle exclusion
#   MUST NOT    — ordinary car-domain PHP that merely looks like them to a regex
#
# The second half is not decoration. The car sources were written in the same week as this guard,
# and a tripwire that fires on `public ?string $make = null;` is one nobody reads by Friday.
#
# Run: bash tests/test-vehicle-guard.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
guard="$repo/.claude/hooks/tenure-guard.sh"

pass=0
fail=0

logdir="$(mktemp -d)"
[[ -d "$logdir" ]] || { printf 'cannot create a scratch dir for the observability log\n' >&2; exit 1; }
trap 'rm -rf "$logdir"' EXIT

# Same reason `test-tenure-guard.sh` gives: without OBS_LOG redirected, every run of this test
# appends synthetic `FIRED on …` lines to the real hooks log, byte-identical in shape to a genuine
# §1 firing against a file that does not exist — which destroys the property that makes that line
# worth keeping.
fired() {
  local path="$1" content="$2" payload
  payload="$(CONTENT="$content" FP="$path" python3 -c '
import json, os
print(json.dumps({"tool_name": "Write",
                  "tool_input": {"file_path": os.environ["FP"], "content": os.environ["CONTENT"]}}))')"

  OBS_LOG="$logdir/hooks-errors.log" CLAUDE_PROJECT_DIR="$repo" bash "$guard" <<<"$payload" >/dev/null 2>&1
  [[ $? -eq 2 ]]
}

# The default path is a CAR-domain file, because the domain signal is the PATH: `src/php/Car/` and
# the `Vehicle*` classes ARE the car domain and nothing in the text needs to say so. That was found
# by probe rather than by design — a first draft required a vehicle word in the WRITE, so
# `private const array NEGATABLE = [];`, the literal way to empty this set, went silent because the
# only vehicle word in sight was in the filename.
CAR="src/php/Car/VehicleClassifier.php"

expect_fire() {
  local label="$1" content="$2" path="${3:-$repo/$CAR}"
  if fired "$path" "$content"; then
    printf '  \033[32mok\033[0m   FIRES     %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m SILENT    %s  <-- the tripwire has a blind spot here\n' "$label"
    fail=$((fail + 1))
  fi
}

expect_silence() {
  local label="$1" content="$2" path="${3:-$repo/$CAR}"
  if fired "$path" "$content"; then
    printf '  \033[31mFAIL\033[0m FIRES     %s  <-- false positive; the guard will be ignored\n' "$label"
    fail=$((fail + 1))
  else
    printf '  \033[32mok\033[0m   silent    %s\n' "$label"
    pass=$((pass + 1))
  fi
}

printf '\n== vehicle-guard: fires on relaxation of the CAR excluded set, silent on ordinary car code ==\n\n'

# ── MUST FIRE ────────────────────────────────────────────────────────────────────────────────────
expect_fire "the negatable set assigned an empty list" \
  'private const array NEGATABLE = [];'

expect_fire "the literal set assigned an empty list" \
  'private const array LITERAL = [];'

expect_fire "an excluded-set accessor returning nothing" \
  'public static function excluded(): array { return []; }'

expect_fire "an excluded condition put on an allow-list" \
  '$allowAccidente = true;'

expect_fire "accidenté declared included" \
  'accidente: included'

expect_fire "épave next to an acceptance keyword" \
  '// epave listings are accepted from now on'

expect_fire "a wrecked-vehicle term enabled by config" \
  '"pour_pieces_enabled": true'

# The vocabulary must be covered as a SET, not as the two examples someone happened to write a case
# for. Each of these is one of `VehicleClassifier`'s own keys; a term dropped from the hook's
# alternation is a silent hole exactly like a term dropped from the classifier.
for term in gage opposition vei vge "economiquement irreparable" "sans carte grise" "non roulant"; do
  expect_fire "excluded term '$term' next to an inclusion keyword" \
    "// $term is now allowed"
done

# The path signal, from the other side: a `Vehicle*` class outside src/php/Car/ is still the car
# domain. Without this the check could be defeated by moving a file.
expect_fire "an emptied set in a Vehicle* class outside src/php/Car" \
  'private const array NEGATABLE = [];' \
  "$repo/src/php/Other/VehicleThing.php"

# ── MUST NOT FIRE ────────────────────────────────────────────────────────────────────────────────
expect_silence "an ordinary nullable property" \
  'public ?string $make = null; public ?int $mileageKm = null;'

expect_silence "the brand penalty's own reason lines" \
  "\$score += \$w['brand']; \$reasons[] = 'marque inconnue — hors score';"

expect_silence "a scorer component list being edited" \
  "public const array COMPONENTS = ['price', 'age', 'mileage', 'gearbox', 'fuel', 'body', 'brand'];"

expect_silence "PHP's array append, which is the opposite of clearing" \
  '$cars[] = $listing;'

expect_silence "a comparison against an empty array" \
  'if ($criteria->brandAvoid !== []) { return null; }'

# THE CLASSIFIER'S OWN CORRECT CODE. Every excluded term appears in it, beside the word `NEGATABLE`
# — so a pattern that keyed on the vocabulary alone would fire on the very file it is guarding,
# every time anyone touched it, which is the fastest way to make it ignored.
expect_silence "the classifier stating its own excluded set" \
  "private const array NEGATABLE = ['accidenté' => '~\\baccident(?:e|ee|es|ees|s)?\\b~u', 'gagé' => '~\\bgage(?:e|ee|es|ees)?\\b~u', 'épave' => '~\\bepaves?\\b~u'];"

# `config/car/` IS NOT THE CAR DOMAIN SIGNAL, and this asserts the distinction rather than a plain
# silence. The §1 vehicle set is CODE — `NEGATABLE`/`LITERAL` in `VehicleClassifier`,
# non-overridable — while `config/car/criteria.json` carries `exclude_patterns`, the ordinary user
# list, which SHIPS EMPTY on purpose (the ParuVendu alert is already scoped to `Voiture
# d'occasion`). A first draft of the hook put `config/car/` in the domain signal and fired a
# VEHICLE hit on the shipped file.
#
# It is asserted as "no VEHICLE hit", not as "no hit at all", because the file DOES still trip the
# pre-existing HOUSING pattern 2 on `exclude_patterns: []`. That is long-standing accepted noise —
# the hook's own header says it fires on a communes block deliberately, on the grounds that §1
# detection must not pay for noise reduction — and it is not this change's to silence. Asserting
# total silence here would have been a quiet request to weaken pattern 2.
vehicle_hit() {
  local path="$1" content="$2" payload
  payload="$(CONTENT="$content" FP="$path" python3 -c '
import json, os
print(json.dumps({"tool_name": "Write",
                  "tool_input": {"file_path": os.environ["FP"], "content": os.environ["CONTENT"]}}))')"

  OBS_LOG="$logdir/hooks-errors.log" CLAUDE_PROJECT_DIR="$repo" bash "$guard" <<<"$payload" 2>&1 | grep -q 'VEHICLE'
}

if vehicle_hit "$repo/config/car/criteria.json" \
  '{"_exclude_patterns": "Empty on purpose: the ParuVendu alert is already Voiture d occasion", "exclude_patterns": []}'; then
  printf '  \033[31mFAIL\033[0m FIRES     the shipped car config raises a VEHICLE hit  <-- config/car is not the §1 set\n'
  fail=$((fail + 1))
else
  printf '  \033[32mok\033[0m   silent    the shipped car config raises no VEHICLE hit\n'
  pass=$((pass + 1))
fi

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
