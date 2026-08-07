#!/usr/bin/env bash
# sabotage-check.sh — does the suite actually CATCH a regression?
#
# A green test run proves the code passes the tests. It does not prove the tests would notice if the
# code stopped working — and for these modules that distinction is the whole ballgame, because every
# failure mode here is SILENT. A classifier that quietly rejects everything looks exactly like a
# quiet rental market, and a classifier that quietly admits social housing looks exactly like a
# productive one until an application is wasted. The store is the same shape: a seen-set that stops
# persisting, a price history that stops recording, a run log that reports a dead source as calm —
# none of them raise anything, and all of them are indistinguishable from working.
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
# Every failure's LABEL, repeated in the summary. Two reviewers independently saw this script report
# undetected sabotages once and could not say which, because both had piped it through `tail` and the
# inline FAIL lines had scrolled away. A gate whose verdict cannot be reconstructed from its own last
# ten lines is a gate nobody can act on.
failed_labels=()

# Each entry: label :: file :: sed expression that breaks the guarantee
run_sabotage() {
  local label="$1" target="$2" expr="$3"

  rm -rf "$work/repo"
  mkdir -p "$work/repo"
  # src/ and tests/ are copied because the sabotage edits them. vendor/ is COPIED TOO, and that is
  # load-bearing rather than wasteful: composer's `autoload_psr4.php` computes its base directory
  # from its own location, so a symlinked vendor/ resolves PSR-4 back to the PRISTINE src/ and every
  # sabotage silently reports as undetected. A reviewer hit exactly that and got 0/144. Only the
  # runner is symlinked — it is a self-contained PHAR with no path assumptions.
  # Checked: an unnoticed copy failure makes the scratch suite fail for the wrong reason, and the
  # detection assertion below cannot tell that apart from a caught sabotage.
  # config/ JOINED THIS LIST on 2026-08-07 and it is not optional. The config layer's tests read
  # `config/criteria.json` and `config/sources.json` through a repo-root constant, so without it
  # every one of them ERRORS in the scratch copy — and an errored suite is a red suite, which this
  # harness cannot tell apart from a caught sabotage. Every sabotage would have reported `ok` while
  # proving nothing at all, which is precisely the failure the whole script exists to detect.
  if ! cp -a "$repo/src" "$repo/tests" "$repo/config" "$repo/phpunit.xml" "$repo/composer.json" "$work/repo/" \
    || ! cp -a "$repo/vendor" "$work/repo/vendor" \
    || ! ln -s "$repo/tools" "$work/repo/tools"; then
    printf '  \033[31mFAIL\033[0m %-58s (could not build the scratch copy)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  if ! sed -i "$expr" "$work/repo/$target"; then
    printf '  \033[31mFAIL\033[0m %-58s (sabotage could not be applied)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  # The sabotage must actually change the file, or we are testing nothing.
  if cmp -s "$repo/$target" "$work/repo/$target"; then
    printf '  \033[31mFAIL\033[0m %-58s (sabotage was a no-op — the pattern no longer matches)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  local out
  out="$(cd "$work/repo" && php tools/phpunit.phar --colors=never --do-not-cache-result 2>&1)"
  local rc=$?

  # A non-zero exit is NOT evidence of detection — a PHP parse error, a missing autoloader or a
  # failed `cd` produces one too, and asserting on `rc` alone would report those as successes.
  #
  # Nor is "PHPUnit reported errors" enough on its own, and that was the remaining hole: PHPUnit
  # turns an autoload-time SYNTAX error into N test errors, which match both greps below and would
  # print `ok`. Several sabotages here are line-deletes or pattern-pinned seds, so a refactor that
  # moves the targeted text can silently convert a sabotage into a syntax error — and the script
  # would then certify the guarantee as covered while the suite never exercised it. A green light
  # on the one check guarding §1.
  #
  # So: a parse/fatal error is never accepted, and the run must have actually executed tests.
  if grep -qE '(Parse error|Fatal error|syntax error, unexpected)' <<<"$out"; then
    printf '  \033[31mFAIL\033[0m %-58s (the sabotage produced a PHP parse error, so the suite never\n' "$label"
    printf '        ran — this proves nothing either way)\n'
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  if grep -qE '^(FAILURES!|ERRORS!|OK, but)' <<<"$out" \
    && grep -qE 'Failures: [1-9]|Errors: [1-9]' <<<"$out" \
    && grep -qE 'Tests: [1-9][0-9]{2,}' <<<"$out"; then
    printf '  \033[32mok\033[0m   %-58s (suite went red, as it must)\n' "$label"
    pass=$((pass + 1))
  elif [[ $rc -ne 0 ]]; then
    printf '  \033[31mFAIL\033[0m %-58s (suite exited %d but reported no assertion failure — the\n' "$label" "$rc"
    printf '        harness broke rather than the sabotage being caught)\n'
    printf '        %s\n' "$(tail -3 <<<"$out")"
    fail=$((fail + 1))
    failed_labels+=("$label")
  else
    printf '  \033[31mFAIL\033[0m %-58s (SUITE STAYED GREEN — this regression is undetected)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
  fi
}

printf '\n== sabotage-check: can the suite detect a broken classifier or store? ==\n\n'

# BASELINE FIRST, and this is not ceremony. Every sabotage below asserts "the suite went red". If
# the suite is ALREADY red — a missing autoloader, a syntax error, a half-applied edit — then every
# single sabotage reports success and the whole run is a green light that means nothing. That
# happened on 2026-08-06, and it is why this check exists before the loop rather than after it.
if ! (cd "$repo" && php tools/phpunit.phar --no-output --do-not-cache-result >/dev/null 2>&1); then
  printf '  \033[31mABORT\033[0m the suite is red BEFORE any sabotage — every result below would be a\n'
  printf '        false positive. Fix the suite first, then re-run.\n\n'
  exit 1
fi
printf '  baseline: suite is green — sabotage results are meaningful\n'

# BEFORE the work, not after it. A dirty tree is not fatal — checking uncommitted changes is a
# legitimate use — but every sabotage copies src/ and tests/ wholesale, so an edit landing mid-run
# silently changes what is under test. Warning at the END put it ~130 lines above the verdict, past
# the `tail` that everyone reads.
if [[ -n "$(cd "$repo" && git status --porcelain 2>/dev/null)" ]]; then
  printf '  \033[33mNOTE\033[0m the working tree is dirty; each sabotage copies it as-is, so an edit\n'
  printf '       landing mid-run changes what is under test. Results are advisory until it is clean.\n'
fi

printf '\n'

run_sabotage "PLS dropped from the excluded set" \
  src/php/Core/Tenure.php \
  's/^            self::PLS,$//'

run_sabotage "PLAI dropped from the excluded set" \
  src/php/Core/Tenure.php \
  's/^            self::PLAI,$//'

run_sabotage "conflict rule removed (eligible verdict no longer withholds)" \
  src/php/Core/TenureClassifier.php \
  's/if ($winner->tenure->isEligible()) {/if (false) {/'

run_sabotage "collocation guard removed (bare 'plus' becomes a social label)" \
  src/php/Core/TenureClassifier.php \
  's/if (!$inContext) {/if (false) {/'

run_sabotage "comparative suppression removed ('LOGEMENT PLUS GRAND' reads as financing)" \
  src/php/Core/TenureClassifier.php \
  's|^    private const string COMPARATIVE_TAIL = .*$|    private const string COMPARATIVE_TAIL = "zzzz-no-such-word";|'

# `reasons[]` is the product's only user-facing output (spec §5) and had exactly two assertions on it
# — non-empty, and no blank entry — so reverting the tier-2 reason to the TABLE LITERAL, which makes a
# notification quote a phrase the listing does not contain, left the whole suite green.
run_sabotage "tier-2 reason quotes the table literal instead of the matched text" \
  src/php/Core/TenureClassifier.php \
  "s|self::oneLine(\$matched)|\$hit['literal']|"

