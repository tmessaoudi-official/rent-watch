#!/usr/bin/env bash
# sabotage-check.sh — does the classifier suite actually CATCH a regression?
#
# A green test run proves the code passes the tests. It does not prove the tests would notice if the
# code stopped working — and for this module that distinction is the whole ballgame, because every
# failure mode here is SILENT. A classifier that quietly rejects everything looks exactly like a
# quiet rental market, and a classifier that quietly admits social housing looks exactly like a
# productive one until an application is wasted.
#
# So: break each guarantee on a scratch copy, one at a time, and require the suite to go red. A
# sabotage that leaves the suite green is a hole in the corpus, reported as FAIL.
#
# Run: bash tests/sabotage-check.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
# Checked, because this script runs `rm -rf "$work/repo"` and there is no `set -e`. An unchecked
# mktemp failure would leave $work empty and turn that into `rm -rf "/repo"`, as root.
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed; refusing to continue\n' >&2; exit 1; }
trap 'rm -rf "$work"' EXIT

pass=0
fail=0

# Each entry: label :: file :: sed expression that breaks the guarantee
run_sabotage() {
  local label="$1" target="$2" expr="$3"

  rm -rf "$work/repo"
  mkdir -p "$work/repo"
  # src/ and tests/ are copied because the sabotage edits them. vendor/ (composer's autoloader,
  # which resolves PSR-4 relative to its own location) and the runner are symlinked — copying them
  # per sabotage is pure I/O for no isolation benefit.
  # Checked: an unnoticed copy failure makes the scratch suite fail for the wrong reason, and the
  # detection assertion below cannot tell that apart from a caught sabotage.
  if ! cp -a "$repo/src" "$repo/tests" "$repo/phpunit.xml" "$repo/composer.json" "$work/repo/" \
    || ! cp -a "$repo/vendor" "$work/repo/vendor" \
    || ! ln -s "$repo/tools" "$work/repo/tools"; then
    printf '  \033[31mFAIL\033[0m %-58s (could not build the scratch copy)\n' "$label"
    fail=$((fail + 1))
    return
  fi

  if ! sed -i "$expr" "$work/repo/$target"; then
    printf '  \033[31mFAIL\033[0m %-58s (sabotage could not be applied)\n' "$label"
    fail=$((fail + 1))
    return
  fi

  # The sabotage must actually change the file, or we are testing nothing.
  if cmp -s "$repo/$target" "$work/repo/$target"; then
    printf '  \033[31mFAIL\033[0m %-58s (sabotage was a no-op — the pattern no longer matches)\n' "$label"
    fail=$((fail + 1))
    return
  fi

  local out
  out="$(cd "$work/repo" && php tools/phpunit.phar --colors=never --do-not-cache-result 2>&1)"
  local rc=$?

  # A non-zero exit is NOT evidence of detection — a PHP parse error, a missing autoloader or a
  # failed `cd` produces one too, and asserting on `rc` alone would report those as successes. The
  # only thing that proves the SUITE caught the sabotage is the suite saying so.
  if grep -qE '^(FAILURES!|ERRORS!|OK, but)' <<<"$out" && grep -qE 'Failures: [1-9]|Errors: [1-9]' <<<"$out"; then
    printf '  \033[32mok\033[0m   %-58s (suite went red, as it must)\n' "$label"
    pass=$((pass + 1))
  elif [[ $rc -ne 0 ]]; then
    printf '  \033[31mFAIL\033[0m %-58s (suite exited %d but reported no assertion failure — the\n' "$label" "$rc"
    printf '        harness broke rather than the sabotage being caught)\n'
    printf '        %s\n' "$(tail -3 <<<"$out")"
    fail=$((fail + 1))
  else
    printf '  \033[31mFAIL\033[0m %-58s (SUITE STAYED GREEN — this regression is undetected)\n' "$label"
    fail=$((fail + 1))
  fi
}

printf '\n== sabotage-check: can the suite detect a broken classifier? ==\n\n'

# BASELINE FIRST, and this is not ceremony. Every sabotage below asserts "the suite went red". If
# the suite is ALREADY red — a missing autoloader, a syntax error, a half-applied edit — then every
# single sabotage reports success and the whole run is a green light that means nothing. That
# happened on 2026-08-06, and it is why this check exists before the loop rather than after it.
if ! (cd "$repo" && php tools/phpunit.phar --no-output --do-not-cache-result >/dev/null 2>&1); then
  printf '  \033[31mABORT\033[0m the suite is red BEFORE any sabotage — every result below would be a\n'
  printf '        false positive. Fix the suite first, then re-run.\n\n'
  exit 1
fi
printf '  baseline: suite is green — sabotage results are meaningful\n\n'

run_sabotage "PLS dropped from the excluded set" \
  src/php/Core/Tenure.php \
  's/^            self::PLS,$//'

