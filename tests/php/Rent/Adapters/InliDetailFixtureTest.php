<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\Html\Selector;
use Scout\Rent\Adapters\Payload;
use Scout\Rent\Core\Prose;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;

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
    private const string ROOT = __DIR__ . '/../../../..';

    private function page(): string
    {
        return (string) file_get_contents(self::ROOT . '/tests/fixtures/rent/inli/detail.html');
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
        $inli = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['inli'];

        self::assertNotNull($inli->detailMap);
        self::assertSame(['h1'], $inli->detailMap->title);
        self::assertSame(['.advert-body-description p'], $inli->detailMap->description);
        self::assertSame(['title => \\((\\d{5})\\)'], $inli->detailMap->postcode);
    }

    /**
     * THE POSTCODE COMES FROM THE DETAIL PAGE, and until 2026-09-01 it came from nowhere on 46 % of
     * this source (Track 5b, F-B).
     *
     * In'li's postcode was derived ENTIRELY from the URL slug, and the source publishes two shapes:
     * `/location-appartement-sceaux-92330/PRV-…` carries one and `/locations/offre/joinville-le-pont/PRV-…`
     * does not. The correlation over the live store was perfect — 251 rows with a slug postcode had
     * one, 205 without a slug postcode had none, and NOT ONE row got a postcode any other way.
     *
     * In region mode (`communes: []`) `postcode_prefixes` is the ENTIRE location filter, so those
     * rows could never match: 212 rows, 212 rejections, 0 matches, against a 30 % match rate on the
     * rows that had one. Measured cost: 44 of them cleared rent, surface AND rooms already — Les
     * Ulis at 1042 €/64 m²/3p, Deuil-la-Barre, Maurepas ×3, Ozoir-la-Ferrière.
     *
     * THIS COSTS NO EXTRA REQUEST. All 212 were ALREADY hydrated — the detail page was being fetched
     * for every one of them and this field simply was not read off it.
     *
     * Two wrong routes were investigated first and both are recorded so they are not retried. A
     * commune→postcode table would have needed an authoritative dataset and carries a real
     * ambiguity risk (French commune names repeat across departements, and In'li lets outside
     * Île-de-France too). And the detail page's OTHER five-digit numbers were read as In'li's
     * corporate CEDEX — they are neither: `875 9.5C5.09375` is SVG path data.
     */
    public function testTheDetailPageSuppliesThePostcodeTheUrlSlugOmits(): void
    {
        self::assertSame('Appartement 63m² à louer à LES ULIS (91940) | In\'li', $this->selected('title'));

        $inli = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['inli'];
        [$selector, $regex] = explode(' => ', $inli->detailMap->postcode[0], 2);

        self::assertSame(1, preg_match('~' . $regex . '~u', $this->selected($selector), $m));
        self::assertSame('91940', $m[1], 'the listing\'s own postcode, stated beside its own commune');
    }

    /**
     * THE COUNTERWEIGHT: the capture takes the postcode ALONE, never the whole title.
     *
     * The detail path deliberately adds no `_text`, so a detail map contributes only what it
     * selects — the Cityloger ruling, "a map addresses the listing, never the page". A selector
     * returning the whole `<title>` would push `| In'li` and the marketing wording into the
     * listing's classified surface, which is the furniture failure this repo has paid for five
     * times.
     */
    public function testThePostcodeCaptureTakesTheCodeAndNothingElse(): void
    {
        $inli = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['inli'];
        [$selector, $regex] = explode(' => ', $inli->detailMap->postcode[0], 2);

        preg_match('~' . $regex . '~u', $this->selected($selector), $m);

        self::assertSame('91940', $m[1]);
        self::assertStringNotContainsString('In\'li', $m[1]);
        self::assertStringNotContainsString('louer', $m[1]);
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
     * NO LIFT IS ON *THIS* PAGE — and this page turned out to be ATYPICAL.
     *
     * An earlier version of this docblock read *"In'li publishes no lift at all"*, and CLAUDE.md
     * repeated it. It was measured on this one capture. Live acceptance on 2026-08-23 hydrated 20
     * real In'li detail pages: **18 mention `ascenseur` and 19 state a floor**. Generalising from
     * n=1 — the same error class as the retired *"live yield is 0"* claim.
     *
     * So what this pins is narrow and true: on THIS page the word is absent, therefore the lift is
     * `null`, which says nothing rather than saying no (hard rule 9). The source's actual vocabulary
     * lives in `tests/fixtures/rent/inli/descriptions.json` — 20 hand-labelled captures, five of them
     * explicit negations — and is exercised by `Core\ProseTest`.
     */
    public function testTheLiftIsAbsentFromThisParticularPage(): void
    {
        self::assertStringNotContainsStringIgnoringCase('ascenseur', $this->page());
        self::assertNull(
            Prose::elevator($this->selected('.advert-body-description p')),
            'unmentioned is not absent',
        );
    }

    /**
     * The committed map reads floor and lift through `prose:` readers, and they reach the listing.
     *
     * Asserted end to end through `Selector` rather than by calling `Prose` directly, because the
     * bug this guards is in the WIRING: a `prose:` capture that fell through to `captureFrom()`
     * would compile as an ordinary regex, match nothing, and leave both fields `null` for ever while
     * the config looked deliberate.
     *
     * The frozen page states neither fact, so the input here is a synthetic description in In'li's
     * own house style — the shapes are taken verbatim from the captured corpus.
     */
    public function testTheProseReadersAreWiredThroughTheCommittedMap(): void
    {
        $inli = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['inli'];
        $detailMap = $inli->detailMap;

        self::assertNotNull($detailMap);
        self::assertSame(['.advert-body-description p => prose:floor'], $detailMap->floor);
        self::assertSame(['.advert-body-description p => prose:elevator'], $detailMap->elevator);

        $html = '<html><body><div class="advert-body-description"><p>'
            . "Le bien est situé au 6e étage d'un immeuble de 18 étages avec ascenseur."
            . '</p></div></body></html>';
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $root = $document->documentElement;
        self::assertNotNull($root);

        self::assertSame('6', Selector::parse($detailMap->floor[0])->resolve($root), 'the position, not the count');
        self::assertSame('oui', Selector::parse($detailMap->elevator[0])->resolve($root));
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