# The doubt FLOOR in a structured tenure field. Round 5 found the prose branch answering silence when
# no COLLOCATION noun sat beside the acronym — a §1 breach on the strongest rung of the ladder.
run_sabotage "field prose branch goes silent again (no collocation noun => no signal, no doubt)" \
  src/php/Core/TenureClassifier.php \
  's/$doubtAt = $this->firstNonComparativeOccurrence($cased, $acronym);/$doubtAt = null;/'

# Addressed to firstNonComparativeOccurrence() alone — `if (!$this->matches` also appears in
# isCodeList(), and a sabotage that broke both could be "detected" by a fixture for the other one.
run_sabotage "the comparative escape stops firing (the adverb becomes a doubt)" \
  src/php/Core/TenureClassifier.php \
  '/private function firstNonComparativeOccurrence/,$ s@if (!$this->matches@if (true || !$this->matches@'

# The title/description boundary. Two independent halves — the fold that preserves the newline, and
# the anchor that stops PCRE matching `$` in front of it. Breaking EITHER restores the leak.
run_sabotage "folding flattens the title/description newline again" \
  src/php/Core/Text.php \
  's|\[^\\S\\n\]+|\\s+|'

run_sabotage "conventionne adjacency anchor reverts to \$ (which matches before a final newline)" \
  src/php/Core/TenureClassifier.php \
  's|\*\\z/u|*$/u|'

# ROUND 6: the doubt floor on the PROSE surface, and the four consumers of the boundary rule.
run_sabotage "prose branch goes silent again (no collocation noun => no signal, no doubt)" \
  src/php/Core/TenureClassifier.php \
  '/\$hit = \$this->financingAcronymPosition/,$ s|\$doubtAt = \$this->firstNonComparativeOccurrence(\$cased, \$acronym, caseInsensitive: false);|$doubtAt = null;|'

run_sabotage "prose doubt floor goes case-INsensitive (digests the adverb too)" \
  src/php/Core/TenureClassifier.php \
  's|caseInsensitive: false|caseInsensitive: true|'

run_sabotage "a newline stops ending the financing phrase" \
  src/php/Core/TenureClassifier.php \
  's@\^\[^\\S\\n\]\*(\\n|\$)@^[^\\S\\n]*($)@'

# The comparative escape exists in TWO methods, and an earlier note here claimed a sabotage was
# unnecessary for "the comparative escape" as though there were one. That is true only of the copy
# inside financingAcronymPosition(), which sits below a phrase-end test that returns first — verified
# inert. The copy in firstNonComparativeOccurrence() has NO phrase-end test above it and is the sole
# guard on the doubt floor, so reverting it to `\s*` silently reopened the boundary hole with the
# suite green. One note stretched over two call sites; each now has its own answer.
run_sabotage "the doubt floor's comparative escape reads across the boundary" \
  src/php/Core/TenureClassifier.php \
  '/private function firstNonComparativeOccurrence/,$ s@\[^\\S\\n\]\*(?i:@\\s*(?i:@'

run_sabotage "the collocation separator spans the title/description newline" \
  src/php/Core/TenureClassifier.php \
  "s|(?:\[^\\\\S\\\\n\]\|\[\\\\/\\\\-,:;()\]){1,3}|[\\\\s\\\\/\\\\-,:;()]{1,3}|"

run_sabotage "an eligible label may be assembled across a multi-line FIELD value" \
  src/php/Core/TenureClassifier.php \
  "s|isset(\$hit\['matched'\])|false|"

run_sabotage "the field NAME stops being read for excluded vocabulary" \
  src/php/Core/TenureClassifier.php \
  's|$nameSignal = $this->excludedVocabularyIn((string) $name);|$nameSignal = null;|'

run_sabotage "the unrecognised-surface scan sees LABELS only again (PLUS goes blind)" \
  src/php/Core/TenureClassifier.php \
  's|foreach (array_keys(self::AMBIGUOUS_LABELS) as $acronym) {|foreach ([] as $acronym) {|'

run_sabotage "an unreadable field value is silently dropped instead of digested" \
  src/php/Core/TenureClassifier.php \
  's|if (!is_scalar($value) \&\& !$value instanceof \\Stringable \&\& $value !== null) {|if (false) {|'

run_sabotage "procedural surfaces are concatenated again (literals assemble across field joins)" \
  src/php/Core/TenureClassifier.php \
  's|$surfaces\[\] = $folded;|$surfaces[0] .= "\\n" . $folded;|'

run_sabotage "incidental surfaces refuse instead of decoding (one entity kills the listing)" \
  src/php/Core/Text.php \
  's|return self::fold(self::decodeEntities($raw));|return self::fold($raw);|'

# The §1 direction of the tolerant fold: decoding must RESTORE a token an entity split, not paper
# over it. The first implementation substituted a space and `PL&shy;AI` folded to `pl ai` — no
# match, no doubt, NOTIFIED. All three reviewers reproduced it independently.
run_sabotage "the tolerant fold substitutes a space instead of decoding (an entity hides PLAI)" \
  src/php/Core/Text.php \
  "s|\$next = html_entity_decode(\$decoded, ENT_QUOTES \| ENT_HTML5, 'UTF-8');|\$next = (string) preg_replace('/\&[a-zA-Z0-9#]{1,10};/', ' ', \$decoded);|"

# The identifier split must run on the INVISIBLE-STRIPPED form. On the merely-decoded string a soft
# hyphen reads as a separator, so `plai<U+00AD>sir` splits into the word `plai` and invents a match
# inside *plaisir* — the silent drop Text::hasToken() exists to prevent.
run_sabotage "the identifier split runs before invisibles are stripped (plaisir becomes plai)" \
  src/php/Core/TenureClassifier.php \
  's|$cased = Text::foldTolerantPreserveCase((string) $value);|$cased = Text::decodeEntities((string) $value);|'

run_sabotage "the vocabulary filters to isExcluded() again (numero unique goes blind)" \
  src/php/Core/TenureClassifier.php \
  '/private static function vocabularyKeys/,$ s|if ($tenure->isEligible()) {|if (!$tenure->isExcluded()) {|'

run_sabotage "identifier spellings stop being split (demandeLogementSocial goes blind)" \
  src/php/Core/TenureClassifier.php \
  "s|foreach (\[\$folded, \$split\] as \$haystack) {|foreach ([\$folded] as \$haystack) {|"

run_sabotage "separator-free key containment removed (numeroUnique goes blind)" \
  src/php/Core/TenureClassifier.php \
  's|foreach (self::vocabularyKeys() as $normalised => $literal) {|foreach ([] as $normalised => $literal) {|'

run_sabotage "the listing url/commune/postcode/externalId stop being read" \
  src/php/Core/TenureClassifier.php \
  "s|'postcode' => \$listing->postcode, 'externalId' => \$listing->externalId\] as \$what => \$text) {|'postcode' => null, 'externalId' => null] as \$what => \$text) {|"

run_sabotage "an unreadable surface is read as empty again (breakage becomes absence)" \
  src/php/Core/TenureClassifier.php \
  's|if ($folded === null) {|if (false) {|'
run_sabotage "sans negates across the title/description boundary again" \
  src/php/Core/TenureClassifier.php \
  "s|'/\\\\bsans\[^\\\\S\\\\n\]+\\\\z/u'|'/\\\\bsans\\\\s+\$/u'|"

run_sabotage "procedural tells stop reading structured fields" \
  src/php/Core/TenureClassifier.php \
  's|foreach ($this->proceduralSurfaces($listing) as $folded) {|foreach ([Text::fold($listing->text())] as $folded) {|'

run_sabotage "an excluded label in an unrecognised field is ignored again" \
  src/php/Core/TenureClassifier.php \
  's|$unknownFieldDoubt = $this->excludedVocabularyIn($value);|$unknownFieldDoubt = null;|'

run_sabotage "an eligible tell may be assembled across a phrase boundary" \
  src/php/Core/TenureClassifier.php \
  's|if (\$tenure->isEligible() \&\& str_contains(\$matched, "\\n")) {|if (false) {|'

run_sabotage "an eligible LABEL may be assembled across a phrase boundary" \
  src/php/Core/TenureClassifier.php \
  "s|\$hit\['tenure'\]->isEligible() && str_contains|false \&\& str_contains|"

