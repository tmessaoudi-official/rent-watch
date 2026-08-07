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
  else
    printf '  \033[31mFAIL\033[0m %-58s (SUITE STAYED GREEN — this regression is undetected)\n' "$label"
    fail=$((fail + 1))
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
printf '  baseline: suite is green — sabotage results are meaningful\n\n'

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
  's%$rentCc !== null && $rentCc !== $previousRentCc%$rentCc !== null%'

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

run_sabotage "a database from a newer schema is operated on anyway" \
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
  's%if ($baseline !== null && $baseline > 0.0) {%if (true) {%'

run_sabotage "drop-below-mean warning threshold neutralised" \
  src/php/Store/Store.php \
  's%$rollingMean \* self::DROP_WARNING_RATIO%0.0%'

printf '\n  %d sabotage(s) detected, %d undetected\n\n' "$pass" "$fail"

[[ $fail -eq 0 ]]