run_sabotage "PLAI dropped from the excluded set" \
  src/php/Core/Tenure.php \
  's/^            self::PLAI,$//'

run_sabotage "conflict rule removed (eligible verdict no longer withholds)" \
  src/php/Core/TenureClassifier.php \
  's/if ($objections !== \[\]) {/if (false) {/'

run_sabotage "collocation guard removed (bare 'plus' becomes a social label)" \
  src/php/Core/TenureClassifier.php \
  's/if (!$inContext) {/if (false) {/'

run_sabotage "comparative suppression removed ('LOGEMENT PLUS GRAND' reads as financing)" \
  src/php/Core/TenureClassifier.php \
  's|^    private const string COMPARATIVE_TAIL = .*$|    private const string COMPARATIVE_TAIL = "zzzznevermatches";|'

run_sabotage "word boundaries removed (substring match: 'plaine' becomes PLAI)" \
  src/php/Core/Text.php \
  "s|'/(?<!\[a-z0-9\])' . preg_quote(\$needle, '/') . '(?!\[a-z0-9\])/u'|'/' . preg_quote(\$needle, '/') . '/u'|"

run_sabotage "source default raised above the fail-closed floor" \
  src/php/Core/TenureClassifier.php \
  's/5 => 50\]/5 => 95]/'

run_sabotage "fail-closed floor lowered" \
  src/php/Core/TenureClassifier.php \
  's/public const int FLOOR_BP = 60;/public const int FLOOR_BP = 10;/'

run_sabotage "mixedTenure defaults to false (config omission opens the gate)" \
  src/php/Core/SourceProfile.php \
  's/public bool $mixedTenure = true,/public bool $mixedTenure = false,/'

run_sabotage "UNKNOWN routed to the notification channel" \
  src/php/Core/TenureClassifier.php \
  's/if ($tenure === Tenure::UNKNOWN) {/if (false) {/'

run_sabotage "fail-closed downgrade removed (mixed source keeps an eligible tenure)" \
  src/php/Core/TenureClassifier.php \
  's/if ($tenure->isEligible() \&\& $confidenceBp < self::FLOOR_BP \&\& $source->mixedTenure) {/if (false) {/'

run_sabotage "malformed UTF-8 folded to an empty string again" \
  src/php/Core/Text.php \
  's/throw MalformedText::notUtf8(.Text::foldPreserveCase.);/;/'

run_sabotage "undecoded HTML entities accepted as text" \
  src/php/Core/Text.php \
  's/throw MalformedText::undecodedEntities($entity\[0\]);/;/'

run_sabotage "combining marks no longer stripped (NFD text stops matching)" \
  src/php/Core/Text.php \
  '/p{Mn}/d'

run_sabotage "conventionne exception widened back to any eligible tenure" \
  src/php/Core/TenureClassifier.php \
  's/static fn (TenureSignal $s): bool => $s->tenure === Tenure::LLI,/static fn (TenureSignal $s): bool => $s->tenure->isEligible(),/'

run_sabotage "ambiguous uppercase acronym guessed instead of digested" \
  src/php/Core/TenureClassifier.php \
  's/return $ambiguousAt === null ? null : \[$ambiguousAt, false\];/return $ambiguousAt === null ? null : [$ambiguousAt, true];/'

# NOT SABOTAGED, and the reason is worth recording rather than quietly omitting.
# `if ($folded === '') { continue; }` in structuredFieldSignals() is defence in depth, not a
# load-bearing guard: Text::tokenPosition() already returns null for an empty haystack, so removing
# the check changes no behaviour and the suite correctly stays green. The BEHAVIOUR is covered by
# TenureClassifierTest::testEmptyStructuredFieldDoesNotFireTierOne(); it simply cannot be broken by
# deleting one of two redundant guards. Listing it as a sabotage would report a hole that is not one.

run_sabotage "tier 5 consulted even when higher tiers fired" \
  src/php/Core/TenureClassifier.php \
  's/$anyEvidence ? \[\] : $this->sourceDefaultSignals($source)/$this->sourceDefaultSignals($source)/'

run_sabotage "SOCIAL stops corroborating the excluded tenures" \
  src/php/Core/Tenure.php \
  's/return $other->isExcluded();/return false;/'

run_sabotage "tier tie-break ignores position (first table entry wins)" \
  src/php/Core/TenureClassifier.php \
  's/return $tierSignals\[0\];/return array_values($tierSignals)[count($tierSignals) - 1];/'

run_sabotage "'sans' negation lookbehind removed" \
  src/php/Core/TenureClassifier.php \
  's/&& $this->isPrecededBySans($folded, $position)/\&\& false/'

run_sabotage "conventionne exception removed (genuine LLI stock digests)" \
  src/php/Core/TenureClassifier.php \
  's/if (!$statesIntermediate) {/if (true) {/'

printf '\n  %d sabotage(s) detected, %d undetected\n\n' "$pass" "$fail"

[[ $fail -eq 0 ]]