run_sabotage "reasons[] stop being collapsed to one line" \
  src/php/Core/TenureClassifier.php \
  's|return trim((string) preg_replace(./\\s+/u., . ., \$fragment));|return $fragment;|'

# The two folded surfaces must stay byte-aligned: label positions come from fold(), the ambiguous
# acronym's from foldPreserveCase(), and the resolver compares them directly. mb_strtolower breaks
# that for 27 codepoints (İ, ẞ, the Kelvin sign …) — which is what this code did until round 4.
run_sabotage "fold() lowercases multibyte again (byte offsets diverge between the two surfaces)" \
  src/php/Core/Text.php \
  's/return strtolower(self::foldPreserveCase($raw));/return mb_strtolower(self::foldPreserveCase($raw), "UTF-8");/'

run_sabotage "word boundaries dropped (substring match: 'plaine' becomes PLAI)" \
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

# BOTH encoding guards at once, deliberately — and the reason is worth recording.
# The malformed-UTF-8 refusal is defence in depth: the `mb_check_encoding` gate at the top of
# foldPreserveCase() and the null-check on the preg results below it each catch the same inputs, so
# removing EITHER ONE leaves the suite correctly green. Sabotaging one alone would report an
# undetected regression that is nothing of the sort. What must be load-bearing is the pair, so the
# pair is what gets broken here.
run_sabotage "both encoding guards removed (malformed UTF-8 accepted again)" \
  src/php/Core/Text.php \
  's/!mb_check_encoding(/!true \&\& mb_check_encoding(/; s/if ($collapsed === null) {/if (false) {/'

run_sabotage "undecoded HTML entities accepted as text" \
  src/php/Core/Text.php \
  's/throw MalformedText::undecodedEntities($entity\[0\]);/;/'

run_sabotage "combining marks no longer stripped (NFD text stops matching)" \
  src/php/Core/Text.php \
  '/p{Mn}/d'

run_sabotage "conventionne exception widened back to any eligible tenure" \
  src/php/Core/TenureClassifier.php \
  's/if ($other->tenure !== Tenure::LLI) {/if (!$other->tenure->isEligible()) {/'

run_sabotage "ambiguous uppercase acronym guessed instead of digested" \
  src/php/Core/TenureClassifier.php \
  's/return $ambiguousAt === null ? null : \[$ambiguousAt, false\];/return $ambiguousAt === null ? null : [$ambiguousAt, true];/'

run_sabotage "French inflection dropped (labels matched exactly again)" \
  src/php/Core/Text.php \
  's/\$parts\[\] = preg_quote(\$word, .\/.) . .(?:es|e|s|x)?.;/$parts[] = preg_quote($word, "\/");/'

run_sabotage "the -al\/-aux branch dropped (logements sociaux stops matching)" \
  src/php/Core/Text.php \
  "s/} elseif (str_ends_with(\$word, 'al')) {/} elseif (false) {/"

run_sabotage "doubts compete positionally again (indecidable marker resolved as a tenure)" \
  src/php/Core/TenureClassifier.php \
  's/if ($s->tenure === Tenure::UNKNOWN) {/if (false) {/'

run_sabotage "doubts no longer withhold an otherwise-eligible verdict" \
  src/php/Core/TenureClassifier.php \
  's/if ($objections !== \[\] || $doubts !== \[\]) {/if ($objections !== []) {/'

run_sabotage "prose field values bypass the collocation guard again" \
  src/php/Core/TenureClassifier.php \
  's/preg_quote($acronym, .\/.)/mb_strtolower(preg_quote($acronym, "\/"))/'

# The adjacency test is the whole exception. Three separate ways to defeat it, because the first two
# versions of this code failed in three separate ways and each fix was written blind to the next.
# The `$between <= $s->position` half alone is not the guard — it only says the label ENDS before the
# word. Deleting the whitespace-only test is what makes any LLI anywhere in the text qualify a
# `conventionné`, which is v1 of this code and the shape that MATCHED a mixed résidence.
run_sabotage "conventionne exception unbounded again (any LLI anywhere deletes it)" \
  src/php/Core/TenureClassifier.php \
  's/^\( *\)&& $this->matches(.*substr($folded, $between, $s->position - $between))) {$/\1\&\& true) {/'

# NOTE: there is no "direction-aware" sabotage here. One was written, and it proved the explicit
# `$other->position > $s->position` skip was UNREACHABLE — `$between` is `position + length`, so a
# label starting after `conventionné` always ends after it and fails `$between <= $s->position`
# regardless. The dead clause was deleted rather than fixture-covered; direction is now asserted
# directly by TenureClassifierTest::testConventionneIsOnlyExcusedByALabelThatPrecedesIt().

run_sabotage "conventionne adjacency measures the table literal, not the matched text" \
  src/php/Core/TenureClassifier.php \
  's/$between = $other->position + $other->length;/$between = $other->position + strlen($other->evidence);/'

run_sabotage "invisible non-Cf characters no longer stripped" \
  src/php/Core/Text.php \
  "s/self::INVISIBLE/''/"

run_sabotage "numero unique alone becomes a determinate rejection again" \
  src/php/Core/TenureClassifier.php \
  "s/'numero unique' => Tenure::UNKNOWN,/'numero unique' => Tenure::SOCIAL,/"

# NOT SABOTAGED, and the reason is worth recording rather than quietly omitting.
# `if ($folded === '') { continue; }` in structuredFieldSignals() is defence in depth, not a
# load-bearing guard: Text::tokenPosition() already returns null for an empty haystack, so removing
# the check changes no behaviour and the suite correctly stays green. The BEHAVIOUR is covered by
# TenureClassifierTest::testEmptyStructuredFieldDoesNotFireTierOne(); it simply cannot be broken by
# deleting one of two redundant guards. Listing it as a sabotage would report a hole that is not one.

run_sabotage "tier 5 consulted even when higher tiers fired" \
  src/php/Core/TenureClassifier.php \
  's/array_filter($signals) === \[\] \&\& $doubts === \[\]/true/'

run_sabotage "SOCIAL stops corroborating the excluded tenures" \
  src/php/Core/Tenure.php \
  's/return $other->isExcluded();/return false;/'

run_sabotage "tier tie-break ignores position (first table entry wins)" \
  src/php/Core/TenureClassifier.php \
  's/return $tierSignals\[0\];/return array_values($tierSignals)[count($tierSignals) - 1];/'

run_sabotage "'sans' negation lookbehind removed" \
  src/php/Core/TenureClassifier.php \
  's/&& $this->isPrecededBySans($folded, $position)/\&\& false/'

run_sabotage "conventionne exception removed entirely (genuine LLI stock digests)" \
  src/php/Core/TenureClassifier.php \
  's/if ($s->tenure !== Tenure::CONVENTIONNE || $s->evidence !== .conventionne.) {/if (true) {/'

# ── The store ─────────────────────────────────────────────────────────────────────────────────────
#
# Same argument, different subsystem. The store's failure modes are silent in the direction that
# hurts most: a seen-set that stops persisting re-notifies the entire market at once, a price history
# that stops recording loses evidence that cannot be reconstructed (a listing only ever shows its
# CURRENT rent), and a run log that mis-derives health reports a broken selector as a quiet market —
# `CLAUDE.md` hard rule 2, the defect this whole project is shaped around.
#
# None of those raise an exception. Every one of them leaves the suite green unless a test is
# actually looking, so "the store suite passes" proves nothing on its own.

run_sabotage "unknown rent reads as a drop to zero (null treated as 0)" \
  src/php/Store/Store.php \
  's%($rentCc !== null && $previousRentCc !== null) ? $rentCc - $previousRentCc : null%($rentCc ?? 0) - ($previousRentCc ?? 0)%'

run_sabotage "last known rent erased when a source stops publishing it" \
  src/php/Store/Store.php \
  's%COALESCE(:rent, rent_cc)%:rent%'

run_sabotage "price history records unchanged rents (no longer changes-only)" \
  src/php/Store/Store.php \
  's%$rentCc !== null && $rentCc !== $chronoBefore%$rentCc !== null%'

