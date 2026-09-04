<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\PatternMissLog;
use Scout\Rent\Adapters\ListingMapper;
use Scout\Rent\Config\ConfigLoader;

/**
 * TRACK 6-A3 / F27b — the extraction-miss signal reaches the four rent html/json sources.
 *
 * `PatternMissLog` was built for Track 1h, after PAP's positional patterns died and ran four days
 * unnoticed, and it shipped on ONE adapter of five. `grep -c PatternMiss` before this change:
 *
 *     EmailAlertSource 8   VehicleEmailSource 9
 *     ListingMapper 0   HtmlSource 0   HttpJsonSource 0   DetailHydrator 0   FixtureSource 0
 *
 * So inli, cdc_habitat, cityloger and logirep counted nothing, and a silently-null CSS selector or
 * JSON path was exactly as invisible as the missed regex had been. That is *a fix landing on one of
 * several symmetric surfaces*, this repo's named recurring defect.
 *
 * `ListingMapper` is the one funnel every html, json AND detail-hydration extraction passes through
 * — four construction sites, verified by grep — so instrumenting it covers all four sources and the
 * hydrator in one place, which is also why hard rule 9 has exactly one implementation.
 *
 * ## ONLY A CONFIGURED FIELD CAN MISS, and that guard is not cosmetic
 *
 * `map()` calls every `Payload::` reader unconditionally, so an UNMAPPED field returns null on every
 * item. Counting those would report a permanent 100 % miss on a field nobody asked for — measured:
 * logirep maps no `floor` and no `elevator`, and its 123 rows are 123/123 null on both. That is
 * F30's shape (a signal firing on a field the source structurally does not carry) rebuilt on four
 * sources at once, and `total()`'s rule is exactly 100 %, so it would fire every pass for ever.
 *
 * ## WHAT THIS SIGNAL DOES NOT CATCH, stated rather than discovered later
 *
 * `PatternMissLog::total()` reports a key only where `misses === calls` — a *total* failure, floor
 * three. Measured over the store, no configured field on any of the four sources is anywhere near
 * that: In'li elevator 46 %, cdc_habitat elevator 35 %, cityloger surface 16 %, everything else
 * under 10 %. So this catches the F1 shape — a field that goes to zero everywhere at once, a
 * template change — and is SILENT on a partial variant, including cityloger's real 16 % surface
 * defect. A per-field opt-out was considered and NOT built: nothing in the data needs one today,
 * and an inert config key is the defect class Track 6-A2 spent the same evening making refusable.
 */
#[CoversClass(ListingMapper::class)]
final class ListingMapperMissTest extends TestCase
{
    /**
     * @param array<string,mixed> $map
     */
    private function mapper(array $map, PatternMissLog $log, string $name = 'probe'): ListingMapper
    {
        $definitions = ConfigLoader::sourcesFromArray([
            'sources' => [
                $name => [
                    'enabled' => false,
                    'family' => 'institutional',
                    'type' => 'json',
                    'default_tenure' => 'LLI',
                    'mixed_tenure' => false,
                    'url' => 'https://example.test/api',
                    'items_path' => 'items',
                    'map' => $map,
                ],
            ],
        ]);

        return new ListingMapper($definitions[$name], $log);
    }

    /**
     * EVERY CONFIGURABLE FIELD IS COUNTED — derived from `FieldMap`, not from a list in this test.
     *
     * The signal shipped covering ten of the fourteen countable keys, and the four it missed were
     * the four that matter most, which is not a coincidence: they are the ones read somewhere other
     * than the constructor call in `map()`, so a reader scanning that call saw a complete set.
     *
     *   - `rent` and `rent_hc` are read in `rents()`. A dead rent selector nulls the rent, and
     *     `CriteriaEngine::disqualify()` guards `max_rent_cc` with `$rentCc !== null` — so it
     *     switches the rent ceiling OFF for the whole source. Nothing else moves: a nulled rent
     *     does not drop the listing, so `item_count` is unchanged, no run fails, `SourceHealth`
     *     stays `ok`.
     *   - `url` is read in `url()`. Detail hydration needs it, so a death here stops every detail
     *     page being read while the cards still map.
     *   - `tenure_field` is read in `fields()`, and it is §1. It is the classifier's TIER-1 signal;
     *     absent, the classifier falls through to the SOURCE DEFAULT, and a mixed-stock portal
     *     judges every listing by its most optimistic assumption. Silently.
     *
     * Two lenses found this independently in C2 round 5. The guard is behavioural rather than a
     * grep: every key is configured to a path that cannot resolve, and `total()` must name all of
     * them. A fifteenth key added to `FieldMap` and instrumented by nobody fails HERE.
     */
    public function testEveryConfigurableFieldMapKeyIsCounted(): void
    {
        $properties = array_values(array_filter(
            (new \ReflectionClass(\Scout\Rent\Config\FieldMap::class))->getConstructor()?->getParameters() ?? [],
            static fn (\ReflectionParameter $p): bool => (string) $p->getType() === 'array',
        ));
        self::assertNotSame([], $properties, 'reflection found no field-map keys — the guard would pass vacuously');

        // `ref` is the ONE deliberate exemption and it needs no counter: its absence THROWS a
        // `SourceError` from `map()`, which is the loudest signal available. Counting it would add
        // a key that can never reach 100 % without the run having already failed.
        $countable = array_values(array_diff(
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $properties),
            ['ref'],
        ));

