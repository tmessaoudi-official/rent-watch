<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Payload;
use RentWatch\Config\ConfigLoader;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;

/**
 * In'li's detail page, frozen — and what the committed `detail_map` is allowed to conclude from it.
 *
 * An In'li CARD's entire text is `1 005 € cc 3 pièces · 55.32 m² Longjumeau`: four facts and no
 * title, which is why `exclude_title_patterns` has been structurally dead on the source producing
 * two thirds of the matches. Nothing has slipped through it, because In'li lists flats — luck, not
 * a filter.
 *
 * The assertions below are the ones that would go red if somebody widened a selector to "get more
 * data", which is the change that has cost this repo three fixes on three different sources.
 */
final class InliDetailFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private function page(): string
    {
        return (string) file_get_contents(self::ROOT . '/tests/fixtures/inli/detail.html');
    }

    private function selected(string $selector): string
    {
        $document = \Dom\HTMLDocument::createFromString($this->page(), LIBXML_NOERROR);
        $node = $document->querySelector($selector);

        self::assertNotNull($node, 'the committed selector `' . $selector . '` matched nothing');

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** The committed map is what is under test — not a selector written for the test. */
    public function testTheCommittedDetailMapIsTheOneAsserted(): void
    {
        $inli = ConfigLoader::loadSources(self::ROOT . '/config/sources.json')['inli'];

        self::assertNotNull($inli->detailMap);
        self::assertSame(['h1'], $inli->detailMap->title);
        self::assertSame(['.advert-body-description p'], $inli->detailMap->description);
    }

    public function testTheDetailPageSuppliesTheTitleTheCardNeverCarried(): void
    {
        self::assertSame('Appartement de 63 m² à LES ULIS', $this->selected('h1'));
    }

    public function testTheDescriptionIsTheListingsOwnProseAndNotThePage(): void
    {
        $description = $this->selected('.advert-body-description p');

        self::assertStringContainsString('appartement 3 pièces de 63.0 m²', $description);
        self::assertStringNotContainsString(
            'À propos de ce bien',
            $description,
            'that heading belongs to `.advert-body-description`, one level up — the `p` is the '
                . 'listing\'s own prose, and the difference is the whole discipline',
        );
    }

    /**
     * NO LIFT IS ON THIS PAGE, and that is a fact worth pinning rather than a gap worth hiding.
     *
     * `ascenseur` appears nowhere, so the lift stays `null` — which says nothing, and is a different
     * fact from `false`, which would say *sans ascenseur* and let the high-floor penalty fire
     * (hard rule 9). If In'li ever starts publishing it, this assertion fails and someone maps it.
     */
    public function testTheLiftIsAbsentFromThePageEntirely(): void
    {
        self::assertStringNotContainsStringIgnoringCase('ascenseur', $this->page());
        self::assertNull(Payload::bool(['d' => $this->selected('.advert-body-description p')], ['d']));
    }

    /**
     * The floor is PROSE, which is why no `floor` selector is mapped.
     *
     * `Payload::floor()` reads it out of the description, anchored on `étage` — and this listing is
     * the case that could hide a bug, because it is on the 3rd floor AND has 3 rooms. The
     * disagreeing cases are asserted beside it so a reader cannot mistake a coincidence for a pass.
     */
    public function testTheFloorIsReadFromTheProseAndIsNotTheRoomCount(): void
    {
        $description = $this->selected('.advert-body-description p');

        self::assertSame(3, Payload::floor(['d' => $description], ['d']));
        self::assertSame(7, Payload::floor(['d' => ['appartement 3 pièces de 63 m². Le bien est situé au 7e étage.']], ['d']));
        self::assertSame(0, Payload::floor(['d' => ['4 pièces de 80 m². Le bien est situé en rez-de-chaussée.']], ['d']));
        self::assertNull(Payload::floor(['d' => ['appartement 3 pièces de 63 m². Chauffage collectif.']], ['d']));
    }

    /**
     * THE FURNITURE CLASS, on a fourth source — and this description contains it.
     *
     * *"proximité de bus et de lignes de RER B et C avec **plus**ieurs stations accessibles"*. `plus`
     * is one of the commonest words in French, it is the excluded financing acronym `PLUS`, and
     * reading the second where the first was written has already cost this repo three fixes (CDC's
     * *au plus près*, Cityloger's *bailleur social*, Logirep's *Ce·lli·er*).
     *
     * The verdict must be IDENTICAL with and without the description: adding prose to a pure-LLI
     * source may not change what it concludes. If this goes red, the prose route regressed and In'li
     * is about to start rejecting its own stock.
     */
    public function testAddingTheDescriptionDoesNotChangeTheVerdictOnAPureLliSource(): void
    {
        $description = $this->selected('.advert-body-description p');
        self::assertStringContainsString('plusieurs', $description, 'the fixture must still carry the trap');

        $classifier = new TenureClassifier();
        $inli = new SourceProfile(name: 'inli', family: 'institutional', defaultTenure: Tenure::LLI, mixedTenure: false);

        $card = $classifier->classify($this->listing(''), $inli);
        $hydrated = $classifier->classify($this->listing($description), $inli);

        self::assertSame(Tenure::LLI, $card->tenure);
        self::assertSame(Tenure::LLI, $hydrated->tenure, 'the description must not veto a pure-LLI source');
        self::assertSame($card->confidenceBp, $hydrated->confidenceBp);
    }

    private function listing(string $description): RawListing
    {
        return new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-054595',
            title: 'Appartement de 63 m² à LES ULIS',
            description: $description,
            url: 'https://www.inli.fr/location-appartement-les-ulis-91940/PRV-054595',
            commune: 'Les Ulis',
            postcode: '91940',
            rentCc: 1005,
        );
    }

    /** The fixture must stay scrubbed — the guard is repo-wide, this is the local reminder. */
    public function testTheFixtureCarriesNoLiveKey(): void
    {
        self::assertStringNotContainsString('AIzaSyA', $this->page());
        self::assertStringContainsString('AIzaSyREDACTED-FIXTURE-PLACEHOLDER', $this->page());
    }
}