run_sabotage "seen-set stops persisting (every run re-notifies everything)" \
  src/php/Store/Store.php \
  "s%'sqlite:' . \$path%'sqlite::memory:'%"

run_sabotage "notified flag never read (a digested listing counts as notified)" \
  src/php/Store/Store.php \
  "s%\$row !== false && \$row\['notified_at'\] !== null%true%"

run_sabotage "marking an unknown listing notified is a silent no-op" \
  src/php/Store/Store.php \
  's%if ($statement->rowCount() === 0) {%if (false) {%'

run_sabotage "dedup key no longer scoped to the source (two feeds collide on id 17)" \
  src/php/Store/Store.php \
  "s%return \$source . ':id:' . rawurlencode(\$externalId);%return ':id:' . rawurlencode(\$externalId);%"

run_sabotage "URL normalisation bypassed (a #fragment forks the identity)" \
  src/php/Store/Store.php \
  's%if ($parts === false || !isset($parts\[.host.\])) {%if (true) {%'

run_sabotage "URL path folded with the host (two distinct listings over-merge)" \
  src/php/Store/Store.php \
  's%return $rebuilt;%return strtolower($rebuilt);%'

run_sabotage "unparseable timestamp silently becomes the epoch" \
  src/php/Store/Store.php \
  's@throw new .InvalidArgumentException(sprintf(.horodatage ISO-8601 illisible : %s., $iso));@return 0;@'

run_sabotage "a database from a NEWER schema is operated on anyway" \
  src/php/Store/Store.php \
  's%if ($recorded > self::SCHEMA_VERSION) {%if (false) {%'

run_sabotage "a source that never ran is reported healthy" \
  src/php/Store/Store.php \
  's%status: SourceStatus::NEVER_RUN,%status: SourceStatus::OK,%'

run_sabotage "a failed last run no longer reports BROKEN" \
  src/php/Store/Store.php \
  's%if (!$lastOk) {%if (false) {%'

run_sabotage "a failed run extends the empty streak (failure read as 'nothing found')" \
  src/php/Store/Store.php \
  "s%(int) \$run\['ok'\] !== 1 || %%"

run_sabotage "empty-run threshold raised out of reach (a dead source stays OK)" \
  src/php/Store/Store.php \
  's%self::EMPTY_RUNS_BEFORE_BROKEN%99%'

run_sabotage "zero-baseline check removed (a genuinely quiet source is cried wolf on)" \
  src/php/Store/Store.php \
  's%if ($baseline > 0.0) {%if (true) {%'

run_sabotage "drop-below-mean warning threshold neutralised" \
  src/php/Store/Store.php \
  's%$rollingMean \* self::DROP_WARNING_RATIO%0.0%'

# ── The store, round two ──────────────────────────────────────────────────────────────────────────
#
# Everything below was added after a three-lens review panel found 25 defects in the first cut,
# including two P0s that were hard rule 2's own failure shape reintroduced by the module written to
# prevent it. Five of these guarantees were ALSO shown to be untested by a reviewer running this very
# script against them — the suite stayed green on all five. That is the argument for this file in one
# sentence: the tests looked thorough and were not, and only sabotage said so.

run_sabotage "unknown baseline treated as a zero baseline (broken-after-a-gap reads OK)" \
  src/php/Store/Store.php \
  's%$baseline = self::lastProductiveCount($runs, $streakStart);%$baseline = 0.0;%'

run_sabotage "trailing Z no longer normalised (parsed in the host timezone)" \
  src/php/Store/Store.php \
  's%$normalised = str_ends_with($iso, .Z.) ? substr($iso, 0, -1) . .+00:00. : $iso;%$normalised = $iso;%'

run_sabotage "timestamp round-trip check dropped (2026-02-30 rolls forward to 2 March)" \
  src/php/Store/Store.php \
  's%&& $parsed->format($format) === $normalised%%'

run_sabotage "Unicode trim reverts to ASCII trim (an nbsp id collapses the whole run)" \
  src/php/Store/Store.php \
  's%trim($value, ".*")%trim($value)%'

run_sabotage "no-information floor removed (id-less, url-less, title-less listings share one key)" \
  src/php/Store/Store.php \
  's%if ($url === .. && $title === ..) {%if (false) {%'

run_sabotage "previous rent read by write order, not chronological order" \
  src/php/Store/Store.php \
  's%WHERE dedup_key = :key AND at_epoch <= :epoch%WHERE dedup_key = :key%'

run_sabotage "changes-only invariant drops its forward check (duplicate consecutive rents)" \
  src/php/Store/Store.php \
  's%&& $rentCc !== $this->rentAfter($key, $epoch)%%'

run_sabotage "a stale sighting overwrites the current state" \
  src/php/Store/Store.php \
  's%} elseif (!$isCurrent) {%} elseif (false) {%'

run_sabotage "a partial re-parse erases the stored URL" \
  src/php/Store/Store.php \
  's%url          = COALESCE(NULLIF(:url, ....), url),%url          = :url,%'

run_sabotage "a partial re-parse erases the stored title" \
  src/php/Store/Store.php \
  's%title        = COALESCE(NULLIF(:title, ....), title),%title        = :title,%'

run_sabotage "price history ordered by insertion id rather than by time" \
  src/php/Store/Store.php \
  's%SELECT rent_cc FROM price_history WHERE dedup_key = :key ORDER BY at_epoch ASC, id ASC%SELECT rent_cc FROM price_history WHERE dedup_key = :key ORDER BY id ASC%'

# This slot used to hold a "NOT SABOTAGED" note claiming the run-log ordering was redundant because
# `recordRun()` refused out-of-order writes. A reviewer proved the excuse false on two counts, and
# the refusal itself turned out to be the worse bug — it deleted the runs it refused. Both the
# ordering and its opposite are now real, tested guarantees:

run_sabotage "run recency read from the timestamp again (one skewed clock hides every later run)" \
  src/php/Store/Store.php \
  's%WHERE source = :source ORDER BY id ASC%WHERE source = :source ORDER BY at_epoch ASC, id ASC%'

run_sabotage "never-productive source hides behind OK again" \
  src/php/Store/Store.php \
  's%if (!$everProduced && $successfulRuns >= self::EMPTY_RUNS_BEFORE_BROKEN%if (false%'

run_sabotage "a source failing half its fetches reads as healthy" \
  src/php/Store/Store.php \
  's%if ($runsInWindow >= self::MIN_RUNS_FOR_FLAKY%if (false%'

run_sabotage "adapter error text persisted and shown unredacted" \
  src/php/Store/Store.php \
  's%Redact::text($error)%$error%'

run_sabotage "only BROKEN alerts (NEVER_RUN and NEVER_PRODUCED go quiet)" \
  src/php/Core/SourceStatus.php \
  's%return $this !== self::OK;%return $this === self::BROKEN;%'

run_sabotage "the secret-name list is emptied (key= and token= pass through)" \
  src/php/Core/Redact.php \
  "s%'signature', 'token',%'zzz-no-such-name',%"

run_sabotage "mailbox masking neutralised (the IMAP address reaches the notification)" \
  src/php/Core/Redact.php \
  's%{2,}%{99,}%'

run_sabotage "redaction fails OPEN on a PCRE error (raw text returned)" \
  src/php/Core/Redact.php \
  's%return self::MASK;%return $message;%'


# ── The store, round three ────────────────────────────────────────────────────────────────────────
#
# Round 10's panel returned 30 findings against round 9's fixes, four of them P0 — every one a case
# where the repair was one step shallower than the defect. That is the pattern this file exists to
# interrupt, so each of those repairs now has a sabotage of its own.

run_sabotage "schema version stops tracking the schema (an older database opens and then throws)" \
  src/php/Store/Store.php \
  's%public const int SCHEMA_VERSION = 3;%public const int SCHEMA_VERSION = 1;%'

run_sabotage "the v1 upgrade never runs (seen_epoch column missing forever)" \
  src/php/Store/Store.php \
  's%if ($recorded < 2) {%if (false) {%'

