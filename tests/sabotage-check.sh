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

# An optional regex over LABELS, so a newly-added case can be proven red on its own instead of
# behind a two-hour full run. It skips silently rather than reporting, because a filtered run is a
# development aid and must never be mistakable for the ledger: the summary counts only what ran, and
# `skipped` is printed at the end whenever the filter was in play. CI never sets it.
_filter="${SABOTAGE_FILTER:-}"

# Per case, not for the whole ledger — see the call site for what it is defending against.
readonly SUITE_TIMEOUT_SECONDS="${SABOTAGE_SUITE_TIMEOUT:-300}"
skipped=0

# Each entry: label :: file :: sed expression that breaks the guarantee
run_sabotage() {
  local label="$1" target="$2" expr="$3"

  if [[ -n "$_filter" ]] && ! grep -qE -- "$_filter" <<<"$label"; then
    skipped=$((skipped + 1))
    return
  fi

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
  # NO `--do-not-cache-result`: it disables the result cache, which `executionOrder="defects"` in
  # phpunit.xml requires, so PHPUnit raises a runner warning and `failOnWarning="true"` turns a
  # perfectly green suite red. Cache isolation between cases needs no flag — `$work/repo` is
  # rm -rf'd and rebuilt above for every sabotage, so the cache PHPUnit writes under it dies with it.
  # `timeout`, because a sabotage can make the suite BLOCK rather than fail, and a gate that stalls
  # silently is worse than one that reports a failure. Observed on 2026-08-19: disabling the Q36
  # empty-database guard let `scout run --watch` enter its real fifteen-minute loop inside a test
  # that expected the run to be refused, and this script sat on its FIRST case for eleven minutes
  # printing nothing. The test-side fix is a bounded loop; this is the harness refusing to be held
  # hostage by the next one. The full suite runs in ~25 s, so five minutes is not a tight budget.
  out="$(cd "$work/repo" && timeout "$SUITE_TIMEOUT_SECONDS" php tools/phpunit.phar --colors=never 2>&1)"
  local rc=$?

  # 124 is `timeout`'s own verdict. It is NOT detection: the suite never finished, so it never said
  # anything about the guarantee. Loud, and counted as a failure, because an inconclusive case in a
  # gate that certifies §1 must not read as a pass.
  if [[ $rc -eq 124 ]]; then
    printf '  \033[31mFAIL\033[0m %-58s (the suite did not terminate within %ss — inconclusive,\n' "$label" "$SUITE_TIMEOUT_SECONDS"
    printf '        and a hang is not a detection)\n'
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

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
#
# The flags here are load-bearing in the other direction too: this gate must not be reddened by its
# OWN invocation. `--do-not-cache-result` was removed on 2026-08-19 for exactly that — see the note
# at the per-sabotage run above, and the satisfiability check in tests/test-ci-workflow.sh that now
# pins it. `--no-output` is kept: it is current in PHPUnit 13 and suppresses the progress dots.
if ! (cd "$repo" && php tools/phpunit.phar --no-output >/dev/null 2>&1); then
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
  's/if ($tenure->isEligible()$/if (false \&\& $tenure->isEligible()/'

# The gate's THIRD term, added 2026-08-23 when In'li turned out to publish PLS. Dropping it makes a
# weakly-labelled listing on a mixed source match before its detail page has ever been read — which
# is the disarmed state In'li shipped in, now reachable by deleting four words instead of by
# believing a source's own description of itself.
run_sabotage "the fail-closed rule stops requiring the detail page to have been read" \
  src/php/Core/TenureClassifier.php \
  's/\&\& !$detailRead) {/) {/' \
  's/$source->mixedTenure \&\& !$detailRead ? Outcome::DIGEST/$source->mixedTenure \&\& false ? Outcome::DIGEST/'

# And its mirror: hydration must not become a licence. If `detailRead` were allowed to short-circuit
# the EXCLUSION rules rather than only the source-default floor, reading a page would turn an
# explicit PLS into a match — the exact inversion §1 exists to prevent.
run_sabotage "reading the detail page licenses an excluded listing" \
  src/php/Core/TenureClassifier.php \
  's/if ($tenure->isExcluded()) {/if ($tenure->isExcluded() \&\& !$detailRead) {/'

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
  's%public const int SCHEMA_VERSION = [0-9]\+;%public const int SCHEMA_VERSION = 1;%'

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

# Restores the pre-2026-08-19 parser: strip every non-digit, then read what is left as ONE number.
# It reads as a tidy simplification and it silently FUSES two quantities into one — `55,32 m2`
# became 55322 m², `3 pièces · 55.32 m²` became 355.32, `Réf 12 — 1 450 €` became 121450. Every
# fused value is plausible as a rent, a surface or a room count, so nothing downstream can reject
# it and the criteria engine simply scores an invented number. Found against a real In'li capture.
#
# NOTE the anchor: the token regex itself is unmatchable from a BRE without a pile of escapes (`\d`
# has no BRE meaning and `[` opens a bracket expression), and an over-escaped pattern silently
# matches nothing — which is a sabotage that reports `ok` while testing NOTHING. Both first attempts
# here did exactly that and were caught by the harness's own `cmp -s` no-op guard. Anchor on the
# plain assignment underneath instead; it says the same thing and cannot rot into a no-op.
run_sabotage "a unit's own digit fuses into the number again (55,32 m2 -> 55322)" \
  src/php/Adapters/Payload.php \
  's%\$raw = \$token\[0\];%\$raw = preg_replace("~[^0-9,.-]~u", "", \$raw) ?? "";%'

# The other half: the token is found, but the LAST one wins instead of the first. Reads as an
# equally arbitrary choice and is worse — `3 pièces · 55.32 m²` yields 55 rooms, and a single-number
# string (every rent this project has ever parsed) is unaffected, so the change looks harmless in
# every test that does not deliberately put two quantities in one string.
run_sabotage "the LAST numeric token wins instead of the first" \
  src/php/Adapters/Payload.php \
  's%\$raw = \$token\[0\];%preg_match_all("~-?[0-9][0-9.,]*[0-9]|-?[0-9]~u", \$raw, \$all); \$raw = end(\$all[0]);%'

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

# The Q36 empty-database guard USED to be tested here, against `Store::wasCreated()`. That guard was
# replaced on 2026-08-19 by `Store::isSeenSetEmpty()` — the old one asked whether `open()` had created
# the file, which any earlier command that merely opened the database answered away. The case is not
# re-pointed here because its replacement already exists, written against the current code and against
# both halves of the ruling: see "the empty-database guard stops firing", "--seed no longer gets past
# the empty-database guard" and "the seen-set emptiness answer comes from the process, not the rows"
# in the Q36 block below. Two cases for one guarantee is not twice the coverage; it is one case that
# nobody notices has gone stale. This one HAD gone stale and reported `sabotage was a no-op` in the
# 2026-08-19 full ledger — which is the harness working, and the reason it is removed rather than left.

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
  's%if (!\$this->robots->allows(Robots::pathOf(\$url))) {%if (false) {%'

run_sabotage "an unreadable robots.txt starts allowing everything (fails OPEN)" \
  src/php/Adapters/Http/Robots.php \
  's%if (!\$this->parsed) {%if (false) {%'

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

run_sabotage "the honest User-Agent constant is BYPASSED at the wiring point" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%CURLOPT_USERAGENT => self::USER_AGENT,%CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0)',%"

