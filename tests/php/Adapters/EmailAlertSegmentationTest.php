<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\EmailAlertSource;
use RentWatch\Adapters\Mail\FileMailbox;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * Card segmentation and content-addressed identity, shaped by the first real portal alert.
 *
 * **The defect this closes is an identity collapse, not a parsing one.** SeLoger sends no listing
 * URL and no listing id: every link in an alert is `click.by.seloger.com/?qs=<opaque token>`, and
 * `EmailAlertSource` keys on the link with its query stripped — so all sixteen links in a real
 * message resolve to the single id `https://click.by.seloger.com/`. Sixteen cards, one identity.
 *
 * The redirect is deliberately never followed to recover the real URL: it is a third-party request
 * per listing, the token carries the subscriber's identity, and following it manufactures an
 * engagement signal from a click nobody made. Hard rule 5 one step out.
 */
#[CoversClass(EmailAlertSource::class)]
final class EmailAlertSegmentationTest extends TestCase
{
    private string $dir = '';

    private string $dbPath = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        if ($this->dbPath !== '') {
            @unlink($this->dbPath);
        }
    }

    // ------------------------------------------------------------------ segmentation

    public function testEachCardBecomesItsOwnListing(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('915', 'Appartement À Louer', '3 pièces . 52,37 m²', 'Le Parterre', 'Dourdan', '91410'),
        ]))->fetch();

        self::assertCount(2, $listings, 'two cards, two listings');

        $ids = array_map(static fn ($l) => $l->externalId, $listings);
        self::assertCount(2, array_unique($ids), 'a card is not the same flat as the card above it');
    }

    /**
     * The footer is a segment too, and it is not a card.
     *
     * A trailer carrying the CNIL notice, the postal address and a campaign code follows the last
     * CTA in every real alert. It yields no rent, so it is not a listing.
     */
    public function testTheFooterIsNotAListing(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertCount(1, $listings);
    }

    /**
     * The card is the description, not the message.
     *
     * This is the Cityloger ruling on a new surface: a map must address the LISTING, never the page.
     * Whole-body description is the furniture failure class that sent 14 of 16 correctly-badged CDC
     * listings to the digest, and an alert's trailer is furniture by construction.
     */
    public function testTheDescriptionIsTheCardNotTheWholeMessage(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('915', 'Appartement Dourdan', '3 pièces . 52,37 m²', 'Le Parterre', 'Dourdan', '91410'),
        ]))->fetch();

        self::assertStringNotContainsString('Dourdan', $listings[0]->description, 'card 1 must not carry card 2');
        self::assertStringNotContainsString('Conformément à la loi', $listings[0]->description, 'nor the trailer');
    }

    // ------------------------------------------------------------------ identity

    /**
     * **Rent is NOT in the identity, and that is the whole point of excluding it.**
     *
     * A price drop is an event this project exists to detect. A rent in the key turns every drop
     * into a brand-new listing — notified as new, with no price history and no "en baisse" reason —
     * which is the silent opposite of what the store's price-history table is for.
     */
    public function testAPriceChangeDoesNotChangeTheIdentity(): void
    {
        $before = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        $after = $this->source(self::body([
            self::card('940', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertSame($before[0]->externalId, $after[0]->externalId, 'same flat, lower rent');
        self::assertSame(980, $before[0]->rentCc);
        self::assertSame(940, $after[0]->rentCc);
    }

    /** A different flat is a different identity — the guard against under-notifying. */
    public function testADifferentFlatIsADifferentIdentity(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement A', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('980', 'Appartement B', '3 pièces . 61,20 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertNotSame($listings[0]->externalId, $listings[1]->externalId, 'a different surface is a different flat');
    }

    /**
     * **The no-information floor.** A card whose extraction fails across the board would hash to
     * `sha1("seloger|||||")` — and EVERY such card collapses onto that one id, which is the store's
     * own "nothing collapses onto a shared key" identity guarantee violated one layer up.
     *
     * Skipping costs nothing real: a card with no location can never match anyway (Q32 — no
     * location evidence is a rejection), so the alternative to skipping is a listing that is
     * rejected AND poisons an identity.
     */
    public function testACardWithNoLocatingEvidenceIsNotGivenAnIdentity(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            "\n<L>\n1 100 €/mois charges comprises\n<L>\nVoir l'annonce\n",
        ]))->fetch();

        self::assertCount(1, $listings, 'the card with no commune, postcode, rooms or surface is refused');
        self::assertStringContainsString('Conflans', (string) $listings[0]->commune);
    }

    /**
     * A message that HAS cards but yields none is a broken parser, and it must be loud.
     *
     * This is hard rule 2's shape: extraction that silently returns nothing is indistinguishable
     * from a market that went quiet, and the alert literally says how many listings it carries.
     */
    public function testAMessageWhoseCardsAllFailIsALoudFailure(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~aucune annonce~i');

        $this->source(self::body([
            "\n<L>\nRien d'exploitable ici\n<L>\nVoir l'annonce\n",
        ]))->fetch();
    }

    /**
     * Two DISTINCT cards resolving to one id is an extraction failure, not a re-send.
     *
     * Scoped to the message on purpose: across messages the same id IS a legitimate re-send, which
     * is the behaviour content-addressing exists to give. Within one message it means the fields
     * that distinguish two flats were not read.
     */
    public function testTwoCardsInOneMessageSharingAnIdentityIsALoudFailure(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~identité~i');

        $this->source(self::body([
            self::card('980', 'Appartement A', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('1 400', 'Appartement B', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();
    }

    // ------------------------------------------------------------------ backwards compatibility

    /**
     * With no `card_separator` the source behaves exactly as it did — one listing per link, whole
     * body as the description. `email_demo` and its two committed fixtures depend on it.
     */
    public function testWithoutASeparatorTheOldBehaviourIsUnchanged(): void
    {
        $body = self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]);

        $listings = $this->source($body, params: ['link_host' => 'example-portal.test'])->fetch();

        foreach ($listings as $listing) {
            self::assertStringStartsWith('https://example-portal.test/', $listing->externalId);
        }
    }

    // ------------------------------------------------------------------ rent

    /**
     * A PRICE-DROP alert quotes three amounts, and only one of them is the rent.
     *
     * Captured from a live SeLoger message on 2026-08-25 (*"Baisse de prix"*, Pontault-Combault
     * 77340). It states the DISCOUNT first (`baissé de 100 €`), then the new rent (`1 100 €/mois`),
     * then the old one (`1 200 € ↘ 8%`) — and the reader took the first figure it saw.
     *
     * **100 € is below the plausibility floor, so the card was dropped entirely** and the source
     * reported `broken` on a message whose template had not changed at all. That is the benign
     * direction. Had the reduction been 300 € the reader would have returned **300** — inside the
     * band, wildly wrong, and clearing a rent ceiling the flat comes nowhere near. Same shape as the
     * `ref 850` defect of the same day: a plausible wrong rent is worse than none.
     *
     * Two rules come out of it, and this one card is the evidence for both. **A rent is a PERIODIC
     * amount**: `/mois` is what tells the new rent apart from a discount and from a struck-through
     * old price, neither of which carries a period. And **a pattern is scanned for ALL its matches,
     * first plausible one wins**, because the bare figure fallback would otherwise still take the
     * discount on a card that states no period at all.
     */
    public function testAPriceDropCardReadsTheRentAndNotTheReduction(): void
    {
        $listings = $this->source(self::body([
            "\n<L>\n Le prix d'un bien correspondant à votre recherche a baissé de 100 €\n"
                . "<L>\n1 100 €/mois \n"
                . "<L>\n1 200 € ↘ 8%\n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Mairie Rouxel-Sud Est, \n\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), self::shippedParams())->fetch();

        self::assertCount(1, $listings, 'the card is readable — 100 € is a reduction, not a rent');
        self::assertSame(1100, $listings[0]->rentCc, 'the periodic amount, not the discount and not the old price');
        self::assertSame('Pontault-Combault', $listings[0]->commune);
    }

    /**
     * The two rules above are INDEPENDENT, and this pair is what proves it.
     *
     * The captured card cannot: a 100 € reduction is below the plausibility floor, so scanning
     * every match rescues it even with no periodic pattern, and the periodic pattern rescues it even
     * scanning only the first. Each fix alone makes that test pass — so it asserts "at least one of
     * these works", which is not what its docblock claims. The sabotage ledger said so: both cases
     * came back UNDETECTED while the suite was green.
     *
     * Here the reduction is **300 €** — the hypothetical the fix was argued from, made executable.
     * It sits inside the plausibility band, so scanning every match does NOT save it: only knowing
     * that a rent carries a period does. Six hundred euros wrong, and it would clear a ceiling the
     * flat comes nowhere near.
     */
    public function testAPlausibleReductionIsNotMistakenForTheRent(): void
    {
        $listings = $this->source(self::body([
            "\n<L>\n Le prix a baissé de 300 €\n"
                . "<L>\n1 100 €/mois \n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), self::shippedParams())->fetch();

        self::assertSame(1100, $listings[0]->rentCc ?? null, 'the periodic figure, not the plausible discount');
    }

    /**
     * And the converse: no periodic marker anywhere, so only scanning every match can find the rent.
     *
     * A card quoting a fee before a bare rent. The first figure is implausible, and stopping there
     * — which is what `preg_match` does — drops a listing that states its rent perfectly clearly
     * two lines down. Silent: the card simply never becomes a listing.
     */
    public function testAnImplausibleFigureDoesNotHideABareRentBelowIt(): void
    {
        $listings = $this->source(self::body([
            "\n<L>\nFrais de dossier 50 €\n"
                . "<L>\n980 €\n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), self::shippedParams())->fetch();

        self::assertSame(980, $listings[0]->rentCc ?? null, 'the fee is not the rent, and it does not hide it');
    }

    // ------------------------------------------------------------------ commune

    /**
     * The quartier line above the commune is OPTIONAL, and both shapes must read.
     *
     * The two frozen fixtures both carry one, so a fixture-only assertion would prove the shape
     * that has a quartier and say nothing about the shape that does not — the generalisation from
     * n=1 this repo has already paid for twice. Measured against the live mailbox on 2026-08-25:
     * of nine cards, three (`Mormant`, `Garches`, `Moret-Loing-et-Orvanne`) sit directly above
     * their postcode with no quartier at all. That is why the pattern anchors on the parenthesised
     * postcode BELOW the name and not on the comma above it.
     */
    public function testTheCommuneIsReadWithOrWithoutAQuartierLine(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::cardWithoutQuartier('860', 'Appartement Mormant', '3 pièces . 80 m²', 'Mormant', '77720'),
        ]), self::shippedParams())->fetch();

        $communes = [];
        foreach ($listings as $listing) {
            $communes[(string) $listing->postcode] = $listing->commune;
        }

        self::assertSame('Conflans-Sainte-Honorine', $communes['78700'] ?? null, 'with a quartier');
        self::assertSame('Mormant', $communes['77720'] ?? null, 'without a quartier');
    }

    /**
     * The configured pattern beats the vocabulary scan, and this is the case that shows why.
     *
     * The vocabulary is a substring search over the whole card, so a card in Mormant whose copy
     * says *"proche Dourdan"* returns Dourdan — the prototype's over-matching defect, which
     * `CLAUDE.md` records as *"a Paris listing mentioning 'proche Chatou' passes the commune
     * filter"*. The pattern reads the field the portal laid out, so it is not fooled by prose. The
     * ranked names are deliberately supplied here so the fallback COULD fire and is proven not to.
     */
    public function testTheLaidOutCommuneBeatsATownMerelyMentionedInTheCopy(): void
    {
        $listings = $this->source(self::body([
            self::cardWithoutQuartier('860', 'Appartement proche Dourdan', '3 pièces . 80 m²', 'Mormant', '77720'),
        ]), self::shippedParams())->fetch();

        self::assertSame('Mormant', $listings[0]->commune ?? null);
    }

    /**
     * With no `commune_pattern` configured, the vocabulary scan is exactly what it always was.
     *
     * The blast radius of this change on every other email source is meant to be zero, and an
     * unconfigured key is how that is guaranteed rather than hoped for.
     */
    public function testWithoutTheKeyTheVocabularyScanStillAnswers(): void
    {
        $listings = $this->source(self::body([
            self::cardWithoutQuartier('915', 'Appartement À Louer', '3 pièces . 52,37 m²', 'Dourdan', '91410'),
        ]))->fetch();

        self::assertSame('Dourdan', $listings[0]->commune ?? null, 'found by the ranked vocabulary');
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $params */
    private function source(string $body, ?array $params = null): EmailAlertSource
    {
        $this->dir = sys_get_temp_dir() . '/rentwatch-seg-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/alert.eml', self::message($body));

        $this->dbPath = sys_get_temp_dir() . '/rentwatch-seg-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = new SourceDefinition(
            name: 'seloger',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: false,
            defaultTenure: Tenure::LIBRE,
            params: $params ?? [
                'from' => 'example-portal.test',
                'link_host' => 'example-portal.test',
                'card_separator' => "Voir l'annonce",
                'id_from' => 'content',
                'residence_pattern' => '~^\s*([^\n,]{2,60}),\s*$~m',
                'title_pattern' => '~^\s*((?:Appartement|Maison|Studio)[^\n]*)$~m',
            ],
            map: new FieldMap(ref: ['url'], chargesIncluded: true),
        );

        return new EmailAlertSource(
            $definition,
            Store::open($this->dbPath),
            new FileMailbox($this->dir),
            ['conflans-sainte-honorine' => 'Conflans-Sainte-Honorine', 'dourdan' => 'Dourdan'],
        );
    }

    /**
     * The synthetic params, but with the SHIPPED `commune_pattern` rather than a copy of it.
     *
     * A copy would pass while the real one rotted — the fixture-leakage rule. The rest stays
     * synthetic because the host and the separator are what make this a self-contained test.
     *
     * @return array<string,mixed>
     */
    private static function shippedParams(): array
    {
        $shipped = \RentWatch\Config\ConfigLoader::loadSources(
            dirname(__DIR__, 3) . '/config/sources.json',
        )['seloger'];

        return [
            'from' => 'example-portal.test',
            'link_host' => 'example-portal.test',
            'card_separator' => "Voir l'annonce",
            'id_from' => 'content',
            'residence_pattern' => '~^\s*([^\n,]{2,60}),\s*$~m',
            'title_pattern' => '~^\s*((?:Appartement|Maison|Studio)[^\n]*)$~m',
            'commune_pattern' => $shipped->params['commune_pattern'] ?? '',
        ];
    }

    /** A card whose location block is the commune alone — three of nine live cards, 2026-08-25. */
    private static function cardWithoutQuartier(
        string $rent,
        string $title,
        string $roomsAndSurface,
        string $commune,
        string $postcode,
    ): string {
        return "\n<L>\n{$rent} €/mois charges comprises\n"
            . "<L>\n{$title}\n"
            . "<L>\n{$roomsAndSurface}\n"
            . "<L>\n {$commune}\n ({$postcode})\n"
            . "<L>\nVoir l'annonce\n";
    }

    private static function card(
        string $rent,
        string $title,
        string $roomsAndSurface,
        string $residence,
        string $commune,
        string $postcode,
    ): string {
        return "\n<L>\n{$rent} €/mois charges comprises\n"
            . "<L>\n{$title}\n"
            . "<L>\n{$roomsAndSurface}\n"
            . "<L>\n {$residence}, \n\n {$commune}\n ({$postcode})\n"
            . "<L>\nVoir l'annonce\n";
    }

    /** @param list<string> $cards */
    private static function body(array $cards): string
    {
        $trailer = "\nConformément à la loi Informatique et Libertés, vous pouvez accéder aux données"
            . " vous concernant.\nSeLoger • \n<L>\nSLG-202501-ALI-RELAXED\n";

        return "1 nouvelle annonce : Ile-de-France\n" . implode('', $cards) . $trailer;
    }

    private static function message(string $body): string
    {
        // Links are written as `<L>` in the card helpers and expanded here, so a card reads as the
        // shape a human sees in the mail rather than as a wall of tokens.
        static $n = 0;
        $body = preg_replace_callback(
            '~<L>~',
            static function () use (&$n): string {
                ++$n;

                return 'https://example-portal.test/r/?qs=tok' . $n;
            },
            $body,
        ) ?? $body;

        return "From: \"Portail\" <alertes@example-portal.test>\n"
            . "Subject: 1 nouvelle annonce : Ile-de-France\n"
            . "Content-Type: multipart/alternative; boundary=\"B\"\n"
            . "\n"
            . "This is a multi-part message in MIME format.\n"
            . "\n"
            . "--B\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . $body
            . "\n--B--\n";
    }
}
