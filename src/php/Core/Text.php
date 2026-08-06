<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * Deterministic text folding, shared by every text-matching decision in the core.
 *
 * WHY THIS IS HAND-ROLLED RATHER THAN `Normalizer::normalize()` OR `iconv`:
 * this repo ships the same core twice — once in PHP, once in phorj — and the two are compared
 * fixture-by-fixture against `tests/fixtures/tenure/corpus.json`. That comparison only means
 * something if both implementations fold text identically. ICU's normalisation tables are a
 * dependency phorj has not been confirmed to have, and `iconv//TRANSLIT` output varies with the
 * host locale. An explicit table is portable, has no locale, and never changes under us.
 *
 * Folding is deliberately lossy in one direction only: it removes accents and (in {@see fold()})
 * case, and it normalises the several Unicode apostrophes French text arrives with. It does NOT
 * decode HTML entities — that is the adapter's job, and a classifier that quietly decoded them
 * would hide a broken adapter (see the `case-005` fixture).
 */
final class Text
{
    /** Lowercase accented forms. */
    private const array FOLD_LOWER = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /**
     * Uppercase accented forms, written out rather than derived at runtime.
     *
     * Spelled in full because {@see foldPreserveCase()} is what tells the financing acronym `PLUS`
     * from the French adverb `plus`, so its output must not depend on a locale-sensitive
     * `mb_strtoupper()` call — and because phorj will mirror this table literally.
     */
    private const array FOLD_UPPER = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ý' => 'Y', 'Ÿ' => 'Y',
        'Œ' => 'OE', 'Æ' => 'AE',
    ];

    /** Every apostrophe French listings arrive with, folded to the ASCII one. */
    private const array APOSTROPHES = ["\u{2019}", "\u{2018}", "\u{02BC}", "\u{FF07}", '`', '´'];

    /**
     * Strip accents and normalise apostrophes and whitespace, but KEEP case.
     *
     * This is the surface the ambiguous-acronym rules are matched against, because case is the
     * evidence there: `PLUS` in a financing collocation is a tenure label, `plus` is the adverb.
     */
    public static function foldPreserveCase(string $raw): string
    {
        // Checked BEFORE anything else, and it throws rather than degrading. See MalformedText:
        // `preg_replace(…, '/u')` returns null on malformed UTF-8, and casting that to string
        // produced '' — which the classifier read as "this listing mentioned no financing scheme"
        // and matched on the source default. An unreadable listing is not an unlabelled one.
        if ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
            throw MalformedText::notUtf8('Text::foldPreserveCase');
        }

        // An HTML entity here means an adapter stopped short. Refuse rather than classify around
        // it: an entity sitting inside a multi-word label deletes that label and leaves the others
        // standing, and the label it deletes is not chosen fairly.
        if (preg_match('/&(?:[a-zA-Z][a-zA-Z0-9]{1,10}|#\d{1,6}|#[xX][0-9a-fA-F]{1,6});/', $raw, $entity) === 1) {
            throw MalformedText::undecodedEntities($entity[0]);
        }

        $s = strtr($raw, self::FOLD_LOWER + self::FOLD_UPPER);
        $s = str_replace(self::APOSTROPHES, "'", $s);

        // TWO INVISIBLE CATEGORIES, STRIPPED FOR THE SAME REASON: neither carries meaning, and
        // either one sitting inside a multi-word label deletes that label while leaving every other
        // label in the listing standing. The deletion is not chosen fairly, so the failure is
        // asymmetric — and both directions of it were found by review rather than by the suite.
        //
        // \p{Mn} — combining diacritics, for text that arrives NFD-decomposed (`e` + U+0301) rather
        // than precomposed. Without it an NFD `numéro unique d'enregistrement` never matched while
        // an unaccented `LLI` in the same listing still did: the social side vanished, the eligible
        // side survived.
        //
        // \p{Cf} — invisible FORMAT characters: U+00AD soft hyphen, U+200B zero-width space,
        // U+200C/D zero-width (non-)joiner, U+200E LRM, U+2060 word joiner, U+FEFF BOM. Same
        // asymmetry, one Unicode category over: `Ce logement<U+00AD>social a loyer intermediaire`
        // lost `logement social` and kept `loyer intermediaire`, classifying an explicitly social
        // listing as LLI at confidence 90 — above the floor, so the fail-closed rule never engaged.
        //
        // Note the doctrine that CREATES this input: MalformedText::undecodedEntities() tells the
        // adapter that decoding is its job. An adapter that obeys turns `&shy;` -- standard
        // hyphenation markup in justified French CMS output -- into U+00AD, which passes both the
        // UTF-8 gate and the entity gate. Refusing these would punish the adapter for being
        // correct; stripping them is right, because they are invisible by definition.
        // \s already covers the space-LIKE leaks (NBSP, narrow NBSP) via the collapse below.
        $stripped = preg_replace('/[\p{Mn}\p{Cf}]/u', '', $s);
        $collapsed = $stripped === null ? null : preg_replace('/\s+/u', ' ', $stripped);

        // `null` from either call is a PCRE ERROR, never "nothing to replace". Casting it to string
        // is what turned an unreadable listing into an unlabelled one in the first place, so the
        // anti-pattern is not left sitting two lines above its own fix.
        if ($collapsed === null) {
            throw MalformedText::notUtf8('Text::foldPreserveCase (' . preg_last_error_msg() . ')');
        }

        return trim($collapsed);
    }

    /**
     * Lowercase, strip accents, normalise apostrophes, collapse whitespace.
     *
     * The result is the canonical form every literal pattern in this codebase is written against.
     */
    public static function fold(string $raw): string
    {
        return mb_strtolower(self::foldPreserveCase($raw), 'UTF-8');
    }

    /**
     * Does `$needle` occur in `$foldedHaystack` as a whole token?
     *
     * This is the single most important function in the tenure module, and the reason is one word:
     * `plus`. It is among the commonest words in French, so a substring match classifies most of the
     * rental market as social housing. `plai` is the mirror image — as a substring it matches
     * *plaine*, *plaisant* and *plaisir*. Both mistakes are silent: the listings are simply dropped
     * and never arrive, so the developer concludes the market is quiet.
     *
     * @param string $foldedHaystack must already have been through {@see fold()}
     * @param string $needle         a folded literal, possibly multi-word
     */
    public static function hasToken(string $foldedHaystack, string $needle): bool
    {
        return self::tokenPosition($foldedHaystack, $needle) !== null;
    }

    /**
     * Byte offset of the first whole-token occurrence, or null.
     *
     * The offset is what makes tie-breaking between two matches deterministic — and therefore
     * reproducible in the phorj implementation — rather than dependent on table iteration order.
     */
    public static function tokenPosition(string $foldedHaystack, string $needle): ?int
    {
        if ($needle === '' || $foldedHaystack === '') {
            return null;
        }

        $pattern = '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/u';
        $result = preg_match($pattern, $foldedHaystack, $m, PREG_OFFSET_CAPTURE);

        // `false` is a PCRE ERROR, not "no match", and treating the two alike is how an unreadable
        // listing becomes an unlabelled one. Inputs here have already been through fold(), so this
        // should be unreachable — which is exactly why it must be loud if it ever happens.
        if ($result === false) {
            throw MalformedText::notUtf8('Text::tokenPosition (' . preg_last_error_msg() . ')');
        }

        return $result === 1 ? $m[0][1] : null;
    }

    /**
     * Words that are acronyms, not French words, and therefore never inflect.
     *
     * `plai` must be here and the reason is sharp: the generic rule below would generate `plaie`
     * (a wound) and `plais` (from *plaire*), both real French words, and every listing containing
     * one would be classified as social housing and dropped in silence.
     */
    private const array INVARIANT_WORDS = ['lli', 'plai', 'plus', 'pls', 'anru', 'anah', 'hlm', 'sne', 'apl'];

    /**
     * Whole-token match that tolerates FRENCH AGREEMENT AND PLURALS.
     *
     * THE DEFECT THIS EXISTS FOR: every literal used to be matched exactly, with a trailing
     * `(?![a-z0-9])` guard. French tenure vocabulary is inflected — the adjective agrees and the
     * noun phrase pluralises — so `conventionnée`, `logements sociaux` and `prêts locatifs sociaux`
     * were all non-matches while `conventionné` and `logement social` matched. The acronyms
     * (`PLAI`, `ANRU`, `ANAH`, `HLM`) are invariant, which is precisely why the table read as safe
     * on inspection: the terms a reviewer checks first are the ones that could not break.
     *
     * A listing whose own description said *« logements sociaux et intermédiaires »* classified as
     * LLI at full tier-2 confidence and was notified, because no excluded signal existed for the
     * conflict rule to see.
     *
     * The rules are deliberately small and French-specific:
     *   - a word ending in `-al` also matches `-aux` (`social` → `sociaux`), plus `-e`/`-es`;
     *   - any other word may take `-e`, `-es`, `-s` or `-x`;
     *   - words in {@see INVARIANT_WORDS} take nothing at all.
     *
     * Over-generation (`logementx`, `uniquee`) is harmless: those are not French words, so they
     * match no real listing. Under-generation is what costs an application.
     *
     * @param string $foldedHaystack must already have been through {@see fold()}
     *
     * @return array{int, string}|null byte offset and the text that actually matched, or null
     */
    public static function inflectedTokenPosition(string $foldedHaystack, string $needle): ?array
    {
        if ($needle === '' || $foldedHaystack === '') {
            return null;
        }

        $parts = [];

        foreach (explode(' ', $needle) as $word) {
            if (in_array($word, self::INVARIANT_WORDS, true)) {
                $parts[] = preg_quote($word, '/');
            } elseif (str_ends_with($word, 'al')) {
                $parts[] = '(?:' . preg_quote($word, '/') . '(?:es|e)?|'
                    . preg_quote(substr($word, 0, -2), '/') . 'aux)';
            } else {
                $parts[] = preg_quote($word, '/') . '(?:es|e|s|x)?';
            }
        }

        // `\s*`, not `\s+`, between words. Stripping an invisible \p{Cf} character that sat between
        // two words JOINS them — `logement<U+200B>social` becomes `logementsocial` — so a pattern
        // requiring whitespace would still miss the label the strip was meant to rescue. Allowing
        // zero separation is safe in both directions: `logementsocial` is not a French word, so
        // nothing legitimate matches by accident, and the guards at each end still require the
        // whole thing to stand as a token.
        $pattern = '/(?<![a-z0-9])' . implode('\s*', $parts) . '(?![a-z0-9])/u';
        $result = preg_match($pattern, $foldedHaystack, $m, PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw MalformedText::notUtf8('Text::inflectedTokenPosition (' . preg_last_error_msg() . ')');
        }

        return $result === 1 ? [$m[0][1], $m[0][0]] : null;
    }

    /**
     * Reduce a structured field NAME to a comparison key: folded, then stripped of every
     * separator. `typeProduit`, `TYPE_PRODUIT` and `Type de produit` all become `typeproduit`
     * — adapters disagree about field-name style and none of them is wrong.
     */
    public static function fieldKey(string $rawName): string
    {
        $s = self::fold($rawName);
        $s = str_replace([' de ', " d'", ' du ', ' des '], ' ', $s);

        return preg_replace('/[^a-z0-9]/u', '', $s)
            ?? throw MalformedText::notUtf8('Text::fieldKey (' . preg_last_error_msg() . ')');
    }
}
