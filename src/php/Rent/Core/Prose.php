<?php

declare(strict_types=1);

namespace Scout\Rent\Core;
use Scout\Core\Text;

/**
 * Reads a floor and a lift out of French listing PROSE.
 *
 * This is a different job from `Payload::floor()` and `Payload::bool()`, which read a structured
 * field, and it is deliberately a different class rather than an extension of them.
 *
 * It exists because In'li — about two thirds of all matches — states both facts only in the
 * description. Measured on 20 live detail pages (2026-08-23): 18 mention `ascenseur`, 19 state a
 * floor, and neither is ever a field.
 *
 * Two rules carry the whole class, and both are hard rule 9 read forwards:
 *
 *  - **A floor is a POSITION, never a COUNT.** French says `au N<ordinal> étage` for where the flat
 *    is and `de N étages` for how tall the building is. Reading the second as the first reports a
 *    3rd-floor flat as being on the 4th — a wrong DISPLAYED fact, which nothing downstream catches.
 *  - **A lift reads its NEGATION first.** A wrong `false` lowers the score, which is the safe
 *    direction; a wrong `true` awards a bonus for a lift that does not exist. Five of the 18
 *    captured mentions are negations, so this is the common case, not the corner.
 *
 * Both readers UNDER-extract on purpose. `null` says nothing, and saying nothing is always safe.
 */
final class Prose
{
    /**
     * Ordinals as French writes them for a floor. Cardinals are deliberately absent: `de trois
     * étages` is a building's height, and leaving cardinals out of this table is what makes the
     * spelled-number case discriminate itself for free.
     *
     * @var array<string, int>
     */
    private const SPELLED_ORDINALS = [
        'premier' => 1, 'premiere' => 1, '1er' => 1, '1ere' => 1,
        'deuxieme' => 2, 'second' => 2, 'seconde' => 2,
        'troisieme' => 3, 'quatrieme' => 4, 'cinquieme' => 5, 'sixieme' => 6,
        'septieme' => 7, 'huitieme' => 8, 'neuvieme' => 9, 'dixieme' => 10,
        'onzieme' => 11, 'douzieme' => 12, 'treizieme' => 13, 'quatorzieme' => 14,
        'quinzieme' => 15, 'seizieme' => 16, 'dix-septieme' => 17, 'dix-huitieme' => 18,
        'dix-neuvieme' => 19, 'vingtieme' => 20,
    ];

    /**
     * A ground-floor mention governed by one of these is describing the BUILDING's shops, not the
     * tenant's flat. `des commerces en rez-de-chaussée` is ordinary French listing copy.
     */
    private const GROUND_FLOOR_FURNITURE = [
        'commerce', 'commerces', 'boutique', 'boutiques', 'local', 'locaux',
        'parking', 'parkings', 'superette', 'supermarche', 'epicerie', 'restaurant',
    ];

    /**
     * The reserved prefix that turns a field-map capture into a named reader.
     *
     * `=> prose:floor` rather than an object form, because the field map's every entry is typed
     * `list<string>` and shared with the JSON adapter — an object would ripple through `allPaths()`
     * and both adapters for a feature only HTML uses. The prefix is RESERVED rather than merely
     * conventional: an unknown name after it refuses at load, so `prose:flor` cannot be compiled as
     * an ordinary regex that matches nothing while the config looks deliberate.
     */
    public const string READER_PREFIX = 'prose:';

    /** How far back from `ascenseur` a marker is allowed to govern it. */
    private const LIFT_WINDOW = 40;

    /**
     * How far AFTER the noun a denial may sit and still be read as denying it.
     *
     * Much shorter than {@see LIFT_WINDOW} on purpose: a backward window is scanning a sentence
     * for its verb, while this is only reaching across the separator in a spec-block row
     * (`Ascenseur : non`).
     *
     * **It is a bound on the scan, not the guarantee**, and this sentence used to claim otherwise
     * — *"widening it would start reading the NEXT field's value"*. Round 8 set it to 200 and the
     * whole suite stayed green, because the ADJACENCY rule below (only separators may sit between
     * the noun and the denial) already excludes anything with a word in it. Recorded rather than
     * pinned with a case invented to make it look load-bearing: dead safety code reads as a second
     * line of defence and is not one.
     */
    private const LIFT_TRAILING_WINDOW = 16;

    private const LIFT_NEGATIONS = ['sans', 'aucun', 'aucune', 'pas d', 'depourvu', 'depourvue', 'ni '];

    private const LIFT_ASSERTIONS = ['avec', 'dispose', 'possede', 'equipe', 'presence', 'par', 'dote', 'offre'];