run_sabotage "the upgrade forgets to re-stamp the version (every open re-migrates)" \
  src/php/Store/Store.php \
  "s%\$stamp->execute(\['value' => (string) self::SCHEMA_VERSION\]);%%"

run_sabotage "seen_epoch backfilled to zero (every stored listing reads as older than anything)" \
  src/php/Store/Store.php \
  's%$epoch = self::epoch((string) $row\[.last_seen_at.\]);%$epoch = 0;%'

run_sabotage "baseline falls back to the last SUCCESSFUL run again (one quiet day zeroes it)" \
  src/php/Store/Store.php \
  "s%&& (int) \$runs\[\$i\]\['item_count'\] > 0%%"

run_sabotage "the rolling window loses its upper bound (a 2036 run inflates every later mean)" \
  src/php/Store/Store.php \
  's%if ($at < $cutoff || $at > $reference) {%if ($at < $cutoff) {%'

run_sabotage "the window scan stops at the first out-of-range row instead of skipping it" \
  src/php/Store/Store.php \
  's%                continue;%                break;%'

run_sabotage "a superseded sighting counts as a price drop again" \
  src/php/Store/Store.php \
  's%isPriceDrop: $isCurrent && $delta !== null && $delta < 0,%isPriceDrop: $delta !== null \&\& $delta < 0,%'

run_sabotage "the rent comparison reads the changes-only history again (a real drop is swallowed)" \
  src/php/Store/Store.php \
  's%$previousRentCc = $isCurrent%$previousRentCc = false%'

run_sabotage "a stale sighting can no longer fill a missing URL" \
  src/php/Store/Store.php \
  's%SET url     = COALESCE(NULLIF(url, ....), NULLIF(:url, ....), url),%SET url     = url,%'

run_sabotage "a stale sighting overwrites the URL instead of filling it" \
  src/php/Store/Store.php \
  's%SET url     = COALESCE(NULLIF(url, ....), NULLIF(:url, ....), url),%SET url     = :url,%'

run_sabotage "the rollback is unguarded again (disk-full reports 'no active transaction')" \
  src/php/Store/Store.php \
  's%} catch (\\Throwable) {%} catch (\\LogicException) {%'

run_sabotage "STALE never fires (a source whose schedule stopped reads OK forever)" \
  src/php/Store/Store.php \
  's%if ($silentFor > self::ROLLING_WINDOW_DAYS \* 86400) {%if (false) {%'

run_sabotage "NEVER_PRODUCED loses its time floor (a source is accused 45 minutes after onboarding)" \
  src/php/Store/Store.php \
  's%&& $span >= self::MIN_SPAN_FOR_NEVER_PRODUCED%%'

run_sabotage "millisecond timestamps refused again (what every JSON API emits)" \
  src/php/Store/Store.php \
  's%str_pad($m\[1\], 6, .0.)%$m[1]%'

run_sabotage "IMAP LOGIN / POP3 PASS credentials pass through unmasked" \
  src/php/Core/Redact.php \
  "s%(LOGIN)%(zzz-no-such-verb)%"

run_sabotage "the Telegram bot token in a URL path passes through" \
  src/php/Core/Redact.php \
  's%bot%zzz-no-such-verb%g'

run_sabotage "the ntfy topic in a URL path passes through" \
  src/php/Core/Redact.php \
  's%(ntfy\\.\[A-Za-z0-9.\\-\]+/)%(zzz-no-such-host/)%'

run_sabotage "the RFR income figure passes through" \
  src/php/Core/Redact.php \
  's%(RFR)\[%(zzz-no-such-name)[%'

run_sabotage "'pass' dropped from the secret names (what imap_open emits)" \
  src/php/Core/Redact.php \
  "s%'mot de passe', 'motdepasse', 'pass', %%"

run_sabotage "a URL value is masked as if it were a secret (the failing endpoint is destroyed)" \
  src/php/Core/Redact.php \
  "s%(?!https?://)%%"

run_sabotage "ambiguous names accept ':' again ('Erreur auth: <url>' eats the url)" \
  src/php/Core/Redact.php \
  "s%'signature', 'token',%'signature', 'token', 'auth', 'key',%"


# ── The store, round four ─────────────────────────────────────────────────────────────────────────
#
# Round 11's panel returned 35 findings against round 10's fixes, four P0 — and one of them, again,
# was a repair that made things worse (the monotonic run log). It also caught something process-level
# that no sabotage can: the tree was NOT frozen while the panel ran, so three findings were being
# repaired concurrently and the round could not be scored. Freeze first.

run_sabotage "the changes-only guard reads the delta baseline again (duplicate rents)" \
  src/php/Store/Store.php \
  's%$rentCc !== $chronoBefore%$rentCc !== $previousRentCc%'

run_sabotage "recency ignores the clock (a late-committed run erases BROKEN)" \
  src/php/Store/Store.php \
  's%if ($nowIso !== null) {%if (false) {%'

run_sabotage "a future-stamped run is trusted even when a clock is available" \
  src/php/Store/Store.php \
  's%$at <= $now%true%'

run_sabotage "an unstamped legacy database is stamped current instead of upgraded" \
  src/php/Store/Store.php \
  's%$recorded = 1;%return;%'

run_sabotage "an undateable row brings the whole upgrade down again" \
  src/php/Store/Store.php \
  's%} catch (\\InvalidArgumentException) {%} catch (\\RangeException) {%'

run_sabotage "an empty-string URL is written over a known one" \
  src/php/Store/Store.php \
  's%url          = COALESCE(NULLIF(:url, ....), url),%url          = COALESCE(:url, url),%'

run_sabotage "WAL is never requested (two processes contend instead of sharing)" \
  src/php/Store/Store.php \
  "s%PRAGMA journal_mode = WAL%PRAGMA journal_mode = delete%"

run_sabotage "the busy timeout is dropped (a second writer fails instantly)" \
  src/php/Store/Store.php \
  "s%PRAGMA busy_timeout = %PRAGMA cache_size = %"

run_sabotage "fractional seconds narrow again (a Go feed's .1Z is refused)" \
  src/php/Store/Store.php \
  's%str_pad($m\[1\], 6, .0.)%$m[1]%'

run_sabotage "secret names stop matching inside an env-var (IMAP_PASSWORD leaks)" \
  src/php/Core/Redact.php \
  "s%^        \$before = .*%        \$before = '(?<![A-Za-z0-9_])';%"

run_sabotage "a single-quoted secret value stops being masked" \
  src/php/Core/Redact.php \
  "s%\$quoted = .*;%\$quoted = '\"[^\"]*\"';%"

run_sabotage "the mask can be re-masked (orphan brackets accumulate)" \
  src/php/Core/Redact.php \
  "s%(?!' . preg_quote(self::MASK, '~') . ')%%"

run_sabotage "the bare Telegram token pattern is removed" \
  src/php/Core/Redact.php \
  's%\\b.d{8,12}:\[A-Za-z0-9_\\-\]{30,}%zzz-no-such-token%'

run_sabotage "SASL AUTH PLAIN base64 passes through" \
  src/php/Core/Redact.php \
  's%(AUTH|AUTHENTICATE)%(zzz-no-such-verb)%'

run_sabotage "the padded-base64 blob pattern is removed" \
  src/php/Core/Redact.php \
  's%{16,}%{999,}%g'

run_sabotage "the LOGIN stoplist is bypassed (French prose is eaten)" \
  src/php/Core/Redact.php \
  's%(LOGIN)\[ .t\]+. . $notProse . .%(LOGIN)[ \\t]+\x27 . \x27%'


# ── The store, round five ─────────────────────────────────────────────────────────────────────────
#
# Round 12 returned 27 findings, four P0, and the two worst were BOTH repairs from round 11 that
# went one step too far: a clock-aware recency that discarded real runs when the CLOCK was wrong,
# and a Redact affix that turned every secret name into a substring match. Each now has a sabotage.

run_sabotage "STALE measured from the last-inserted row instead of the newest credible one" \
  src/php/Store/Store.php \
  "s%\$silentFor = \$now - max(\$credible);%\$silentFor = \$now - (int) \$last['at_epoch'];%"

