<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\TestCase;
use RentWatch\Core\RawListing;

/**
 * `RawListing::mergedWith()` — the rule a detail fetch is merged under.
 *
 * Written after a sabotage run found three of its guarantees undetected. They were "covered" only
 * through `HtmlSource`, and the adapter hides two of them: it injects the CARD's ref into the detail
 * listing before merging, so a merge that preferred the detail's identity behaved identically and
 * every test stayed green. A guarantee that can only be observed through a caller which neutralises
 * it is not tested — it is assumed.
 */
final class RawListingMergeTest extends TestCase
{
    public function testTheDetailWinsWhereItHasAValue(): void
    {
        $merged = $this->card()->mergedWith($this->detail(rentCc: 1200, description: 'Logement intermédiaire'));

        self::assertSame(1200, $merged->rentCc);
        self::assertSame('Logement intermédiaire', $merged->description);
    }

    /** Hard rule 9: `null` is *unknown*, and unknown overwrites nothing. */
    public function testANullDetailValueNeverErasesTheCardsValue(): void
    {
        $merged = $this->card()->mergedWith($this->detail());

        self::assertSame(880, $merged->rentCc);
        self::assertSame(3, $merged->rooms);
        self::assertSame('HOUILLES', $merged->commune);
        self::assertTrue($merged->hasElevator, 'false and null are different facts, and this one was true');
    }

    /**
     * An empty string is an absent value, not a value of nothing.
     *
     * A selector that matched an empty element found NO text. Letting `''` win turns a good card
     * into a blank one — and a blank title or description is not visibly wrong in a digest, it just
     * quietly stops carrying the words the classifier reads.
     */
    public function testAnEmptyDetailStringNeverOverwritesTheCardsOwnText(): void
    {
        $merged = $this->card()->mergedWith($this->detail(title: '', description: ''));

        self::assertSame('Appartement 3 pièces', $merged->title);
        self::assertSame('Le texte de la carte', $merged->description);
    }

    /**
     * Identity comes from the card, always — even when the detail page names another.
     *
     * This is the guarantee the adapter cannot demonstrate, because it hands the detail mapper the
     * card's own ref before merging. Asserted here with two DIFFERENT ids, which is the only way to
     * see which one survives. A listing re-identified mid-pass is a listing the seen-set has never
     * seen, so it is announced again on every run forever.
     */
    public function testIdentityAlwaysComesFromTheCardEvenWhenTheDetailCarriesAnother(): void
    {
        $merged = $this->card()->mergedWith(
            new RawListing(sourceName: 'other-source', externalId: 'DIFFERENT-ID', title: 'x'),
        );

        self::assertSame('229605', $merged->externalId, 'the seen-set is keyed on this');
        self::assertSame('cityloger', $merged->sourceName);
    }

    /** Fields merge per key: the detail adds what it knows without discarding the card's own. */
    public function testFieldsMergePerKeyAndTheCardsOwnTextSurvives(): void
    {
        $merged = $this->card()->mergedWith(
            new RawListing(
                sourceName: 'cityloger',
                externalId: '229605',
                fields: ['tenureField' => 'LI15P'],
            ),
        );

        self::assertSame('LI15P', $merged->fields['tenureField'] ?? null);
        self::assertSame(
            'Appartement 3 pièces HOUILLES 78800 880 € cc',
            $merged->fields['_text'] ?? null,
            "the card's own text is correctly scoped to the card and must survive hydration",
        );
    }

    private function card(): RawListing
    {
        return new RawListing(
            sourceName: 'cityloger',
            externalId: '229605',
            title: 'Appartement 3 pièces',
            description: 'Le texte de la carte',
            fields: ['_text' => 'Appartement 3 pièces HOUILLES 78800 880 € cc'],
            url: 'https://www.cityloger.fr/logement-a-louer-229605',
            commune: 'HOUILLES',
            postcode: '78800',
            rentCc: 880,
            rooms: 3,
            hasElevator: true,
        );
    }

    private function detail(
        ?int $rentCc = null,
        string $description = '',
        string $title = '',
    ): RawListing {
        return new RawListing(
            sourceName: 'cityloger',
            externalId: '229605',
            title: $title,
            description: $description,
            rentCc: $rentCc,
        );
    }
}