    /**
     * The flat's own floor, or `null` when the prose does not state one.
     *
     * `0` is the rez-de-chaussée and is a REAL floor (hard rule 9) — it is not "no answer".
     */
    public static function floor(string $text): ?int
    {
        $folded = Text::foldTolerant($text);

        // Unreadable text states nothing. It must not crash a pass, and it must not be scored as a
        // listing that mentioned no floor either — both come out as `null`, which says nothing.
        if ($folded === null || $folded === '') {
            return null;
        }

        // A POSITION: `au`/`en` + an ordinal + the SINGULAR `etage`. `\b` on `etage` is what keeps
        // `de 18 etages` out; the `au|en` anchor is what keeps a bare count out even when singular.
        //
        // `(?:\s+et\s+dernier)?` because `au 9e et dernier étage` is how French says top floor, and
        // dropping the listing's floor over the word `dernier` would be a silly way to lose one.
        $ordinal = '(?:\d+\s*(?:er|ere|eme|e)?|' . implode('|', array_keys(self::SPELLED_ORDINALS)) . ')';
        $pattern = '/\b(?:au|en)\s+(?:le\s+)?(' . $ordinal . ')(?:\s+et\s+dernier)?\s*etage\b/';

        if (preg_match($pattern, $folded, $m) === 1) {
            return self::ordinalValue($m[1]);
        }

        // The ground floor, and only when it is the FLAT's. Checked after the ordinal on purpose:
        // when a page states both — `appartement au 5e étage. Des commerces en rez-de-chaussée.` —
        // the anchored position is the one that is about the tenant.
        if (preg_match('/\b(?:au|en)\s+(?:le\s+)?(rdc|rez.?de.?chaussee)\b/', $folded, $m, PREG_OFFSET_CAPTURE) === 1) {
            return self::groundFloorIsTheFlats($folded, (int) $m[0][1]) ? 0 : null;
        }

        return null;
    }

