<?php

declare(strict_types=1);

namespace RentWatch\Adapters;

use RentWatch\Core\Text;

/**
 * Reads values out of one decoded raw item, following a field map's dotted paths.
 *
 * Everything here exists to serve `CLAUDE.md` hard rule 9 — **`null` is not zero** — and the rule is
 * easy to state and easy to violate one accessor at a time. So the conversion functions are the only
 * place a raw value becomes a typed one, and each returns `null` for *absent or unreadable*, never a
 * zero. The prototype's `(l.rooms or 0) < min_rooms` is what this is written against: it disqualifies
 * every listing that did not state a room count, silently, and a silent over-rejection is invisible
 * because nothing arrives.
 *
 * The other rule is that **an empty string is not a value**. Real payloads are full of `""`, `"-"`
 * and `"N/C"` where a field was not filled in, and treating those as data produces a listing that
 * claims to know something it does not.
 */
final class Payload
{
    /**
     * How deep {@see flatten()} descends before it stops.
     *
     * A bound, not a formality: this runs on the synchronous poll path, and a cyclic or
     * pathologically deep payload would otherwise recurse until the stack gives out. Twelve is well
     * beyond anything a real listing payload uses — six is deep for one — so the bound protects the
     * process without discarding evidence the classifier needs. Anything past it IS dropped, which
     * is a silent loss; it is accepted only because reaching it means the payload is not a listing.
     */
    private const int MAX_DEPTH = 12;

    /** Strings a source uses to mean "not filled in". Compared case-insensitively, after trimming. */
    private const array NULLISH = ['', '-', '--', 'n/a', 'na', 'n/c', 'nc', 'null', 'nd', 'non communique', 'non communiqué'];

