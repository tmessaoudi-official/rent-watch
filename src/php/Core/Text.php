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
        $s = strtr($raw, self::FOLD_LOWER + self::FOLD_UPPER);
        $s = str_replace(self::APOSTROPHES, "'", $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return trim($s);
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

        if (preg_match($pattern, $foldedHaystack, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $m[0][1];
        }

        return null;
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

        return (string) preg_replace('/[^a-z0-9]/u', '', $s);
    }
}
