<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\TestCase;
use Scout\Core\ListingSnapshot;
use Scout\Core\RawListing;
use Scout\Core\SourceProfile;
use Scout\Core\TenureClassifier;

/**
 * `reclassify runs on evidence ⊇ original, never ⊂` — the invariant, stated where it can fail.
 *
 * Schema v7 exists so a stored verdict can be REVISED rather than merely audited, and its whole
 * safety argument rests on one property: the listing the classifier sees after a snapshot round
 * trip must produce the same verdict as the listing it saw the first time. If the snapshot loses
 * anything, `scout reclassify` does not make a smaller improvement — it makes a BREACH, and it
 * makes it preferentially on the listings most likely to be social, because those are the ones
 * whose evidence conflicts.
 *
 * **This file exists because that property was false and every other test passed.** Until
 * 2026-08-24 `ListingSnapshot::decode()` kept a field only `if (is_scalar($item))`, so an array-,
 * `null`- or object-valued field was written by the encoder and silently dropped by the decoder. A
 * review panel drove the consequence through the real CLI: a listing with
 * `fields: ['gamme' => ['PLAI','PLUS']]` and an intermediate-sounding title classified
 * `UNKNOWN`/`DIGEST`, and after one round trip `scout reclassify` promoted it to `LLI`/`MATCH` and
 * pushed a notification. The reflection test in {@see ListingSnapshotTest} could not catch it: it
 * checks that every CONSTRUCTOR PARAMETER is encoded, and `fields` was — it was the VALUE TYPE
 * inside it that nothing exercised.
 *
 * So the assertion here is deliberately not about columns. It classifies each listing twice — once
 * directly and once through `encode()`/`decode()` — and requires the two verdicts to be identical.
 * A future field, a future encoder shortcut and a future "tidy up the decoder" all fail here
 * without anyone having to think of them.
 */
final class TenureSnapshotEvidenceTest extends TestCase
{
    /**
     * Listings whose verdict depends on evidence a careless snapshot would discard.
     *
     * Every one of them is a shape `TenureClassifier` has a deliberate rule for, and every one is
     * eligible-looking on the surface that survives — which is what makes the loss dangerous rather
     * than merely lossy.
     *
     * @return iterable<string, array{RawListing}>
     */
    public static function adversarialListings(): iterable
    {
        $base = [
            'sourceName' => 'demo',
            'title' => 'T4 lumineux — logement intermédiaire',
            'commune' => 'Sartrouville',
            'postcode' => '78500',
            'rentCc' => 1450,
            'surfaceM2' => 88.0,
            'rooms' => 4,
        ];

        // The panel's own reproduction. A list-valued financing field naming two excluded regimes,
        // against a title naming an eligible one: the conflict is what withholds it, and on the
        // surviving surface alone the same listing reads as eligible.
        yield 'a list-valued financing field' => [new RawListing(
            ...$base,
            externalId: 'a-1',
            fields: ['gamme' => ['PLAI', 'PLUS']],
        )];

        // The field NAME is the evidence here, and its value is empty. `numeroUnique` is a literal
        // PROCEDURAL entry — the cheapest reliable social discriminator the domain offers — so a
        // decoder that drops empty-valued keys throws away the strongest signal on the listing
        // while looking like it is tidying.
        yield 'a null-valued procedural field' => [new RawListing(
            ...$base,
            externalId: 'a-2',
            fields: ['numeroUniqueEnregistrement' => null],
        )];

        // A nested map, which is what an adapter forwarding a JSON sub-object verbatim produces.
        yield 'a nested structured field' => [new RawListing(
            ...$base,
            externalId: 'a-3',
            fields: ['financement' => ['code' => 'PLS', 'libelle' => 'Prêt Locatif Social']],
        )];

        // The counterweight, and it is not decoration: without an ordinary listing in the set, a
        // "preserve everything" decoder that stopped normalising scalars would satisfy every case
        // above while changing what every real adapter's listing looks like to the classifier.
        yield 'an ordinary string-valued field' => [new RawListing(
            ...$base,
            externalId: 'a-4',
            fields: ['financement' => 'LLI', '_text' => 'Logement intermédiaire, attribution directe'],
        )];

        // An explicitly excluded regime stated plainly. It must stay REJECT through the round trip
        // — the direction nobody thinks to check, because it is already safe.
        yield 'an explicit PLAI' => [new RawListing(
            ...['title' => 'T4 — logement social PLAI'] + $base,
            externalId: 'a-5',
            fields: ['financement' => 'PLAI'],
        )];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adversarialListings')]
    public function testTheVerdictSurvivesTheSnapshotRoundTrip(RawListing $listing): void
    {
        $classifier = new TenureClassifier();
        // Mixed with no default: the fail-closed profile, and the one under which a lost signal
        // actually changes the answer. On a pure source the default would mask the difference.
        $profile = new SourceProfile('demo', 'institutional', null, true);

        $before = $classifier->classify($listing, $profile);
        $after = $classifier->classify(
            ListingSnapshot::decode(ListingSnapshot::encode($listing)),
            $profile,
        );

        self::assertSame(
            $before->tenure,
            $after->tenure,
            'the snapshot changed the tenure — reclassify would run on evidence the original did not have',
        );
        self::assertSame(
            $before->outcome,
            $after->outcome,
            'the snapshot changed the OUTCOME, which is the transition scout reclassify notifies on',
        );
        self::assertSame(
            $before->confidenceBp,
            $after->confidenceBp,
            'the snapshot changed the confidence, which decides whether a mixed-source listing digests or matches',
        );
    }

    /**
     * And the set itself must keep exercising the dangerous direction.
     *
     * A cross-check on the fixtures rather than on the code: if every adversarial listing above
     * were quietly edited into something that classifies cleanly, the round-trip assertions would
     * all still pass and prove nothing. At least one case must be a listing the classifier REFUSES
     * to call eligible on the strength of its structured evidence.
     */
    public function testTheSetStillContainsAListingHeldBackByItsStructuredEvidence(): void
    {
        $classifier = new TenureClassifier();
        $profile = new SourceProfile('demo', 'institutional', null, true);

        $withheld = 0;
        foreach (self::adversarialListings() as [$listing]) {
            if (!$classifier->classify($listing, $profile)->tenure->isEligible()) {
                ++$withheld;
            }
        }

        self::assertGreaterThan(
            0,
            $withheld,
            'every case now classifies as eligible, so the round trip is no longer being asked the question that matters',
        );
    }
}