run_sabotage "the secret-name affix matches mid-word again (project vocabulary is eaten)" \
  src/php/Core/Redact.php \
  "s%^        \$after = .*%        \$after = '';%"

run_sabotage "the base64 blob drops its padding requirement (identifiers are eaten)" \
  src/php/Core/Redact.php \
  's%\[A-Za-z0-9+/\]{16,}==%[A-Za-z0-9+/]{999,}==%'

run_sabotage "the whole-line base64 rule is removed (SMTP AUTH blobs pass through)" \
  src/php/Core/Redact.php \
  's%(?:\[A-Za-z0-9+/\]{4}){4,}%zzz-no-such-blob%'

run_sabotage "a value of pure punctuation is masked again (JSON parse errors are eaten)" \
  src/php/Core/Redact.php \
  "s%^        \$hasAlnum = .*%        \$hasAlnum = '';%"

run_sabotage "the base64 blob pattern eats long camelCase identifiers again" \
  src/php/Core/Redact.php \
  "s%(?=\[A-Za-z0-9+/\]\*\[0-9+/\])%%"

run_sabotage "record() reverts to a deferred transaction (the busy handler is skipped)" \
  src/php/Store/Store.php \
  "s%\$this->pdo->exec('BEGIN IMMEDIATE');%\$this->pdo->beginTransaction();%"

# NOT SABOTAGED, and recorded rather than quietly omitted: `upgradeFrom()`'s own `BEGIN IMMEDIATE`.
# The `record()` sabotage uses an unanchored sed that rewrites BOTH sites, so it goes red on
# `record()`'s half alone — reverting only the migration site leaves the suite green. Covering it
# honestly needs two processes racing to open the same v1 database, and the wait it would assert is
# BUSY_TIMEOUT_MS = five seconds, which is not a price this suite should pay on every run. The risk
# is bounded: a migration runs once per database, and losing that race fails loudly.

run_sabotage "journalMode() reports what was asked for, not what was given" \
  src/php/Store/Store.php \
  "s%\$journalMode = (string) \$mode->fetchColumn();%\$journalMode = 'wal';%"

run_sabotage "the span reverts to last-minus-first (a skewed first run disables the detector)" \
  src/php/Store/Store.php \
  "s%\$span = max(\$epochs) - min(\$epochs);%\$span = (int) \$last['at_epoch'] - (int) \$runs[0]['at_epoch'];%"

run_sabotage "fractional seconds are capped at six digits again (Go nanoseconds refused)" \
  src/php/Store/Store.php \
  's%(.d+)(?=%(\\d{1,6})(?=%'

# ── The store, round six ──────────────────────────────────────────────────────────────────────────
#
# Round 13 returned 23 findings, two P0, and both were round-12 repairs overshooting: the name affix
# went from matching too much to missing camelCase entirely, and the verb rule went from leaking
# all-letter passwords to leaking any line the adapter had wrapped with its own context.

run_sabotage "the camelCase left boundary is removed (clientSecret stops matching)" \
  src/php/Core/Redact.php \
  's%|(?-i:(?<=\[a-z0-9\])(?=\[A-Z\]))%%'

run_sabotage "the camelCase right boundary is removed (clientSecretKey stops matching)" \
  src/php/Core/Redact.php \
  's%|(?-i:(?=\[A-Z\]\[a-z\]))%%'

run_sabotage "the PASS stoplist is emptied (protocol responses are eaten)" \
  src/php/Core/Redact.php \
  "s%'command|commande|failed%'zzz-no-such-word|failed%"

run_sabotage "the ntfy topic drops back to the ambiguous list (a JSON body leaks it)" \
  src/php/Core/Redact.php \
  "s%^        'topic',\$%%"

run_sabotage "the counting window has no upper edge at all" \
  src/php/Store/Store.php \
  's%$edge = $now ?? (int) $runs\[array_key_last($runs)\]\[.at_epoch.\];%$edge = PHP_INT_MAX;%'

# ── The store, round seven ────────────────────────────────────────────────────────────────────────
#
# Round 14 returned 25 findings. Two were the LOGIN rule and the counting window overshooting AGAIN,
# in the opposite direction each time; the rest were guarantees this file was not watching. Every
# sub-part of the two camel boundaries now has its own case, because "remove the whole alternative"
# turned out to pass while three of its four pieces could be degraded silently.

run_sabotage "boundaryL loses its (?-i:) wrapper (bypass= is eaten)" \
  src/php/Core/Redact.php \
  's%(?-i:(?<=\[a-z0-9\])(?=\[A-Z\]))%(?<=[a-z0-9])(?=[A-Z])%'

run_sabotage "boundaryR loses its (?-i:) wrapper (keyword= and signal= are eaten)" \
  src/php/Core/Redact.php \
  's%(?-i:(?=\[A-Z\]\[a-z\]))%(?=[A-Z][a-z])%'

run_sabotage "boundaryR drops the real-hump requirement (PASSAGE= is eaten)" \
  src/php/Core/Redact.php \
  's%(?=\[A-Z\]\[a-z\])%(?=[A-Z])%'

run_sabotage "the AUTHENTICATE rule is removed entirely (a short SASL argument leaks)" \
  src/php/Core/Redact.php \
  's%(AUTHENTICATE)\[ .t\]+(\[A-Za-z0-9%(zzz-no-such-verb)[ \\t]+([A-Za-z0-9%'

run_sabotage "the whole-line base64 rule loses its CRLF tolerance" \
  src/php/Core/Redact.php \
  's%){4,}={0,2})\[ .t\]\*.r?\$~m%){4,}={0,2})[ \\t]*$~m%'

run_sabotage "the whole-line base64 rule drops its multiple-of-four requirement" \
  src/php/Core/Redact.php \
  's%(?:\[A-Za-z0-9+/\]{4}){4,}%[A-Za-z0-9+/]{16,}%'

run_sabotage "the whole-line base64 rule drops its mixed-case requirement" \
  src/php/Core/Redact.php \
  's%(?=\[A-Za-z0-9+/\]\*\[A-Z\])%%'

run_sabotage "the whole-line base64 rule loses its trace-prefix allowance" \
  src/php/Core/Redact.php \
  "s%'~(^|\[:>\]\[ .t\])%'~(^)%"

run_sabotage "the PASS stoplist loses its French half" \
  src/php/Core/Redact.php \
  "s%|erreur|unknown|inconnu%|zzz-no-word-1|unknown|zzz-no-word-2%"

run_sabotage "the stoplist loses its case-insensitivity" \
  src/php/Core/Redact.php \
  "s%(?i:' . self::PROSE_AFTER_VERB%(?:' . self::PROSE_AFTER_VERB%"

run_sabotage "the stoplist boundary reverts to \\b (accented entries go dead)" \
  src/php/Core/Redact.php \
  "s%')(?!\[A-Za-z0-9_\]))'%')..b)'%"

run_sabotage "the byte-fallback trim loses \\x85 and \\xAD" \
  src/php/Store/Store.php \
  's%.x85.xA0.xAD%\\xA0%'

run_sabotage "the counting window loses its upper edge (a future-stamped row alerts forever)" \
  src/php/Store/Store.php \
  's%if ($at < $cutoff || $at > $edge) {%if ($at < $cutoff) {%'

run_sabotage "the counting window ignores the clock (a stale writer hides failures)" \
  src/php/Store/Store.php \
  "s%\$edge = \$now ?? (int)%\$edge = (int)%"

# ── The config, adapter and criteria layers, added 2026-08-07 ─────────────────────
# Every failure mode below is SILENT in the same way the classifier's are: a listing that is
# wrongly rejected is indistinguishable from a listing that was never published.

run_sabotage "an unknown room count starts disqualifying (the prototype's bug)" \
  src/php/Core/CriteriaEngine.php \
  's%\$listing->rooms !== null \&\& \$listing->rooms <%(\$listing->rooms ?? 0) <%'

run_sabotage "an unknown surface starts disqualifying" \
  src/php/Core/CriteriaEngine.php \
  's%\$listing->surfaceM2 !== null \&\& \$listing->surfaceM2 <%(\$listing->surfaceM2 ?? 0.0) <%'

