<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * Postcode → French departement name, for the eight of Île-de-France.
 *
 * A commune name alone is genuinely ambiguous here: there is a Neuilly in three of these
 * departements, a Villeneuve in four, and a notification that says only *"Neuilly"* asks the reader
 * to click to find out whether it is Neuilly-sur-Seine or Neuilly-sur-Marne. The postcode is the
 * disambiguator every source already provides; the departement NAME is what makes it readable
 * without knowing that 93 is Seine-Saint-Denis.
 *
 * **Île-de-France only, deliberately, and unknown means silent.** The criteria filter on these eight
 * prefixes, so nothing else can reach a notification — and a source like Logirep publishes
 * nationally, so an unexpected postcode has to be ordinary rather than exceptional. Shipping all
 * 101 departements would be a table nobody here reads; guessing a name from a prefix would be
 * worse. What this must never do is throw: a formatter that dies on an unexpected postcode takes
 * down the whole pass for a listing it was only ever going to reject.
 */
final class Department
{
    /** @var array<string, string> */
    private const array IDF = [
        '75' => 'Paris',
        '77' => 'Seine-et-Marne',
        '78' => 'Yvelines',
        '91' => 'Essonne',
        '92' => 'Hauts-de-Seine',
        '93' => 'Seine-Saint-Denis',
        '94' => 'Val-de-Marne',
        '95' => "Val-d'Oise",
    ];

    /**
     * `"Yvelines (78)"`, or `null` when the postcode is absent, malformed or outside Île-de-France.
     *
     * Non-digits are stripped before the prefix is read, the same normalisation
     * {@see \RentWatch\Config\Criteria::matchesCommune()} applies — a source that writes `78 500`
     * or `F-78500` is describing the same place, and a display that disagreed with the filter about
     * which departement a listing is in would be worse than saying nothing.
     */
    public static function label(?string $postcode): ?string
    {
        $name = self::name($postcode);
        if ($name === null) {
            return null;
        }

        return $name . ' (' . substr(self::digits($postcode), 0, 2) . ')';
    }

    /** The bare name, for callers that want to compose their own text. */
    public static function name(?string $postcode): ?string
    {
        $digits = self::digits($postcode);

        // Five digits required rather than two: `78` on its own is a departement code, not a
        // postcode, and treating a truncated value as a location is how a display starts asserting
        // something the data never said.
        if (strlen($digits) !== 5) {
            return null;
        }

        return self::IDF[substr($digits, 0, 2)] ?? null;
    }

    private static function digits(?string $postcode): string
    {
        return preg_replace('~\D+~', '', (string) $postcode) ?? '';
    }
}