    /**
     * Follow a dotted path into a decoded structure.
     *
     * A numeric segment indexes a list, so `photos.0.url` works. Returns `null` the moment a segment
     * is missing rather than throwing: a field map naming a path that a particular item happens not
     * to carry is ordinary, and the *systematic* version of that — a path no item carries — is what
     * the fixture test catches, loudly, at the right time.
     */
    public static function at(mixed $item, string $path): mixed
    {
        $cursor = $item;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor)) {
                return null;
            }
            if (!array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * First path that yields a non-nullish value wins.
     *
     * The list form is what lets one source block survive a payload redesign that moves a field:
     * `["rent.total", "price"]` costs one array element and covers both shapes.
     *
     * @param list<string> $paths
     */
    public static function first(mixed $item, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = self::at($item, $path);
            if (!self::isNullish($value)) {
                return $value;
            }
        }

        return null;
    }

    public static function isNullish(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), self::NULLISH, true);
        }

        return false;
    }

    /** @param list<string> $paths */
    public static function string(mixed $item, array $paths): ?string
    {
        $value = self::first($item, $paths);

        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * An integer, or `null`.
     *
     * Tolerant of the shapes money and counts actually arrive in — `"1 450 €"`, `"1450,00"`,
     * `"1.450 €/mois"` — because a source that reports a rent as a formatted string is common and
     * refusing it would silently drop the field. **Rounds rather than truncates**: `1449.6` is
     * 1450 €, and truncation would put a listing under a ceiling it does not meet.
     *
     * A value with no digits at all returns `null`, not `0`.
     *
     * @param list<string> $paths
     */
    public static function int(mixed $item, array $paths): ?int
    {
        $number = self::number($item, $paths);

        return $number === null ? null : (int) round($number);
    }

    /**
     * A FLOOR, read the way French listings actually write one.
     *
     * Floors are the one numeric field that arrives as prose rather than as a number, and the two
     * ways of getting it wrong are both silent:
     *
     * - **`RDC` parsed as nothing.** The ground floor is `0`, a real and extremely common floor —
     *   five of the thirteen distinct floor strings on CDC Habitat's Yvelines page are `RDC`. Read
     *   as `null` it becomes "the ad did not say", and hard rule 9 exists because those are
     *   different facts that drive different score components.
     * - **The card heading parsed as a number.** `3 pièces - 4ème étage - 82m²` fed to
     *   {@see number()} yields `3`, because that is the first number in the string. A fabricated
     *   floor is worse than an absent one, so anything this function does not positively recognise
     *   as a floor returns `null` rather than falling back to a generic number read.
     *
     * @param list<string> $paths
     */
    public static function floor(mixed $item, array $paths): ?int
    {
        $value = self::first($item, $paths);

        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        // A clean numeric field, which is what a source with a real `etage` key gives us.
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }

        $folded = Text::fold($text);

        // `rdc`, `rez-de-chaussee`, `rez de chaussee` — all one floor, and that floor is zero.
        if (preg_match('/\brdc\b/', $folded) === 1 || preg_match('/\brez.?de.?chaussee\b/', $folded) === 1) {
            return 0;
        }

        // `1er etage`, `2eme etage`, `4e etage`. The ordinal suffix varies by source and by writer;
        // the digit in front of `etage` does not.
        if (preg_match('/(\d+)\s*(?:er|ere|eme|e)?\s*etage/', $folded, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** @param list<string> $paths */
    public static function float(mixed $item, array $paths): ?float
    {
        return self::number($item, $paths);
    }

    /**
     * A boolean, or `null` — and the distinction is load-bearing.
     *
     * `false` means the listing said there is no lift. `null` means it did not mention one. Those are
     * different facts (hard rule 9), and conflating them is what makes the high-floor penalty fire on
     * a flat that has a lift the ad forgot to list. Only recognised spellings map to a boolean;
     * anything else is `null`, because guessing here is exactly the failure being avoided.
     *
     * @param list<string> $paths
     */
    public static function bool(mixed $item, array $paths): ?bool
    {
        $value = self::first($item, $paths);

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            'true', 'oui', 'yes', '1', 'y', 'o', 'avec', 'present', 'présent' => true,
            'false', 'non', 'no', '0', 'n', 'sans', 'absent', 'aucun' => false,
            default => null,
        };
    }

    /**
     * Every structured field of a raw item, flattened to `path => scalar`, for the classifier.
     *
     * The classifier reads field NAMES as evidence (`financement`, `typeProduit`), so it needs the
     * whole surface rather than the handful the field map happens to name. Nested objects are
     * flattened with their dotted path, and the LEAF name is what a name-matching rule sees — a
     * `caracteristiques.financement` must not hide from a rule looking for `financement`.
     *
     * @return array<string, string>
     */
    public static function flatten(mixed $item, string $prefix = '', int $depth = 0): array
    {
        // A bound, not a formality: a payload with a cyclic or pathologically deep structure would
        // otherwise recurse until the stack gives out, on the synchronous poll path.
        if (!is_array($item) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $out = [];
        foreach ($item as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += self::flatten($value, $name, $depth + 1);
                continue;
            }
            if (is_bool($value)) {
                $out[$name] = $value ? 'true' : 'false';
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    /** @param list<string> $paths */
    private static function number(mixed $item, array $paths): ?float
    {
        $value = self::first($item, $paths);

        if (is_int($value) || is_float($value)) {
            return is_float($value) && !is_finite($value) ? null : (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        // Remove the spaces that are THOUSANDS SEPARATORS, so `1 450` is one token rather than two.
        // The narrow-no-break space French typography uses is not matched by `\s` in a non-unicode
        // pattern, so each variant is removed as a literal.
        $raw = str_replace(["\u{202F}", "\u{00A0}", "\u{2009}", ' '], '', trim($value));

        // THEN take the FIRST NUMERIC TOKEN — not every digit left in the string.
        //
        // The previous version deleted every character that was not a digit or a separator and read
        // what remained as one number. That is correct only while the string contains exactly one
        // quantity, and real listing text routinely carries two:
        //
        //   '55,32 m2'            -> old: `55,322` -> 55322    (the unit's own `2` fused on, and the
        //                                                       last three digits then read as a
        //                                                       thousands group)
        //   '3 pièces · 55.32 m²' -> old: `355.32`             (rooms and surface fused)
        //   'Réf 12 — 1 450 €'    -> old: `121450`             (a reference and a rent fused)
        //
        // Every one of those is plausible as a rent, a surface or a room count, so nothing
        // downstream could reject it — the criteria engine simply scored a fabricated number.
        // `m²` escaped only because U+00B2 is not an ASCII digit, which is luck rather than design.
        //
        // The token may not END on a separator, so `1450.` yields `1450` rather than a trailing dot
        // that `is_numeric` would accept and that means nothing.
        //
        // The cost, stated rather than hidden: when a string leads with a quantity that is not the
        // one wanted, this returns the leading one. That is a visible, explainable wrong value
        // instead of an invented one, and the html field map isolates the intended quantity with a
        // regex capture rather than relying on token order.
        if (!preg_match('~-?\d[\d.,]*\d|-?\d~u', $raw, $token)) {
            return null;
        }

        $raw = $token[0];

        // Which separator is the decimal point?
        //
        // "the rightmost one wins" is the obvious rule and it is WRONG on the commonest French
        // rendering of a rent: `1.450 €` has exactly one separator, so the rightmost rule calls it a
        // decimal point and yields 1 — a 1450 € flat recorded as 1 €, which passes every ceiling and
        // scores maximum headroom. It was caught by a test, not by reading.
        //
        // The rule that works, and it is the standard one for money: the last separator is a
        // DECIMAL point only if it is followed by a number of digits other than exactly three.
        // Three trailing digits is a thousands group.
        //
        //   1.450      -> 3 digits  -> thousands -> 1450
        //   1 450,00   -> 2 digits  -> decimal   -> 1450.00
        //   91,5       -> 1 digit   -> decimal   -> 91.5
        //   1.450,50   -> 2 digits  -> decimal, and the earlier `.` is thousands
        //   1,450.50   -> 2 digits  -> decimal, and the earlier `,` is thousands
        //
        // The cost is stated rather than hidden: a genuine `1.500` meaning one-and-a-half is read as
        // 1500. Nothing this project parses is a three-decimal quantity — rents are whole euros and
        // surfaces one decimal — so the trade is entirely one-sided here.
        $lastSeparator = max(strrpos($raw, ','), strrpos($raw, '.'));

        if ($lastSeparator !== false) {
            $trailing = strlen($raw) - $lastSeparator - 1;

            if ($trailing === 3) {
                // Every separator is a thousands mark.
                $raw = str_replace([',', '.'], '', $raw);
            } else {
                $head = str_replace([',', '.'], '', substr($raw, 0, $lastSeparator));
                $raw = $head . '.' . substr($raw, $lastSeparator + 1);
            }
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }
}