run_sabotage "charges comprises stops being derived from HC + charges" \
  src/php/Core/RawListing.php \
  's%if (\$this->rentHc !== null \&\& \$this->charges !== null) {%if (false) {%'

run_sabotage "the title-only exclusion starts matching the description too" \
  src/php/Config/Criteria.php \
  's%\$foldedTitle = Text::fold(\$title);%\$foldedTitle = Text::fold(\$title . "\\n" . \$description);%'

run_sabotage "a DIGEST verdict gets promoted into scoring" \
  src/php/Core/CriteriaEngine.php \
  's%if (\$classification->outcome === Outcome::DIGEST) {%if (false) {%'

run_sabotage "an excluded tenure stops being rejected by the criteria engine" \
  src/php/Core/CriteriaEngine.php \
  's%if (\$classification->outcome === Outcome::REJECT) {%if (false) {%'

run_sabotage "the score stops being clamped (a penalty can drive it negative)" \
  src/php/Core/CriteriaEngine.php \
  's%(int) round(max(0, min(\$total, \$earned)) \* 100 / \$total)%(int) round(\$earned * 100 / \$total)%'

run_sabotage "a listing with no location evidence stops being rejected" \
  src/php/Core/CriteriaEngine.php \
  's%if (!\$listing->hasLocationEvidence()) {%if (false) {%'

run_sabotage "the high-floor penalty starts firing on an UNMENTIONED lift" \
  src/php/Core/CriteriaEngine.php \
  's%\$listing->hasElevator === false \&\& \$listing->floor !== null%\$listing->hasElevator !== true \&\& \$listing->floor !== null%'

run_sabotage "an unknown config key becomes silently ignored" \
  src/php/Config/Reader.php \
  's%if (\$this->remaining === \[\]) {%if (true) {%'

run_sabotage "mixed_tenure stops being required in a source block" \
  src/php/Config/ConfigLoader.php \
  "s%if (!\\\$r->has('mixed_tenure')) {%if (false) {%"

run_sabotage "an excluded default_tenure becomes acceptable in config" \
  src/php/Config/ConfigLoader.php \
  's%if (\$defaultTenure->isExcluded()) {%if (false) {%'

run_sabotage "an enabled source may carry an unverified REMPLACER url" \
  src/php/Config/ConfigLoader.php \
  's%if (str_contains(\$url, self::UNVERIFIED_URL)) {%if (false) {%'

run_sabotage "any underscore-prefixed key becomes a comment again" \
  src/php/Config/Reader.php \
  "s%if (str_starts_with(\\\$name, '_') \&\& array_key_exists(substr(\\\$name, 1), \\\$data)) {%if (str_starts_with(\\\$name, '_')) {%"

run_sabotage "a missing items_path yields an empty list instead of throwing" \
  src/php/Adapters/FixtureSource.php \
  's%if (\$items === null) {%if (false) { \$items = [];%'

run_sabotage "an item with no stable id gets skipped instead of failing the run" \
  src/php/Adapters/ListingMapper.php \
  "s%if (\\\$ref === null || \\\$ref === '') {%if (false) {%"

run_sabotage "the thousands-separator rule is dropped (1.450 becomes 1)" \
  src/php/Adapters/Payload.php \
  's%if (\$trailing === 3) {%if (false) {%'

run_sabotage "an unrecognised boolean spelling becomes false instead of null" \
  src/php/Adapters/Payload.php \
  's%default => null,%default => false,%'

run_sabotage "zero and false start counting as absent values" \
  src/php/Adapters/Payload.php \
  's%if (\$value === null) {%if (!\$value) {%'

run_sabotage "an accented exclude pattern stops being refused" \
  src/php/Config/ConfigLoader.php \
  's%x80-%xFE-%'

run_sabotage "SourceError stops masking credentials before they are persisted" \
  src/php/Adapters/SourceError.php \
  's%Redact::text(\$message)%\$message%'

run_sabotage "a fixture path may escape the repo" \
  src/php/Adapters/FixtureSource.php \
  "s%if (str_contains(\\\$relative, '..')) {%if (false) {%"

# ── Dedup and the notification layer, added 2026-08-07 ────────────────────────────
# Over-merge HIDES a flat and under-merge only notifies twice, so the dedup sabotages attack the
# first direction. A notification that is computed and not delivered is worse than none at all
# (hard rule 2), which is what the notify sabotages attack.

run_sabotage "dedup merges on ONE corroborating fact (every T4 in a commune collapses)" \
  src/php/Core/Dedup.php \
  's%MIN_CORROBORATING_FACTS = 2%MIN_CORROBORATING_FACTS = 1%'

run_sabotage "dedup treats two UNKNOWN communes as the same commune" \
  src/php/Core/Dedup.php \
  's%if (\$communeA === null || \$communeB === null || \$communeA === .. || \$communeA !== \$communeB) {%if (false) {%'

run_sabotage "dedup stops respecting the track boundary (In'li merges with SeLoger)" \
  src/php/Core/Dedup.php \
  's%if (\$familyA !== \$familyB) {%if (false) {%'

run_sabotage "dedup ignores a stated rent disagreement" \
  src/php/Core/Dedup.php \
  's%RENT_TOLERANCE_RATIO = 0.03%RENT_TOLERANCE_RATIO = 9.99%'

run_sabotage "dedup ignores a stated surface disagreement" \
  src/php/Core/Dedup.php \
  's%SURFACE_TOLERANCE_RATIO = 0.03%SURFACE_TOLERANCE_RATIO = 9.99%'

run_sabotage "dedup starts fuzzy-matching two listings from the SAME source" \
  src/php/Core/Dedup.php \
  's%if (\$a->sourceName === \$b->sourceName) {%if (false) {%'

run_sabotage "a broken channel stops being reported (a send failure goes silent)" \
  src/php/Core/Notify/Notifier.php \
  's%\$failures\[\] = \$e;%%'

run_sabotage "delivery is reported as successful when no channel is usable" \
  src/php/Core/Notify/Notifier.php \
  's%return count(\$failures) < count(\$this->usable) \&\& \$this->usable !== \[\];%return true;%'

run_sabotage "the process starts with no usable channel at all" \
  src/php/Core/Notify/Notifier.php \
  's%if (\$this->usable !== \[\]) {%if (true) {%'

run_sabotage "console alone starts counting as a remote channel" \
  src/php/Core/Notify/Notifier.php \
  "s%if (\\\$channel->name() !== 'console') {%if (true) {%"

run_sabotage "a channel throwing an unexpected error escapes and aborts the run" \
  src/php/Core/Notify/Notifier.php \
  "s%Throwable %DomainException %"

run_sabotage "a rent drop crossing the ceiling loses its new-match announcement" \
  src/php/Core/Notify/Formatter.php \
  "s%(\\\$nowQualifies ? 'PASSE SOUS LE PLAFOND' : 'Baisse de loyer')%'Baisse de loyer'%"

run_sabotage "an hors-charges rent is shown as though it were charges comprises" \
  src/php/Core/Notify/Formatter.php \
  "s% € HC% € CC%"

run_sabotage "the literal-secret mask is dropped (a self-hosted ntfy topic leaks)" \
  src/php/Core/Redact.php \
  's%\$message = str_replace(\$literal, self::MASK, \$message);%%'

run_sabotage "a known duplicate is silently dropped instead of shown" \
  src/php/Core/Notify/Formatter.php \
  "s%if (\\\$duplicates !== \[\]) {%if (false) {%"

# ── The run loop, the CLI and schema v3, added 2026-08-07 ─────────────────────────
# The CLI is the only surface the developer sees, so a defect here is a defect in the product's
# entire output. Every case below was written against a specific ruling.

run_sabotage "run stops refusing on a freshly created database (Q36: a missing volume re-notifies everything)" \
  src/php/Cli/Scout.php \
  's%if (\$store->wasCreated() \&\& !\$seed) {%if (false) {%'

run_sabotage "--seed stops marking listings notified (the flood moves one run later)" \
  src/php/Cli/Pipeline.php \
  's%MARKED NOTIFIED WITHOUT SENDING%DISABLED%; s%^                \$this->store->markNotified(\$sighting->dedupKey, \$nowIso);$%%'

