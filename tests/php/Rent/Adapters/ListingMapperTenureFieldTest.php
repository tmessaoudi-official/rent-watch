<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\ListingMapper;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;

/**
 * TRACK 6-A3 / audit N5 — `tenure_field` is INERT on the JSON path, and it is §1-adjacent.
 *
 * `FieldMap` calls `tenureField` "the highest-value mapping in the file", `/add-source` step 4 says
 * "look hard for it", and `TenureClassifier::TENURE_FIELDS` carries the literal key `tenurefield`
 * precisely so this project's own declaration counts as tier-1 evidence.
 *
 * On the HTML path it works, by a renaming that happens OUTSIDE the mapper: `HtmlSource::flatMapped()`
 * rewrites every selector to a literal key, so the extracted array really does carry `tenureField`
 * and `Payload::flatten()` hands it to the classifier under a name it recognises.
 *
 * On the JSON path there is no such renaming. `map()` sets `fields: Payload::flatten($item)` — the
 * portal's RAW keys — and `$map->tenureField` is never read at all. So a source mapping
 * `tenure_field: "regime"` produces no tier-1 signal whatsoever, silently.
 *
 * **THE AUDIT'S STATED EXPOSURE IS NOT REPRODUCIBLE, and saying so is the point of this file.**
 * N5 filed it as "a future JSON source mapping a non-standard tenure key would silently lose the
 * signal". Measured against the real classifier before any fix was written:
 *
 *     financement: PLS  -> PLS tier 1      regime: PLS  -> PLS tier 1
 *     financement: LLI  -> LLI conf 97     regime: LLI  -> LLI conf 97
 *
 * identical, because the unknown-field path was ALREADY hardened to scan any field's value for
 * excluded vocabulary — the long note at `TenureClassifier`'s `TENURE_FIELDS` check records why,
 * after that closed list stood between a spelled-out `PLAI` and a notification.
 *
 * So NO VERDICT CHANGES. What the fix removes is the ASYMMETRY: a config key that acts on one
 * adapter type and does nothing on the other, whose safety currently rests on a fallback rather
 * than on the declaration the operator actually wrote. That is worth closing on its own — it is
 * the same shape as F27 and as `title_pattern` before it — but it is not the §1 hole the audit
 * described, and pretending otherwise would put a false claim in the register.
 */
#[CoversClass(ListingMapper::class)]
final class ListingMapperTenureFieldTest extends TestCase
{
    /**
     * @param array<string,mixed> $map
     */
    private function mapper(array $map): ListingMapper
    {
        $definitions = ConfigLoader::sourcesFromArray([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'institutional',
                    'type' => 'json',
                    'default_tenure' => 'LLI',
                    'mixed_tenure' => true,
                    'url' => 'https://example.test/api',
                    'items_path' => 'items',
                    'map' => $map,
                ],
            ],
        ]);

        return new ListingMapper($definitions['probe']);
    }

    /**
     * The mechanism, asserted where it is real: a mapped tenure field lands under the literal key
     * the classifier treats as THIS PROJECT'S OWN declaration, whatever the portal calls it.
     */
    public function testAMappedTenureFieldIsDeclaredUnderTheKeyTheClassifierKnows(): void
    {
        $listing = $this->mapper([
            'ref' => 'id',
            'title' => 'titre',
            'tenure_field' => 'regime',
        ])->map(['id' => 'a1', 'titre' => 'T3 à Dourdan', 'regime' => 'PLS']);

        self::assertSame('PLS', $listing->fields['tenureField'] ?? null);
        // The raw key is still there too — a mapped value must never REPLACE the payload it came
        // from, because the classifier reads field NAMES as evidence in their own right.
        self::assertSame('PLS', $listing->fields['regime'] ?? null);
    }

    /** And §1 still holds through it, which is the assertion that would notice a regression. */
    public function testSuchAListingIsStillExcluded(): void
    {
        $listing = $this->mapper([
            'ref' => 'id',
            'title' => 'titre',
            'tenure_field' => 'regime',
        ])->map(['id' => 'a1', 'titre' => 'T3 à Dourdan', 'regime' => 'PLS']);

        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'probe', defaultTenure: Tenure::LLI, mixedTenure: true),
        );

        self::assertSame(Tenure::PLS, $classification->tenure);
        self::assertTrue($classification->tenure->isExcluded(), '§1: PLS is never surfaced');
    }

    /**
     * THE COUNTERWEIGHT. `fields` is deliberately the WHOLE structured surface — the classifier
     * reads field NAMES as evidence, so a `financement` nobody thought to map must still be seen.
     * A fix that replaced the flattened payload with the mapped fields would break that, and this
     * is the assertion that says so.
     */
    public function testTheWholeRawSurfaceIsStillHandedToTheClassifier(): void
    {
        $listing = $this->mapper(['ref' => 'id', 'title' => 'titre'])
            ->map(['id' => 'a1', 'titre' => 'T3', 'financement' => 'PLAI']);

        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'probe', defaultTenure: Tenure::LLI, mixedTenure: true),
        );

        self::assertSame(Tenure::PLAI, $classification->tenure, 'an UNMAPPED financing key must still be read');
    }

    /** A source that maps no tenure field is unchanged — nothing is invented. */
    public function testAnUnmappedTenureFieldAddsNothing(): void
    {
        $listing = $this->mapper(['ref' => 'id', 'title' => 'titre'])->map(['id' => 'a1', 'titre' => 'T3']);

        self::assertArrayNotHasKey('tenureField', $listing->fields);
    }
}