    /**
     * `true` when the prose asserts a lift, `false` when it denies one, `null` when it says nothing.
     *
     * An UNMENTIONED lift is not an absent one — that distinction is the whole reason the high-floor
     * penalty requires an explicit `false` rather than the negation of the bonus.
     */
    public static function elevator(string $text): ?bool
    {
        $folded = Text::foldTolerant($text);

        if ($folded === null || $folded === '') {
            return null;
        }

        $verdict = null;
        $offset = 0;

        while (preg_match('/ascenseur/', $folded, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = (int) $m[0][1];
            $offset = $at + 1;

            $from = max(0, $at - self::LIFT_WINDOW);
            $window = substr($folded, $from, $at - $from);

            // NEAREST marker wins, not first-found. `ne dispose pas d'ascenseur` contains an
            // assertion verb AND a negation; the negation is the one adjacent to the noun. The
            // mirror case is `SANS FRAIS DE DOSSIER … avec ascenseur`, where the assertion is
            // adjacent and the `sans` belongs to something else entirely.
            $negation = self::lastMarker($window, self::LIFT_NEGATIONS);
            $assertion = self::lastMarker($window, self::LIFT_ASSERTIONS);

            if ($negation !== null && ($assertion === null || $negation > $assertion)) {
                // Any denial anywhere settles it. A page that both asserts and denies a lift is
                // ambiguous, and ambiguity resolves toward the lower score.
                return false;
            }

            // A TRAILING denial, which the backward window structurally cannot see.
            //
            // The comment below used to justify the bare-noun assertion with "a denial always
            // carries a marker, so a bare noun cannot be one" — true only of a marker placed
            // BEFORE the noun. `Ascenseur : non` is an ordinary French spec-block row and would
            // arrive as prose from any `<p>`-scoped selector, and it read as `true`: a bonus
            // awarded for a lift that does not exist, which is the direction this class's own
            // docblock forbids. Found by a review panel on 2026-08-24.
            //
            // Deliberately narrow. The denial must be ADJACENT — only separators between it and
            // the noun — and must END the phrase, so `ascenseur non conforme` (a lift that exists
            // and is out of service) is not read as an absent one.
            //
            // The `u` flag is needed for the en/em dashes real listing copy uses as separators.
            // `preg_match` returns `false` rather than `1` on invalid UTF-8, so a payload that
            // somehow reaches here unfolded falls through to the pre-existing behaviour instead of
            // erroring — under-extraction being the safe direction.
            $after = substr($folded, $at + \strlen('ascenseur'), self::LIFT_TRAILING_WINDOW);

            if (preg_match(
                // The leading class is INTRA-ROW only — no `.`, no `|`, no newline. With `.` in it
                // this reader reached across a sentence boundary and answered for the NEXT
                // sentence's negation: `…dispose d'un ascenseur. Aucun ascenseur dans la
                // résidence.` returned `null` instead of letting the backward scan find the real
                // denial. Caught by the case written for that exact shape.
                '/^[\s:=\-\x{2013}\x{2014}]*\b(?:non|aucun|aucune|pas)\b(?<tail>.*)$/us',
                $after,
                $trailing,
            ) === 1) {
                // AN ADJACENT TRAILING DENIAL IS NEVER `true`. Which of the two safe answers it
                // gets depends on what follows it:
                //
                //   `Ascenseur : non`            -> FALSE. The row denies the lift.
                //   `Ascenseur : non renseigné`  -> NULL.  "not stated" is unknown, not absent.
                //   `Ascenseur non conforme`     -> NULL.  A lift that exists and is out of order.
                //
                // The first version of this reader required the denial to END the phrase, which
                // took `non renseigné` / `non communiqué` / `non disponible` with it — the
                // commonest French spec values for "unknown" — and defaulted them to `true`, the
                // one direction this class's docblock forbids. Two review lenses found that
                // independently. Vocabulary would have been the wrong fix: the list is open, and
                // `non conforme` is not in it. The STRUCTURE decides instead, and both branches
                // are safe — `null` says nothing, `false` only lowers the score.
                // Only the REST OF THIS ROW counts. `Ascenseur : non | Balcon : oui` denies the
                // lift and then starts a different field, so the tail is cut at the first row
                // separator before being weighed — otherwise the next field's value would decide
                // this one's verdict.
                $tail = (string) ($trailing['tail'] ?? '');
                $row = preg_split('/[\r\n|;\/.\x{2013}\x{2014}]/u', $tail, 2);
                $tail = trim(\is_array($row) ? (string) $row[0] : $tail, " \t:-");

                return $tail === '' ? false : null;
            }

            // The noun alone asserts the lift: French amenity prose says `ascenseur, gardien,
            // parking` without a verb.
            $verdict = true;
        }

        return $verdict;
    }

    /**
     * The reader named by a capture entry, or `null` when the entry is an ordinary regex.
     *
     * A capture carrying the reserved prefix and an UNKNOWN name is a config error, not a regex —
     * see {@see readerNames()} and the loader's refusal.
     */
    public static function readerIn(string $capture): ?string
    {
        $trimmed = trim($capture);

        if (!str_starts_with($trimmed, self::READER_PREFIX)) {
            return null;
        }

        return substr($trimmed, strlen(self::READER_PREFIX));
    }

    /** @return list<string> */
    public static function readerNames(): array
    {
        return ['floor', 'elevator'];
    }

    /**
     * Run a named reader, yielding a token the ORDINARY `Payload` readers already understand.
     *
     * That indirection is deliberate. `prose:floor` returns `'3'` and `prose:elevator` returns
     * `'oui'`/`'non'`, so typing still happens exactly once, in `Payload`, through `ListingMapper` —
     * hard rule 9 keeps its single implementation and this class stays pure string-in, string-out.
     *
     * `'0'` is a real answer here (the rez-de-chaussée) and must survive every emptiness check
     * between this line and the listing.
     */
    public static function read(string $name, string $text): ?string
    {
        return match ($name) {
            'floor' => self::floor($text) === null ? null : (string) self::floor($text),
            'elevator' => match (self::elevator($text)) {
                true => 'oui',
                false => 'non',
                null => null,
            },
            default => null,
        };
    }

    private static function ordinalValue(string $raw): ?int
    {
        $token = trim($raw);

        if (preg_match('/^(\d+)/', $token, $m) === 1) {
            return (int) $m[1];
        }

        return self::SPELLED_ORDINALS[$token] ?? null;
    }

    /**
     * A ground-floor mention belongs to the flat unless a shop is standing in front of it.
     */
    private static function groundFloorIsTheFlats(string $folded, int $at): bool
    {
        $from = max(0, $at - self::LIFT_WINDOW);
        $window = substr($folded, $from, $at - $from);

        foreach (self::GROUND_FLOOR_FURNITURE as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $window) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Offset of the LAST of these markers in the window, or `null` when none appears.
     *
     * @param list<string> $markers
     */
    private static function lastMarker(string $window, array $markers): ?int
    {
        $best = null;

        foreach ($markers as $marker) {
            $at = strrpos($window, $marker);

            if ($at !== false && ($best === null || $at > $best)) {
                $best = $at;
            }
        }

        return $best;
    }
}
