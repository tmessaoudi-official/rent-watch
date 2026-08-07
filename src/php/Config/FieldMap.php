<?php

declare(strict_types=1);

namespace RentWatch\Config;

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
    public function allPaths(): array
    {
        return array_merge(
            $this->ref, $this->title, $this->url, $this->commune, $this->postcode,
            $this->rent, $this->rentHc, $this->charges, $this->surface, $this->rooms, $this->bedrooms,
            $this->floor, $this->elevator, $this->description, $this->tenureField,
        );
    }

    /** @throws ConfigError */
    public static function fromReader(Reader $r): self
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

        if ($ref === []) {
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
