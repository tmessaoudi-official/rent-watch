<?php

declare(strict_types=1);

namespace Scout\Rent\Config;

use Scout\Rent\Core\Prose;
use Scout\Config\ConfigError;
use Scout\Config\Reader;

/**
 * Where each listing field lives inside one source's raw item.
 *
 * Every entry is a **list of candidate dotted paths**, first non-empty wins, because real payloads
 * move a field between two shapes across a redesign and carrying both costs one array element. A
 * single string in the config is normalised to a one-element list here, so the consumer has one shape
 * to handle rather than two.
 *
 * `chargesIncluded` is NOT a path — it is a declaration about what the `rent` path means, and it is
 * **required** whenever a rent path is mapped. Sources disagree on charges comprises vs hors charges
 * (`CLAUDE.md` hard rule 9) and a default either way would silently mis-file a whole source's rents
 * by the size of its charges — roughly 10% of the budget, comfortably enough to move listings across
 * the 1800 € cutoff in both directions.
 *
 * `tenureField` is the highest-value mapping in the file: a structured financing field is signal
 * tier 1, the only tier that reaches 97 confidence on its own.
 */
final readonly class FieldMap
{
    /**
     * Every mappable field, named as this class's own constructor names them.
     *
     * Written out rather than reflected, so that adding a field to the constructor without deciding
     * what it means to an adapter is a visible omission here instead of a silently unresolved
     * selector. It lives on `FieldMap` rather than on an adapter because more than one adapter walks
     * it — {@see \Scout\Rent\Adapters\HtmlSource} over a card element, {@see
     * \Scout\Rent\Adapters\DetailHydrator} over a detail document — and two copies of a list whose
     * whole job is to make an omission visible is two places to forget.
     *
     * `chargesIncluded` is deliberately absent: it is a declaration about what `rent` MEANS, not a
     * path, and nothing resolves a selector for it.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'ref', 'title', 'url', 'commune', 'postcode', 'rent', 'rentHc', 'charges',
        'surface', 'rooms', 'bedrooms', 'floor', 'elevator', 'description', 'tenureField',
    ];

    /**
     * @param list<string> $ref         the source's own stable id — the basis of within-source dedup
     * @param list<string> $title
     * @param list<string> $url
     * @param list<string> $commune
     * @param list<string> $postcode
     * @param list<string> $rent
     * @param list<string> $rentHc  explicit hors-charges path, for a source that publishes BOTH
     * @param list<string> $charges
     * @param list<string> $surface
     * @param list<string> $rooms
     * @param list<string> $bedrooms
     * @param list<string> $floor
     * @param list<string> $elevator
     * @param list<string> $description
     * @param list<string> $tenureField structured financing field — signal tier 1
     */
    public function __construct(
        public array $ref = [],
        public array $title = [],
        public array $url = [],
        public array $commune = [],
        public array $postcode = [],
        public array $rent = [],
        public array $rentHc = [],
        public array $charges = [],
        public array $surface = [],
        public array $rooms = [],
        public array $bedrooms = [],
        public array $floor = [],
        public array $elevator = [],
        public array $description = [],
        public array $tenureField = [],
        public ?bool $chargesIncluded = null,
    ) {}

    /** Every mapped path, flattened — used to check a field map against a committed fixture. */
    /**
     * A stable identity for THIS map's content, used to spot that a detail map has changed.
     *
     * Field-AWARE on purpose: `allPaths()` flattens every list into one, so moving a selector from
     * `floor` to `elevator` leaves it identical while changing what the map extracts. A fingerprint
     * that misses that is worse than none, because it certifies staleness as freshness.
     *
     * Content-addressed, so reformatting `sources.json`, reordering its keys or editing a `_comment`
     * changes nothing — only the selectors do.
     */
    public function fingerprint(): string
    {
        $shape = [
            'ref' => $this->ref, 'title' => $this->title, 'url' => $this->url,
            'commune' => $this->commune, 'postcode' => $this->postcode,
            'rent' => $this->rent, 'rent_hc' => $this->rentHc, 'charges' => $this->charges,
            'surface' => $this->surface, 'rooms' => $this->rooms, 'bedrooms' => $this->bedrooms,
            'floor' => $this->floor, 'elevator' => $this->elevator,
            'description' => $this->description, 'tenure_field' => $this->tenureField,
        ];

        return substr(hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR)), 0, 16);
    }

    public function allPaths(): array
    {
        return array_merge(
            $this->ref, $this->title, $this->url, $this->commune, $this->postcode,
            $this->rent, $this->rentHc, $this->charges, $this->surface, $this->rooms, $this->bedrooms,
            $this->floor, $this->elevator, $this->description, $this->tenureField,
        );
    }

    /**
     * A DETAIL map: the same shape, minus the one field it must never carry.
     *
     * `ref` is required of a card map and refused here, and both rules have the same reason.
     * Identity belongs to the card, because that is what the seen-set is keyed on — a detail map
     * that redefined `ref` could re-identify a listing halfway through a pass and re-notify it on
     * every run. {@see \Scout\Rent\Core\RawListing::mergedWith()} ignores detail identity outright,
     * so a `ref` here would be config that reads as behaviour and does nothing.
     *
     * @throws ConfigError
     */
    public static function detailFromReader(Reader $r): self
    {
        $map = self::fromReader($r, requireRef: false);

        if ($map->ref !== []) {
            throw ConfigError::at(
                $r->pointer() . '.ref',
                'a detail map must not redefine `ref` — identity comes from the card, and a listing '
                    . 're-identified mid-pass is re-notified on every run',
            );
        }

        return $map;
    }

    /** @throws ConfigError */
    public static function fromReader(Reader $r, bool $requireRef = true): self
    {
        $paths = static function (string $key) use ($r): array {
            if (!$r->has($key)) {
                return [];
            }

            $raw = $r->takeRaw($key);
            if (is_string($raw)) {
                $raw = [$raw];
            }
            if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
                throw ConfigError::at(
                    $r->pointer() . '.' . $key,
                    'expected a dotted path or a non-empty array of dotted paths',
                );
            }

            $out = [];
            foreach ($raw as $i => $path) {
                if (!is_string($path) || trim($path) === '') {
                    throw ConfigError::at(
                        $r->pointer() . '.' . $key . '[' . $i . ']',
                        'expected a non-empty dotted path string',
                    );
                }

                // `prose:` in a capture names a READER, and an unknown name refuses here rather
                // than falling through to be compiled as a regex. `prose:flor` is a perfectly valid
                // pattern that matches nothing, so without this the field would read `null` for
                // ever while the config looked deliberate — the same shape as a `detail_map` that
                // can never run, and refused for the same reason.
                $capture = strpos($path, '=>');
                if ($capture !== false) {
                    $tail = substr($path, $capture + 2);
                    $reader = Prose::readerIn($tail);

                    if ($reader !== null && !in_array($reader, Prose::readerNames(), true)) {
                        throw ConfigError::at(
                            $r->pointer() . '.' . $key . '[' . $i . ']',
                            sprintf(
                                'unknown reader « %s%s » — known readers are %s%s',
                                Prose::READER_PREFIX,
                                $reader,
                                Prose::READER_PREFIX,
                                implode(', ' . Prose::READER_PREFIX, Prose::readerNames()),
                            ),
                        );
                    }

                    // A CAPTURE THAT IS NOT A READER IS A REGEX, AND IT MUST COMPILE.
                    //
                    // `Selector::captureFrom()` applies it with `@preg_match`, which neither warns
                    // nor throws: a broken pattern returns `false`, is read as *no match*, and the
                    // field resolves to `null` for every item on every pass, for ever. The source
                    // keeps returning its usual count, no run fails, and `SourceHealth` stays green
                    // — the In'li `cp` shape, where one dead selector meant a source matched zero
                    // flats while reporting `ok`.
                    //
                    // A broken pattern is a STATE rather than an event: every extraction fails for
                    // the same reason and no retry can change it, so it belongs with the load-time
                    // refusals, not with the per-listing failures that are recorded and counted.
                    // `params` patterns have been compile-checked since round 1; the field maps are
                    // the other half of that same surface and had nothing.
                    //
                    // DELIMITED EXACTLY AS THE ADAPTER WILL DELIMIT IT — `~…~u`, with `~` escaped —
                    // because a check that compiles a different string from the one that runs can
                    // pass on a pattern the adapter then rejects, which is a guard that reads as a
                    // second line of defence and is not one.
                    $pattern = trim($tail);
                    if ($reader === null && $pattern !== '' && @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', '') === false) {
                        throw ConfigError::at(
                            $r->pointer() . '.' . $key . '[' . $i . ']',
                            sprintf(
                                'expression régulière invalide « %s » — elle ne compile pas, donc '
                                . 'elle ne capturera jamais rien et le champ restera vide à chaque '
                                . 'passe, sans qu’aucune exécution n’échoue',
                                $pattern,
                            ),
                        );
                    }
                }

                $out[] = $path;
            }

            return $out;
        };

        $ref = $paths('ref');
        $rent = $paths('rent');
        $rentHc = $paths('rent_hc');

        $chargesIncluded = null;
        if ($r->has('charges_included')) {
            $chargesIncluded = $r->requireBool('charges_included');
        }

        $map = new self(
            ref: $ref,
            title: $paths('title'),
            url: $paths('url'),
            commune: $paths('commune'),
            postcode: $paths('cp'),
            rent: $rent,
            rentHc: $rentHc,
            charges: $paths('charges'),
            surface: $paths('surface'),
            rooms: $paths('rooms'),
            bedrooms: $paths('bedrooms'),
            floor: $paths('floor'),
            elevator: $paths('elevator'),
            description: $paths('description'),
            tenureField: $paths('tenure_field'),
            chargesIncluded: $chargesIncluded,
        );
        $r->done();

        if ($requireRef && $ref === []) {
            throw ConfigError::at(
                $r->pointer() . '.ref',
                'required — without a stable id every run re-notifies every listing',
            );
        }

        // `rent_hc` is unambiguous by its own name, so it needs no declaration — but pairing it
        // with a `rent` that is ALSO hors charges would give two HC paths and no CC one, which is
        // silently the same rent twice rather than the pair it looks like.
        if ($rentHc !== [] && $chargesIncluded === false) {
            throw ConfigError::at(
                $r->pointer() . '.rent_hc',
                'mapped alongside a `rent` that is itself hors charges. Map `rent` to the charges-'
                    . 'comprises figure and `rent_hc` to the hors-charges one, or drop one of them',
            );
        }

        if ($rent !== [] && $chargesIncluded === null) {
            throw ConfigError::at(
                $r->pointer() . '.charges_included',
                'required whenever `rent` is mapped — say true or false, never leave it to a default. '
                    . 'Sources disagree, and guessing mis-files a whole source by the size of its charges',
            );
        }

        return $map;
    }
}