run_sabotage "a request header is allowed to override the honest User-Agent" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%if (strtolower(\\\$name) === 'user-agent') {%if (false) {%"

run_sabotage "config regains the power to disguise a source's User-Agent" \
  src/php/Config/ConfigLoader.php \
  "s%if (strtolower((string) \\\$headerName) === 'user-agent') {%if (false) {%"

run_sabotage "a colon-smuggled header NAME slips past the funnel's token check" \
  src/php/Adapters/Http/CurlHttpClient.php \
  's%if (preg_match(self::HEADER_NAME_TOKEN, \$name) !== 1) {%if (false) {%'

run_sabotage "a colon-smuggled header NAME slips past config validation" \
  src/php/Config/ConfigLoader.php \
  's%if (preg_match(self::HEADER_NAME_TOKEN, (string) \$headerName) !== 1) {%if (false) {%'

run_sabotage "a line break in a header VALUE reaches the wire (header injection)" \
  src/php/Adapters/Http/CurlHttpClient.php \
  's%if (preg_match(.~\[\\r\\n\]~., (string) \$value) === 1) {%if (false) {%'

run_sabotage "a line break in a config header VALUE passes validation" \
  src/php/Config/ConfigLoader.php \
  's%if (preg_match(.~\[\\r\\n\]~., \$headerValue) === 1) {%if (false) {%'

run_sabotage "the funnel token anchor stops rejecting a trailing newline in a name" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%+\$/D';%+\$/';%"

run_sabotage "config's token anchor stops rejecting a trailing newline in a name" \
  src/php/Config/ConfigLoader.php \
  "s%+\$/D';%+\$/';%"

run_sabotage "the ntfy Click url stops being sanitised (header injection from a listing)" \
  src/php/Core/Notify/NtfyChannel.php \
  's%.Click: . . self::headerSafe(\$n->url)%"Click: " . $n->url%'

run_sabotage "the email Subject stops being header-safed (Bcc injection from a listing)" \
  src/php/Core/Notify/EmailChannel.php \
  's%\$subject = self::headerSafe(\$this->subjectPrefix . . . . \$n->title);%$subject = $this->subjectPrefix . " " . $n->title;%'

run_sabotage "an IMAP argument stops refusing an embedded CR/LF" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (preg_match(.~\[\\r\\n\]~., \$value) === 1) {%if (false) {%'

run_sabotage "the ntfy Title stops being header-safed (injection from a listing title)" \
  src/php/Core/Notify/NtfyChannel.php \
  's%.Title: . . self::headerSafe(\$n->title)%"Title: " . $n->title%'

run_sabotage "the email sender check loses its D anchor (a trailing newline passes)" \
  src/php/Core/Notify/EmailChannel.php \
  "s%@\[^@\\\\s\]+\$~D%@[^@\\\\s]+\$~%"

run_sabotage "the shared transport CR/LF guard stops refusing (all mail transports)" \
  src/php/Core/Notify/Headers.php \
  's%if (preg_match(.~\[\\r\\n\]~., \$value) === 1) {%if (false) {%'

run_sabotage "SmtpTransport stops calling the shared CR/LF guard" \
  src/php/Core/Notify/SmtpTransport.php \
  's%Headers::assertNoCrlf(.recipient., \$to);%%'

run_sabotage "SendmailTransport stops calling the shared CR/LF guard" \
  src/php/Core/Notify/SendmailTransport.php \
  's%Headers::assertNoCrlf(.header . . \$name, (string) \$value);%%'

run_sabotage "FileTransport stops calling the shared CR/LF guard" \
  src/php/Core/Notify/FileTransport.php \
  's%Headers::assertNoCrlf(.header . . \$name, (string) \$value);%%'

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
  's%(p|div|br|li|tr|h%(XX|div|br|li|tr|h%'

run_sabotage "the closing </br> form drops out of the block-tag alternation" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%|br|li%|li%'

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

run_sabotage "only the band's 200 FLOOR is removed (an agency fee parses as a rent)" \
  src/php/Adapters/EmailAlertSource.php \
  's%\$value >= 200 \&\& %%'

run_sabotage "only the band's 20000 CEILING is removed (a sale price parses as a rent)" \
  src/php/Adapters/EmailAlertSource.php \
  's% \&\& \$value <= 20000%%'

run_sabotage "SMTP permits plaintext credentials to a remote host" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%if (\\\$this->security === 'none' \&\& !self::isLoopback(\\\$this->host)) {%if (false) {%"

run_sabotage "SMTP continues without STARTTLS when the server does not offer it" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%if (stripos(\\\$capabilities, 'STARTTLS') === false) {%if (false) {%"

run_sabotage "SMTP stops masking the base64 form of the password" \
  src/php/Core/Notify/SmtpTransport.php \
  's%\$out\[\] = base64_encode(\$value);%%'

run_sabotage "a body line starting with a dot stops being stuffed (RFC 5321)" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%'\\.\\.'%'.'%"

# ── Q37 pacing (hard rule 5) ─────────────────────────────────────────────────────────────────────
# Hard rule 5 forbids CAPTCHA solving, proxy rotation and fingerprint spoofing, which leaves polite
# rate limiting as the ENTIRE strategy for not being blocked. Every failure below is silent in the
# worst way this project has: a banned IP presents as every source going quiet at once, which is
# indistinguishable from a slow rental market — the exact shape hard rule 2 exists to prevent.

run_sabotage "the gap between two requests to the same host is dropped" \
  src/php/Core/Pacer.php \
  's%public const float SAME_HOST_GAP_SECONDS = 60.0;%public const float SAME_HOST_GAP_SECONDS = 0.0;%'

run_sabotage "the gap between requests to distinct hosts is dropped" \
  src/php/Core/Pacer.php \
  's%public const float DISTINCT_HOST_GAP_SECONDS = 5.0;%public const float DISTINCT_HOST_GAP_SECONDS = 0.0;%'

run_sabotage "the same-host window is only enforced against the LAST request" \
  src/php/Core/Pacer.php \
  's%if (isset(\$this->lastByHost\[\$key\])) {%if (false) {%'

run_sabotage "the poll cadence collapses to a tight loop" \
  src/php/Core/Pacer.php \
  's%public const int PASS_INTERVAL_SECONDS = 900;%public const int PASS_INTERVAL_SECONDS = 0;%'

run_sabotage "the jitter band is widened past the ruling" \
  src/php/Core/Pacer.php \
  's%public const int JITTER_SECONDS = 300;%public const int JITTER_SECONDS = 600;%'

run_sabotage "a fast pass is allowed to immediately start another" \
  src/php/Core/Pacer.php \
  's%public const float MIN_SECONDS_BETWEEN_PASSES = 60.0;%public const float MIN_SECONDS_BETWEEN_PASSES = 0.0;%'

run_sabotage "the host is compared case-sensitively (one site becomes two)" \
  src/php/Core/Pacer.php \
  's%\$key = strtolower(\$host);%\$key = \$host;%'

run_sabotage "the source order stops being shuffled each pass" \
  src/php/Core/Pacer.php \
  's%\$j = (\$this->rand)(0, \$i);%\$j = \$i;%'

# Records the slot on the way out instead of skipping it, rather than deleting the guard: dropping
# the guard would reach `strtolower(null)` and the suite would go red on a PHP deprecation rather
# than on the assertion, which proves the runtime noticed and nothing about the test.
run_sabotage "a hostless source consumes the distinct-host slot" \
  src/php/Core/Pacer.php \
  's%if (\$host === null || \$host === '"''"') {%if ($host === null || $host === '"''"') { $this->lastRequestAt = ($this->clock)(); return; } if (false) {%'

run_sabotage "the pacer records the time it INTENDED to wait, not the clock" \
  src/php/Core/Pacer.php \
  's%\$issuedAt = (\$this->clock)();%\$issuedAt = \$readyAt;%'

run_sabotage "the decorator does not pace at all (every request goes out unthrottled)" \
  src/php/Adapters/PacedSource.php \
  's%\$this->pacer->beforeFetch(\$this->inner->host());%%'

# A DIFFERENT regression from the one above, and the more plausible of the two: the pacing is all
# still there, it just lands on the wrong side of the request. Such a decorator fires its first two
# requests back to back and only then starts behaving, so a short pass is never throttled at all.
# The `finally` leaves the original `return` below unreachable, which PHP accepts.
run_sabotage "the decorator waits AFTER the request instead of before it" \
  src/php/Adapters/PacedSource.php \
  's%\$this->pacer->beforeFetch(\$this->inner->host());%try { return $this->inner->fetch(); } finally { $this->pacer->beforeFetch($this->inner->host()); }%'

run_sabotage "the decorator swallows a source failure into an empty list (rule 3)" \
  src/php/Adapters/PacedSource.php \
  's%return \$this->inner->fetch();%try { return \$this->inner->fetch(); } catch (\\Throwable) { return []; }%'

run_sabotage "wrapAll stops sharing one pacer (each source gets a private window)" \
  src/php/Adapters/PacedSource.php \
  's%static fn (Source \$s): Source => new self(\$s, \$pacer),%static fn (Source $s): Source => new self($s, clone $pacer),%'

run_sabotage "PacedSource::health drops the clock, so STALE can never fire" \
  src/php/Adapters/PacedSource.php \
  's%return \$this->inner->health(\$nowIso);%return \$this->inner->health();%'

# Narrows the caught type instead of rethrowing: `\LogicException` is a real class, so the file still
# parses, and every exception the loop is meant to survive (`SourceError`, `\RuntimeException`) now
# escapes `run()`. NOTE the single backslashes — `\\` in a BRE is one literal backslash, and an
# earlier `\\\\` here matched two, making this whole case a silent no-op.
run_sabotage "a throwing pass kills the watch loop" \
  src/php/Cli/WatchLoop.php \
  's%} catch (\\Exception \$e) {%} catch (\\LogicException $e) {%'

run_sabotage "a failing pass is survived in SILENCE (nothing is reported)" \
  src/php/Cli/WatchLoop.php \
  's%(\$this->onError)(\$e);%%'

# Only the `stopping` half is degraded, and the `$maxPasses` half is deliberately left standing.
#
# Blanking the whole condition to `if (false)` also disables the bound `RENT_WATCH_MAX_PASSES` relies
# on, and that bound is the ONLY thing stopping `ScoutTest`'s bounded `--watch` tests from entering a
# real `betweenPasses()` wait — 600 to 1200 seconds of genuine sleep. The suite then does not go red,
# it BLOCKS: measured on 2026-08-19, the full ledger reported this case as
# `the suite did not terminate within 300s — inconclusive`. A sabotage that takes out the test
# harness's own anti-hang seam along with the guarantee is measuring the seam, not the guarantee.
#
# Degrading only `$this->stopping` reproduces exactly the regression the case is named for — a stop
# request no longer interrupting the loop, so the process naps for a quarter of an hour before
# exiting — while leaving every bounded test bounded. Verified: the suite goes red in 16 s on
# `WatchLoopTest::testStopDoesNotSleepBeforeExiting`, "exiting must be prompt, not after a
# fifteen-minute nap", with the trailing 600.0 s sleep printed in the diff.
run_sabotage "the loop stops mid-pass instead of finishing the pass in flight" \
  src/php/Cli/WatchLoop.php \
  's%if (\$this->stopping || (\$maxPasses !== null \&\& \$completed >= \$maxPasses)) {%if (\$maxPasses !== null \&\& \$completed >= \$maxPasses) {%'

run_sabotage "the inter-pass wait stops being interruptible by a signal" \
  src/php/Cli/WatchLoop.php \
  's%if (\$shouldStop()) {%if (false) {%'

run_sabotage "the wait is served as one long sleep, ignoring signals for 20 min" \
  src/php/Cli/WatchLoop.php \
  's%\$slice = min(self::TICK_SECONDS, \$remaining);%$slice = $remaining;%'

# ── The html adapter (In'li, the first real source), added 2026-08-19 ─────────────────────────────
#
# Every case here is a SILENT failure by construction. An html adapter that stops extracting does
# not error, does not slow down and does not look different — it reports a healthy run with no
# listings, which is indistinguishable from a rental market that went quiet. That is the whole
# reason this source's adapter throws where the obvious code would `return []`.

# The one that matters most. A redesign renaming `featured-item` leaves a 200, valid HTML and zero
# cards; returning them as an empty list reports calm forever.
run_sabotage "a selector matching nothing returns an empty list instead of throwing" \
  src/php/Adapters/HtmlSource.php \
  's%if (\$items->count() === 0) {%if (false) {%'

# Drops the capture and hands the whole text node to the number parser. `3 pièces · 55.32 m²` then
# yields the FIRST token for the surface — 3 m² instead of 55.32 — and 3 m² is a number, so nothing
# downstream can tell it apart from a very small studio.
run_sabotage "the field map's regex capture is ignored (surface reads the room count)" \
  src/php/Adapters/Html/Selector.php \
  's%if ($this->capture === null) {%if (true) {%'

# Walking pages until one comes back empty is a TERMINATION rule, not a correctness proof: a page=N
# that quietly 404s or redirects to page one ends the walk exactly like a genuine last page. Without
# the declared-total check, 24 of 92 listings is reported as a complete pass.
run_sabotage "pagination stops checking the total the page declares" \
  src/php/Adapters/HtmlSource.php \
  's%if (\$total !== null \&\& count(\$out) < \$total) {%if (false) {%'

# Unbounded pagination against a site that ignores the page parameter is an infinite request loop
# on somebody else's server — the one bug in this adapter that could actually get an IP banned,
# which under hard rule 5 is the thing polite pacing exists to prevent.
run_sabotage "the pagination page bound stops being enforced" \
  src/php/Adapters/HtmlSource.php \
  's%if (\$page >= \$this->definition->maxPages) {%if (false) {%'

# A pattern that does not match yields the UNPARSED text instead of null. Hard rule 9's neighbour:
# the field is then a string that happens to contain a number somewhere, and the parser will find
# one — so an unknown becomes a confident wrong value rather than an honest absence.
run_sabotage "a regex that does not match returns the raw text instead of null" \
  src/php/Adapters/Html/Selector.php \
  's%return \$value === .. ? null : \$value;%return $value;%'

# The loader stops refusing an enabled html source with no item_selector, so the refusal moves from
# load time to fetch time — after a poll has been scheduled and a run logged against a source that
# was never going to work.
run_sabotage "an enabled html source may ship with no item_selector" \
  src/php/Config/ConfigLoader.php \
  "s%if (\\\$type === 'html' \&\& (\\\$itemSelector === null || trim(\\\$itemSelector) === '')) {%if (false) {%"

# ── the Q36 flood guard ───────────────────────────────────────────────────────────────────────
#
# Every failure here is silent in the same direction: the guard still exists, still reads a
# plausible fact, and lets the run through. Nobody sees a stack trace — they see one push per
# listing in the back catalogue, which on a source like In'li is ninety-two of them at once.

# There is deliberately NO case for `RENT_WATCH_MAX_PASSES` itself. Removing the bound does not make
# a test fail, it makes one BLOCK — which the timeout above reports as inconclusive, correctly, since
# a suite that never finished never said anything. The hang is the signal; it just is not detection.

# The guard is disarmed outright.
run_sabotage "the empty-database guard stops firing" \
  src/php/Cli/Scout.php \
  's%if (\$store->isSeenSetEmpty() \&\& !\$seed) {%if (false) {%'

# It fires, but `--seed` is no longer the way past it, so the ONLY documented route through is shut
# and the guard becomes a wall: the tool can never make its first real run.
run_sabotage "--seed no longer gets past the empty-database guard" \
  src/php/Cli/Scout.php \
  's%if (\$store->isSeenSetEmpty() \&\& !\$seed) {%if (true) {%'

# The regression this whole change fixes, reintroduced: the store answers "has anything been
# recorded?" from a flag about THIS process instead of from the rows. Any earlier command that
# opened the file — `scout doctor` is the first one a new machine invites you to type — then
# answers it for good.
run_sabotage "the seen-set emptiness answer comes from the process, not the rows" \
  src/php/Store/Store.php \
  's%return (int) \$this->pdo->query(.SELECT EXISTS (SELECT 1 FROM listings).)->fetchColumn() === 0;%return false;%'

# ── schema v4, the cross-portal group ─────────────────────────────────────────────────────────────
# Every guarantee below fails SILENTLY. A group that stops being assigned looks exactly like a market
# with no cross-portal duplicates; a history that reports nothing looks like a flat whose rent never
# moved. Neither shows up as an error, and both are invisible in a green suite.

run_sabotage "a cluster's members are no longer tied into a group" \
  src/php/Store/Store.php \
  's%\$members = array_values(array_unique(\$memberKeys));%$members = [];%'

# The one that survives a shuffle. Minting from whoever survived THIS pass renames the group every
# time source order changes, and orphans any member that delisted in between.
run_sabotage "the group key is minted fresh each pass instead of adopted" \
  src/php/Store/Store.php \
  's%\$adopted ??= \$members\[0\];%$adopted = $members[0];%'

# NULL never equals NULL in SQL, so taking the group branch for an ungrouped listing reports "no
# price history" for most of the database, silently.
run_sabotage "the joined history compares a NULL group against itself" \
  src/php/Store/Store.php \
  's%if (\$group === null) {%if (false) {%'

# The §1-adjacent one: a group-scoped notification gate hides a real flat the moment an over-merge
# happens, and hides it permanently.
run_sabotage "grouping also marks its members notified" \
  src/php/Store/Store.php \
  's%UPDATE listings SET group_key = :group WHERE dedup_key = :key%UPDATE listings SET group_key = :group, notified_at = first_seen_at WHERE dedup_key = :key%'

run_sabotage "an older database is opened without ever adding group_key" \
  src/php/Store/Store.php \
  's%if (\$recorded < 4) {%if (false) {%'

# Back to the pre-v4 shape: cluster first, record only survivors. The overlay then ships inert —
# every group is a group of one and nothing anywhere says so.
run_sabotage "only the cluster survivor is recorded" \
  src/php/Cli/Pipeline.php \
  "s%foreach (\\\$cluster\\['members'\\] as \\\$member) {%foreach ([\$cluster['listing']] as \$member) {%"

run_sabotage "--seed marks only the survivor, leaving members to be announced later" \
  src/php/Cli/Pipeline.php \
  "s%foreach (\\\$clusterKeys\\[spl_object_id(\\\$listing)\\] as \\\$memberKey) {%foreach ([\$sighting->dedupKey] as \$memberKey) {%"

# ── the second source: CDC Habitat, path pagination, and prose that is not an acronym ─────────────
#
# Every one of these is silent. A source that classifies everything UNKNOWN looks like a quiet
# market; a floor parser that loses RDC looks like ads that omit the floor; a robots check that
# only covers page one keeps returning 200 while breaking hard rule 5.

run_sabotage "the card's own prose is scanned as an identifier field again (the adverb PLUS returns)" \
  src/php/Core/TenureClassifier.php \
  "s%if (\\\$name === '_text') {%if (false) {%"

run_sabotage "RawListing::text stops returning the adapter's card-text surface" \
  src/php/Core/RawListing.php \
  's%\$cardText = \$this->fields\[._text.\] ?? null;%$cardText = null;%'

run_sabotage "the config's own tenure_field stops being read as a financing field" \
  src/php/Core/TenureClassifier.php \
  "s%'tenurefield',%%"

run_sabotage "RDC stops meaning the ground floor (null is not zero, hard rule 9)" \
  src/php/Adapters/Payload.php \
  's%return 0;%return null;%'

run_sabotage "a floor is read with the generic number parser (the room count becomes the floor)" \
  src/php/Adapters/ListingMapper.php \
  's%floor: Payload::floor(\$item, \$map->floor),%floor: Payload::int($item, $map->floor),%'

run_sabotage "robots is checked for the index only, never for the pages the walk visits" \
  src/php/Adapters/HtmlSource.php \
  's%if (!\$this->robots->allows(Robots::pathOf(\$pageUrl))) {%if (false) {%'

run_sabotage "page_path silently falls back to appending a query parameter" \
  src/php/Adapters/HtmlSource.php \
  's%\$pageBody = \$this->get(\$pageUrl, \[\]);%$pageBody = $this->get($url, ["page" => (string) $page]);%'

run_sabotage "a page_path with no {page} placeholder is accepted, so the walk never advances" \
  src/php/Config/ConfigLoader.php \
  's%if (\$pagePath !== null \&\& !str_contains(\$pagePath, .{page}.)) {%if (false) {%'


# ── source #3: Cityloger, detail-page hydration, and prose that is not a tenure ───────────────────
#
# Every one of these is silent. A gate that stops narrowing turns one pass into 51 requests against
# somebody else's site; a detail map that reads the whole page classifies a real logement
# intermédiaire as UNKNOWN and digests it forever; and a merge that lets an absent field win erases
# what the card already knew.

# This case used to read `the detail gate stops narrowing`, targeting `$this->detailGate`. That
# symbol was renamed to `$detailPriority` on 2026-08-23 when novelty replaced the predicate, so the
# expression matched NOTHING and the ledger scored it `unapplied` — a case that reports as a covered
# guarantee while testing nothing at all, which is worse than one that fails. What bounds requests
# now is the budget, not the ordering, so the sabotage targets the budget.
run_sabotage "the per-pass detail budget stops bounding (every novel listing costs a request)" \
  src/php/Adapters/HtmlSource.php \
  's%if ($spent >= $budget) {%if (false) {%'

# ── Phase 2b: prose readers, and the two facts they manufacture if read carelessly ────────────────
#
# Every one of these is hard rule 9 inverted -- a fact invented out of its own negation. None is
# caught by a green suite, because each produces a plausible-looking value rather than an error: a
# floor that is really the building's height, a lift on a flat that has none, a field that stops
# being collected the day its map changes, and a correct match demoted to the digest by an adverb.

run_sabotage "Payload::floor reads a plural count, so a building height becomes the tenant's floor" \
  src/php/Adapters/Payload.php \
  's%etage[\]b/%etage/%'

run_sabotage "Prose::floor reads a plural count (de 18 etages) as a position" \
  src/php/Core/Prose.php \
  's%etage[\]b/%etage/%'

run_sabotage "Prose::elevator stops reading the negation first (Aucun ascenseur becomes a lift)" \
  src/php/Core/Prose.php \
  's%if ($negation !== null && ($assertion === null || $negation > $assertion)) {%if (false) {%'

run_sabotage "the detail cache stops being keyed on the map, so a widened map serves stale rows" \
  src/php/Adapters/HtmlSource.php \
  's%$listing->externalId, $detailMap->fingerprint());%$listing->externalId);%'

run_sabotage "prose fields are scanned as identifiers again, so the adverb plus reads as PLUS" \
  src/php/Core/TenureClassifier.php \
  "s%(\$name === 'title' || \$name === 'description')%(\$name === 'never-a-real-field')%"

# The successor to "a detail_map with no gate refuses", which retired on 2026-08-23 when novelty
# became the gate. What that invariant protected is unchanged: a detail map that can never run
# leaves its source resolving UNKNOWN for ever while health stays green. The refusal moved up a
# layer, to config load, so the sabotage moved with it rather than being deleted.
run_sabotage "a detail_map with a zero budget is accepted, so it can never run" \
  src/php/Config/ConfigLoader.php \
  's%if ($detailMap !== null \&\& $detailBudget === 0) {%if (false) {%'

run_sabotage "a failed detail fetch becomes an unhydrated listing (rule 3)" \
  src/php/Adapters/HtmlSource.php \
  's%} catch (SourceError $e) {%} catch (SourceError $e) { return $listing;%'

run_sabotage "the detail map gets the card path, so _text becomes the whole PAGE" \
  src/php/Adapters/HtmlSource.php \
  's%$this->flatMapped($detailMap, detailMode: true)%$this->flatMapped($detailMap, detailMode: false)%'

run_sabotage "an absent detail value overwrites what the card knew (rule 9)" \
  src/php/Core/RawListing.php \
  's%static fn (mixed $mine, mixed $theirs): mixed => $theirs ?? $mine%static fn (mixed $mine, mixed $theirs): mixed => $theirs%'

run_sabotage "an empty detail string overwrites the card's own text (rule 9)" \
  src/php/Core/RawListing.php \
  's%static fn (string $mine, string $theirs): string => $theirs !== .. ? $theirs : $mine%static fn (string $mine, string $theirs): string => $theirs%'

run_sabotage "the detail page re-identifies the listing, so it re-notifies forever" \
  src/php/Core/RawListing.php \
  's%externalId: $this->externalId,%externalId: $detail->externalId !== '"'"''"'"' ? $detail->externalId : $this->externalId,%'

run_sabotage "robots is checked for the search page only, never for the detail pages" \
  src/php/Adapters/HtmlSource.php \
  '/private function withDetail/,$ s%if (!$this->robots->allows(Robots::pathOf($url))) {%if (false) {%'

run_sabotage "detail fetches stop being paced (a per-listing burst, hard rule 5)" \
  src/php/Adapters/HtmlSource.php \
  '/private function withDetail/,$ s%usleep($this->definition->rateLimitMs \* 1000);%%'

run_sabotage "a {page} url template is fetched literally, so page one is never real" \
  src/php/Adapters/HtmlSource.php \
  's%$firstUrl = $urlTemplate ? str_replace(.{page}., .1., $url) : $url;%$firstUrl = $url;%'

run_sabotage "the walk stops substituting {page}, so every page is page one" \
  src/php/Adapters/HtmlSource.php \
  "s%? str_replace('{page}', (string) \$page, \$url)%? \$url%"

run_sabotage "a detail map may redefine ref, so identity comes from the wrong page" \
  src/php/Config/FieldMap.php \
  's%if ($map->ref !== \[\]) {%if (false) {%'


# ── Q27: the watcher's liveness signal ────────────────────────────────────────
# Every case here is silent by construction. A heartbeat that stops arriving looks exactly like a
# quiet rental market, which is the observation the whole feature exists to make distinguishable —
# so a regression in it removes the ONE guard against a dead watcher going unnoticed for days.

run_sabotage "the cold start stops beating (a watcher that dies in hour one is invisible for a day)" \
  src/php/Core/Heartbeat.php \
  's%if ($lastSentIso === null || trim($lastSentIso) === %if (false \&\& %'

run_sabotage "the heartbeat marker is never written (so every restart beats)" \
  src/php/Cli/Scout.php \
  's%@file_put_contents($this->stateFile(.heartbeat.txt.), $now%@file_put_contents("/dev/null", $now%'

run_sabotage "an unreadable heartbeat marker suppresses the beat instead of forcing one" \
  src/php/Core/Heartbeat.php \
  's%if ($last === null || $now === null) {%if (false) {%'

run_sabotage "a marker in the future suppresses liveness until the clock catches up" \
  src/php/Core/Heartbeat.php \
  's%if ($last > $now) {%if (false) {%'

run_sabotage "HEARTBEAT_HOURS=0 silently disables liveness instead of refusing" \
  src/php/Core/Heartbeat.php \
  's%if ($intervalHours < 1) {%if (false) {%'

run_sabotage "the previous startup refusal is never cleared (reported forever)" \
  src/php/Cli/Scout.php \
  's%@unlink($path);%%'

run_sabotage "a startup refusal is written to disk unredacted (a credential leak)" \
  src/php/Cli/Scout.php \
  's%Redact::text($text)%$text%'

# The beat's health figure counts what the run WATCHES. Reverting it to every enabled source is the
# bug this replaced, and it is invisible from inside: the number is plausible, the watcher is
# healthy, and the line simply reports faults that do not exist. Observed on a real container as
# "1/5 source(s) en bon état" while one source was deliberately scoped and four were never polled.
run_sabotage "the beat counts CONFIGURED sources again (a scoped watcher invents faults)" \
  src/php/Cli/Scout.php \
  's%foreach ($watched as $name) {%foreach ($this->sourceNames() as $name) {%'

# And the other direction, which is why the fix is not just "count fewer". Drop the disclosure and a
# deployment carrying a forgotten `--source` reports a flawless 1/1 for ever while the landlords it
# is not watching go unwatched. The beat is what reaches the phone; the startup banner is a log line
# read once.
run_sabotage "the beat stops disclosing that it is scoped (a forgotten --source looks perfect)" \
  src/php/Cli/Scout.php \
  's%if ($configured !== \\count($watched)) {%if (false) {%'

# The in-loop beat — the one that fires on day two — lives inside a closure, so an argument that is
# not captured is `null` at the call site and the watcher dies on its first due beat, 24 hours into
# an unattended run. This is not hypothetical: it was written that way, and every existing heartbeat
# test missed it because the fixed clock makes the in-loop beat unreachable. The case that catches
# it had to reach that call site deliberately.
run_sabotage "the in-loop beat loses an argument to the closure boundary (dies on day two)" \
  src/php/Cli/Scout.php \
  's%$heartbeat, $watched, \&$passes%$heartbeat, \&$passes%'

# ── region mode (2026-08-22) ─────────────────────────────────────────────────────────────────────
# `communes: []` means "the postcode prefixes are the whole location filter". It is the first
# LOOSENING this config has taken, so all four cases below are about the two directions it can fail
# in, and both are silent.
#
# Dead: the filter rejects everything, which is indistinguishable from a quiet rental market — the
# exact shape hard rule 2 exists for, arriving through config rather than a selector.
run_sabotage "region mode reverts to matching nothing (reads as a quiet market for ever)" \
  src/php/Config/Criteria.php \
  's%return $this->postcodeMatchesPrefix($postcode);%return false;%'

# Open, direction one: the prefix check is skipped along with the name check, so `communes: []`
# quietly becomes "anywhere in France". Over-matching does not look broken — it looks busy. Same
# line as the case above, mutated the other way, because that one line IS the region-mode decision.
run_sabotage "region mode stops checking the postcode prefix (anywhere in France matches)" \
  src/php/Config/Criteria.php \
  's%return $this->postcodeMatchesPrefix($postcode);%return true;%'

# Open, direction two: the loader stops refusing the one combination that has no filter left at all.
# The refusal is what makes the loosening safe, so it is load-bearing, not defensive decoration.
run_sabotage "the empty-communes + empty-prefixes refusal is removed (config fails open)" \
  src/php/Config/ConfigLoader.php \
  's%if ($communes === \[\] && $prefixes === \[\]) {%if (false) {%'

# And the regression region mode actually caused, which no test of region mode itself would find:
# `communeLabels` is the vocabulary EmailAlertSource reads a commune out of an alert body with.
# Built from `communes` alone it is empty in region mode, and every emailed listing silently loses
# its commune — no S1 score, nothing to name in the notification, a weaker dedup key — while still
# matching on its postcode, so nothing looks wrong.
run_sabotage "a ranked commune stops being alert-parser vocabulary (emails lose their commune)" \
  src/php/Config/ConfigLoader.php \
  's%$communeLabels\[$key\] ??= $label;%%'

# ── `--source=<name>` force-run, and the demo source that must not ship enabled (2026-08-22) ──────
#
# Every one of these four is silent in the direction that matters. A demo source running in a real
# deployment does not error: it adds ten flats that do not exist to the store, the pass totals, the
# `doctor` table and the heartbeat's source count, and it looks exactly like a landlord with a small
# portfolio. It shipped that way for two weeks.

# Direction one: the force-run is removed, so `--source=<name>` silently runs NOTHING for a disabled
# source. The failure mode is the one this repo already names — a debugging flag that reports a
# clean, fast, empty pass — arriving now on the onboarding path `/add-source` step 5 prescribes.
run_sabotage "--source stops force-running a disabled source (named source runs nothing)" \
  src/php/Cli/Scout.php \
  's%if ($only === null) {%if (true) {%'

# Direction two, and the over-correction: the enabled check goes away entirely, so every disabled
# source runs on every ordinary pass — including the placeholder blocks kept `enabled: false`
# precisely because nobody has verified their endpoint (hard rule 1).
run_sabotage "the enabled check is dropped, so every disabled source runs unasked" \
  src/php/Cli/Scout.php \
  's%if (!$definition->enabled) {%if (false) {%'

# Hard rule 1 at the funnel. Force-running brought REMPLACER back within reach of a real fetch, so
# the refusal is what makes force-running safe rather than defensive decoration.
run_sabotage "a REMPLACER endpoint is polled instead of refused (hard rule 1 at the funnel)" \
  src/php/Cli/Scout.php \
  's%if ($definition->url === ConfigLoader::UNVERIFIED_URL) {%if (false) {%'

# And the config itself. This is the state the tree was actually in, so the case is a regression
# test for a shipped defect rather than a hypothetical: the guard must notice the flag going back.
run_sabotage "the demo fixture ships enabled again (fake listings in a real deployment)" \
  config/sources.json \
  '/"fixture_demo"/,/^    },/ s%"enabled": false%"enabled": true%'

# ── `.env` is parsed, never executed (2026-08-22) ──────────────────────────────────────────────────
#
# The bug that produced this class was silent in the worst way: the CLI reported "SMTP_PASSWORD is
# empty" while the file plainly contained one, because bash had read `KEY=a b c` as a one-command
# prefix and never exported it -- and had EXECUTED `b c`, printing part of a live credential.

# Precedence. `RENT_WATCH_DB=/tmp/throwaway bin/scout run` is how a live source is measured without
# touching the real seen-set; a file that could override the environment would silently point that
# at the real database, and the run would look completely normal.
run_sabotage "the file overrides the real environment (a throwaway run hits the real store)" \
  src/php/Config/DotEnv.php \
  's%if (self::alreadySet($name)) {%if (false) {%'

# A malformed line stops being a refusal. The line is then skipped in silence, so a fat-fingered
# credential means a channel that is simply absent, with nothing said about why.
run_sabotage "a malformed .env line is skipped instead of refused" \
  src/php/Config/DotEnv.php \
  's%throw self::malformed($index + 1);%continue;%'

# Quote stripping. Quotes are the only way to express a value with meaningful trailing whitespace,
# so keeping them makes every quoted password wrong by exactly two characters -- an authentication
# failure that looks like a wrong password rather than a parser bug.
run_sabotage "wrapping quotes are kept, so every quoted secret is wrong by two characters" \
  src/php/Config/DotEnv.php \
  's%return substr($value, 1, -1);%return $value;%'

# ── the notification's facts line (2026-08-22) ─────────────────────────────────────────────────────
#
# This is the product's ONLY user-facing surface -- there is no web UI by design -- so a defect here
# is not cosmetic: it is the whole output. All three below are hard rule 9 at the display layer,
# where `null` and `0` and `false` are three different facts about a building.

# `floor === 0` is RDC and REAL. Read as falsy it vanishes, which is the display twin of rejecting a
# listing for not stating a floor.
run_sabotage "the ground floor is treated as no floor at all (RDC vanishes)" \
  src/php/Core/Notify/Formatter.php \
  's%if ($listing->floor !== null) {%if (!empty($listing->floor)) {%'

# An UNMENTIONED lift becomes an absent one -- the notification asserting something no source said,
# about the one feature that makes a 5th-floor flat unlivable for some people.
run_sabotage "a lift nobody mentioned is reported as absent (a fact the tool invented)" \
  src/php/Core/Notify/Formatter.php \
  's%if ($listing->hasElevator !== null) {%if (true) {%'

# The postcode leaves the headline. On In'li -- 54 of 83 matches, and it ships NO title -- the
# headline is the only text in the notification, so this returns it to a bare commune and a price.
run_sabotage "the postcode is dropped from the headline (ambiguous commune, no title)" \
  src/php/Core/Notify/Formatter.php \
  "s%\$where = \$where === '' ? \$postcode : \$where . ' ' . \$postcode;%%"

# ── Detail hydration, schema v5 (2026-08-23) ─────────────────────────────────────────────────────
#
# Every failure here is silent by construction. A cache that stops being read is a crawl of somebody
# else's site, visible only in their access log. A budget that stops being enforced is the same
# thing wearing a retry for a costume. A failure that stops being recorded is the swallow hard rule
# 3 names, and it presents as a listing that merely has no title.

run_sabotage "a hydrated page is re-fetched every pass anyway (the crawl)" \
  src/php/Adapters/HtmlSource.php \
  "s%if (\$cached !== null \&\& \$cached->fields !== null) {%if (false) {%"

run_sabotage "the per-pass hydration budget stops being enforced" \
  src/php/Adapters/HtmlSource.php \
  "s%if (\$spent >= \$budget) {%if (false) {%"

run_sabotage "a failed detail fetch is swallowed and never recorded" \
  src/php/Adapters/HtmlSource.php \
  "s%\$this->store->recordDetailFailure(%\$this->nothing(%"

run_sabotage "the detail cache key loses its source, so two landlords share a row" \
  src/php/Store/Store.php \
  "s%FROM listing_detail WHERE source = :source AND external_id = :id%FROM listing_detail WHERE external_id = :id OR :source IS NULL%"

run_sabotage "a failed detail page is retried on every pass, for ever" \
  src/php/Adapters/HtmlSource.php \
  "s%if (\$cached->attempts >= self::DETAIL_ATTEMPT_CAP) {%if (false) {%"

run_sabotage "hydration priority is inverted, so the worst candidate takes the slot" \
  src/php/Adapters/HtmlSource.php \
  's%return $owed;%return array_reverse($owed, true);%'

printf '\n  %d sabotage(s) detected, %d undetected\n' "$pass" "$fail"

if [[ -n "$_filter" ]]; then
  # Loud, because a filtered run that looked like a full one would be the ledger lying about its own
  # coverage — the same class of defect as the baseline gate that reddened itself for six days.
  printf '  \033[33mPARTIAL RUN\033[0m — SABOTAGE_FILTER=%s skipped %d case(s). NOT a ledger result.\n' \
    "$_filter" "$skipped"
fi

if (( fail > 0 )); then
  printf '\n  undetected or unapplied:\n'
  printf '    - %s\n' "${failed_labels[@]}"
fi

printf '\n'

# An explicit exit, not a trailing test expression. The positional form was correct only while
# nothing followed it, and on 2026-08-20 eight appended cases followed it: with no `set -e` they
# ran on past it and left their own 0 as the script's status, so a FAIL exited 0 and the nightly
# job could not go red. Anything appended below is now dead code, which
# tests/test-ci-workflow.sh reports rather than the ledger absorbing it.
if (( fail > 0 )); then
  exit 1
fi

exit 0