run_sabotage "the digest re-emits everything on every pass (Q34)" \
  src/php/Cli/Pipeline.php \
  's%if (!\$this->store->wasNotified(\$sighting->dedupKey)) {%if (true) {%'

run_sabotage "a match is marked notified even when no channel confirmed" \
  src/php/Cli/Pipeline.php \
  's%if (\$this->notifier->delivered(\$failures)) {%if (true) {%'

run_sabotage "a failed fetch stops being recorded as a failed run" \
  src/php/Cli/Pipeline.php \
  's%\$this->store->recordRun(\$source->name(), 0, false, \$e->getMessage(), \$nowIso, \$durationMs);%%'

run_sabotage "an adapter exception aborts the whole pass instead of one source" \
  src/php/Cli/Pipeline.php \
  's%catch (SourceError %catch (\\UnexpectedValueException %'

run_sabotage "item_count starts counting MATCHES instead of parsed items (Q30)" \
  src/php/Cli/Pipeline.php \
  's%\$this->store->recordRun(\$source->name(), count(\$listings), true%\$this->store->recordRun($source->name(), 0, true%'

run_sabotage "health alerting narrows back to BROKEN alone (Q29)" \
  src/php/Cli/Pipeline.php \
  's%!\$health->status->isAlerting()%\$health->status->value !== "broken"%'

run_sabotage "the alert cooldown is ignored (a broken source pushes every run)" \
  src/php/Store/Store.php \
  's%return (\$now - \$last) >= \$cooldownHours \* 3600;%return true;%'

run_sabotage "the cooldown keys on the source alone, so an escalation is swallowed" \
  src/php/Store/Store.php \
  "s%WHERE source = :source AND status = :status'%WHERE source = :source'%"

run_sabotage "a source that recovers sends no recovery notice" \
  src/php/Cli/Pipeline.php \
  's%if (\$this->store->clearAlerts(\$source->name())) {%if (false) {%'

run_sabotage "the tenure verdict stops being persisted (Q24)" \
  src/php/Cli/Pipeline.php \
  's%\$this->store->recordVerdict(%$this->store->schemaVersion(); $unused = array(%'

run_sabotage "run duration stops being measured (Q25: doctor's fourth column)" \
  src/php/Cli/Scout.php \
  's%\$durationMs = (int) round((hrtime(true) - \$startedAt) / 1_000_000);%$durationMs = null;%'

run_sabotage "doctor stops printing the journal mode (a silent WAL refusal)" \
  src/php/Cli/Scout.php \
  "s%. ', journal ' . \\\$store->journalMode() . ')')%. ')')%"

# RETIRED, with the reason recorded rather than the case quietly deleted: "doctor stops passing the
# clock to health()" could never go red, and a sabotage that cannot fail reports nothing for its whole
# life. `doctor` records its own successful run IMMEDIATELY BEFORE asking for health, so the clock and
# the newest stamp always agree and no verdict can differ — the argument is defensive there, not
# load-bearing. Where it IS load-bearing is any health read not preceded by a run, and StoreTest
# covers that directly. Do not re-add this case without first making doctor's output depend on it.

run_sabotage "the scraping opt-in gate is removed (hard rule 4 / Q26)" \
  src/php/Cli/Scout.php \
  's%if (\$definition->requiresScrapingOptIn() \&\& !\$this->scrapingAllowed()) {%if (false) {%'

run_sabotage "an unknown notification channel name is silently dropped" \
  src/php/Cli/Scout.php \
  's%if (\$channel === null) {%if (false) {%'

run_sabotage "the freshness bonus is given to every listing forever" \
  src/php/Cli/Pipeline.php \
  's%\$this->ageSeconds(\$sighting->dedupKey, \$nowIso)%null%'

# ── The network adapters, added 2026-08-07 ────────────────────────────────────────
# The transport is testable even though no endpoint is verified: hard rule 1 governs the URL, not
# the adapter. Every case below breaks a guarantee that is otherwise silent.

run_sabotage "robots.txt stops being consulted before a fetch (hard rule 5)" \
  src/php/Adapters/HttpJsonSource.php \
  's%if (\$this->robots !== null \&\& !\$this->robots->allows(Robots::pathOf(\$url))) {%if (false) {%'

run_sabotage "an unreadable robots.txt starts allowing everything (fails OPEN)" \
  src/php/Adapters/Http/Robots.php \
  's%if (!\$this->parsed) {\n            return false;%if (false) {%'

run_sabotage "an empty Disallow starts meaning disallow-everything" \
  src/php/Adapters/Http/Robots.php \
  "s%if (\\\$value !== '') {%if (true) { \\\$value = \\\$value ?: '/';%"

run_sabotage "a non-2xx response becomes an empty result instead of a failure" \
  src/php/Adapters/HttpJsonSource.php \
  's%if (!\$response->isSuccess()) {%if (false) {%'

run_sabotage "a moved items_path yields an empty list instead of throwing" \
  src/php/Adapters/HttpJsonSource.php \
  's%if (\$items === null) {%if (false) { $items = [];%'

run_sabotage "the REMPLACER guard is removed from the adapter itself" \
  src/php/Adapters/HttpJsonSource.php \
  "s%if (str_contains(\\\$url, 'REMPLACER')) {%if (false) {%"

run_sabotage "the honest User-Agent is dropped for a browser disguise (hard rule 5)" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%USER_AGENT = 'rent-watch%USER_AGENT = 'Mozilla/5.0 rent-watch%"

run_sabotage "ISO-8859-1 stops being read as CP1252 (the euro sign vanishes)" \
  src/php/Adapters/Mail/EmailMessage.php \
  "s%? 'CP1252'%? 'ISO-8859-1'%"

run_sabotage "folded header continuation lines stop being joined" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%\$value .= . . . trim(\$line);%%'

run_sabotage "the text/plain part stops being preferred over HTML" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%return \$plain ?? \$html ?? ..;%return $html ?? $plain ?? "";%'

run_sabotage "block tags stop becoming newlines when HTML is stripped" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%~</(p|div|br|li|tr|h\[1-6\]|td)\\\\s\*>|<br\\\\s\*/?>~i%~<XXNOMATCHXX>~i%'

run_sabotage "tracking parameters stop being stripped from an alert link id" \
  src/php/Adapters/EmailAlertSource.php \
  's%return \$scheme . .://. . \$host . \$path;%return $link;%'

run_sabotage "unsubscribe links start becoming listings" \
  src/php/Adapters/EmailAlertSource.php \
  's%if (stripos(\$link, \$noise) !== false) {%if (false) {%'

run_sabotage "a mailbox from the wrong sender stops being filtered out" \
  src/php/Adapters/EmailAlertSource.php \
  's%if (!\$this->isFrom(\$message)) {%if (false) {%'

run_sabotage "a mailbox failure becomes an empty list instead of a source failure" \
  src/php/Adapters/Mail/FileMailbox.php \
  's%if (!is_dir(\$this->directory)) {%if (false) {%'

run_sabotage "the rent plausibility band is removed (a postcode parses as a rent)" \
  src/php/Adapters/EmailAlertSource.php \
  's%\$value >= 200 \&\& \$value <= 20000%$value !== null%'

run_sabotage "SMTP permits plaintext credentials to a remote host" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%if (\\\$this->security === 'none' \&\& !self::isLoopback(\\\$this->host)) {%if (false) {%"

run_sabotage "SMTP continues without STARTTLS when the server does not offer it" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%if (stripos(\\\$capabilities, 'STARTTLS') === false) {%if (false) {%"

run_sabotage "SMTP stops masking the base64 form of the password" \
  src/php/Core/Notify/SmtpTransport.php \
  's%\$out\[\] = base64_encode(\$value);%%'

printf '\n  %d sabotage(s) detected, %d undetected\n' "$pass" "$fail"

if (( fail > 0 )); then
  printf '\n  undetected or unapplied:\n'
  printf '    - %s\n' "${failed_labels[@]}"
fi

printf '\n'

[[ $fail -eq 0 ]]