        $map = ['ref' => 'id'];
        foreach ($countable as $name) {
            $map[self::CONFIG_KEYS[$name] ?? $name] = 'no_such_path_' . $name;
        }
        // Required by the loader whenever `rent` is mapped, and deliberately not a countable key:
        // it is a declaration about the source, not a path that can fail to resolve.
        $map['charges_included'] = true;

        $log = new PatternMissLog();
        $mapper = $this->mapper($map, $log);

        // THREE items, because `total()` carries a floor of 3 calls — it speaks about a field only
        // once it has had a fair chance to succeed. One item reports nothing and the assertion
        // would have passed against an empty set, which is how a guard like this quietly guards
        // nothing.
        foreach (['a1', 'a2', 'a3'] as $id) {
            $mapper->map(['id' => $id]);
        }

        $reported = $log->total();
        sort($reported);
        $expected = array_map(static fn (string $n): string => self::LOG_KEYS[$n] ?? $n, $countable);
        sort($expected);

        self::assertSame(
            $expected,
            $reported,
            'a configurable field-map key is not routed through noted(). A dead selector on it is '
            . 'then exactly as invisible as the missed PAP regex that PatternMissLog was built for.',
        );
    }

    /**
     * `FieldMap` property name → the key used in `config/rent/sources.json`.
     *
     * READ OFF `FieldMap::fromArray()` rather than guessed: `postcode` is configured as `cp`, which
     * a first draft of this guard got wrong and was told so by a `ConfigError` rather than by a
     * wrong result — the loud direction, and the reason the loader refuses unknown keys at all.
     */
    private const array CONFIG_KEYS = [
        'postcode' => 'cp',
        'rentHc' => 'rent_hc',
        'tenureField' => 'tenure_field',
    ];

    /** `FieldMap` property name → the key `PatternMissLog` records it under. */
    private const array LOG_KEYS = [
        'postcode' => 'cp',
        'rentHc' => 'rent_hc',
        'tenureField' => 'tenure_field',
    ];

    /** A configured path that yields nothing is a MISS, and something has to count it. */
    public function testAConfiguredPathThatYieldsNothingIsCounted(): void
    {
        $log = new PatternMissLog();
        $mapper = $this->mapper(['ref' => 'id', 'title' => 'titre', 'surface' => 'surface_absente'], $log);

        for ($i = 0; $i < 3; ++$i) {
            $mapper->map(['id' => 'a' . $i, 'titre' => 'Appartement']);
        }

        self::assertContains('surface', $log->total(), 'a configured field that never extracts must be reported');
        self::assertNotContains('title', $log->total(), 'a field that extracted every time must not be');
    }

    /**
     * THE GUARD THAT MAKES THE SIGNAL USABLE. logirep maps no `floor` and no `elevator`; without
     * this, every one of its passes would report both as a 100 % miss, for ever, on fields it was
     * never asked to read.
     */
    public function testAnUNMAPPEDFieldIsNeverCountedAsAMiss(): void
    {
        $log = new PatternMissLog();
        $mapper = $this->mapper(['ref' => 'id', 'title' => 'titre'], $log);

        for ($i = 0; $i < 5; ++$i) {
            $mapper->map(['id' => 'a' . $i, 'titre' => 'Appartement']);
        }

        self::assertSame([], $log->total(), 'only a CONFIGURED field can miss');
    }

    /**
     * The counterweight, and it is the half a "just report nothing" implementation would satisfy:
     * a field that extracts is not reported, and a field that extracts only SOMETIMES is not either
     * — `total()`'s rule is a total failure, and a portal's own copy legitimately varies.
     */
    public function testAFieldThatExtractsOnSomeItemsIsNotReported(): void
    {
        $log = new PatternMissLog();
        $mapper = $this->mapper(['ref' => 'id', 'title' => 'titre', 'surface' => 'surface'], $log);

        $mapper->map(['id' => 'a', 'titre' => 'T3', 'surface' => 52]);
        $mapper->map(['id' => 'b', 'titre' => 'T3']);
        $mapper->map(['id' => 'c', 'titre' => 'T3']);

        self::assertSame([], $log->total(), 'a partial miss is a varying payload, not a template change');
    }

    /** An empty string is not an answer — the rule `PatternMissLog` already states for emails. */
    public function testAnEmptyStringCountsAsAMiss(): void
    {
        $log = new PatternMissLog();
        $mapper = $this->mapper(['ref' => 'id', 'title' => 'titre', 'commune' => 'ville'], $log);

        for ($i = 0; $i < 3; ++$i) {
            $mapper->map(['id' => 'a' . $i, 'titre' => 'T3', 'ville' => '']);
        }

        self::assertContains('commune', $log->total());
    }

    /**
     * A DETAIL map's misses are counted SEPARATELY from the card map's field of the same name.
     *
     * Found on the deployed image's first pass, not by design. In'li maps `cp` on both maps — the
     * card from the URL slug, the detail page from its `<title>` — and pooled under one key the run
     * reported `cp 171/342`. `total()` speaks only at 100 %, so a card pattern missing on ALL 171
     * cards was averaged with 171 detail successes into a silent 50 %: one whole map dead, WARN
     * unreachable. The same dilution the seven-day flaky window had, one layer down.
     */
    public function testADetailMapMissIsCountedApartFromTheCardMapsFieldOfTheSameName(): void
    {
        $log = new PatternMissLog();
        $definitions = ConfigLoader::sourcesFromArray([
            'sources' => [
                'probe' => [
                    'enabled' => false, 'family' => 'institutional', 'type' => 'json',
                    'default_tenure' => 'LLI', 'mixed_tenure' => false,
                    'url' => 'https://example.test/api', 'items_path' => 'items',
                    'map' => ['ref' => 'id', 'title' => 'titre', 'cp' => 'absent_du_lien'],
                ],
            ],
        ]);

        $card = new ListingMapper($definitions['probe'], $log);
        $detail = new ListingMapper($definitions['probe'], $log, 'detail.');

        for ($i = 0; $i < 3; ++$i) {
            $card->map(['id' => 'a' . $i, 'titre' => 'T3']);                       // cp misses
            $detail->map(['id' => 'a' . $i, 'titre' => 'T3', 'absent_du_lien' => '91410']); // cp found
        }

        $counts = $log->counts();
        self::assertSame(['calls' => 3, 'misses' => 3], $counts['cp'], 'the card map alone must be 3/3');
        self::assertSame(['calls' => 3, 'misses' => 0], $counts['detail.cp'], 'the detail map is its own key');
        // And the WARN is now reachable on the dead half, which pooling made impossible.
        self::assertContains('cp', $log->total());
        self::assertNotContains('detail.cp', $log->total());
    }

    /**
     * The log is OPTIONAL, so nothing that constructs a mapper without one changes behaviour — the
     * counterweight for a change that touches the single funnel every extraction passes through.
     */
    public function testAMapperWithNoLogStillMaps(): void
    {
        $definitions = ConfigLoader::sourcesFromArray([
            'sources' => [
                'probe' => [
                    'enabled' => false, 'family' => 'institutional', 'type' => 'json',
                    'default_tenure' => 'LLI', 'mixed_tenure' => false,
                    'url' => 'https://example.test/api', 'items_path' => 'items',
                    'map' => ['ref' => 'id', 'title' => 'titre'],
                ],
            ],
        ]);

        $listing = (new ListingMapper($definitions['probe']))->map(['id' => 'x', 'titre' => 'T3']);

        self::assertSame('x', $listing->externalId);
        self::assertSame('T3', $listing->title);
    }
}
