<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Rent\Config\ConfigLoader;

/**
 * F23 (cited as F10 in commit d60a183, before an id collision was found) — SeLoger's
 * `Baisse de prix` template yielded an EMPTY title, so `exclude_title_patterns`
 * was inert across the whole template. The In'li/PAP inert-exclusion lesson a fourth time.
 *
 * **THE LAYOUT HERE IS MEASURED, NOT INVENTED.** Both `Baisse de prix` messages still in the IMAP
 * window extract a title correctly and are frozen as fixtures 004/005 — the failing variant had
 * aged out. What survives is better evidence than a guess: schema v7 stores the card body a verdict
 * was formed from, and `SELECT evidence_json FROM listings WHERE source='seloger' AND trim(title)=''`
 * returns the exact text the parser saw. Both surviving rows have this shape, byte for byte:
 *
 *     600€ TOUT COMPRIS – électricité, chauffage, eau, I...     <- the candidate title
 *     (blank)
 *     https://click.by.seloger.com/?qs=…
 *     5 pièces . 70 m²                                          <- the positional anchor
 *
 * The blank and the URL are fine — `title_pattern` allows a run of exactly those between the title
 * and the anchor. The ONLY thing refusing the match is `[^\n€]`, which forbids a `€` anywhere in
 * the captured line. It is there to stop the rent line being read as a title, and it costs an
 * entire template: a price-drop card's agency text begins with the price.
 *
 * The narrow repair is to refuse a candidate that is ONLY a price, not one that merely contains
 * one. Both halves are asserted here, because widening without the counterweight would just move
 * the defect: the pattern would start reading `600 €/mois` as the flat's title.
 *
 * STATED COST, and it is why this fix does not change either row's outcome: giving row 2 its title
 * makes `exclude_title_patterns` REACHABLE on it, and none of the configured title patterns match
 * `600€ TOUT COMPRIS…` anyway. The guarantee being restored is that the exclusions can fire on this
 * template at all — not that they fire on this card.
 */
#[CoversClass(EmailAlertSource::class)]
final class PriceLedTitleTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private function pattern(): string
    {
        return (string) ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger']->params['title_pattern'];
    }

    /**
     * The two card bodies, reproduced from `evidence_json` — the text the parser actually saw.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function priceLedCards(): iterable
    {
        yield 'tout compris' => [
            "600 €/mois \n650 € ↘ 8%\n600€ TOUT COMPRIS – électricité, chauffage, eau, I...\n\nhttps://click.by.seloger.com/?qs=ABB7Ig\n5 pièces . 70 m² \n",
            '600€ TOUT COMPRIS – électricité, chauffage, eau, I...',
        ];
        yield 'colocation' => [
            "670 €/mois \nColocation Choisy le roi 670 €\n\nhttps://click.by.seloger.com/?qs=ABB7Ig\n6 pièces . 200 m² \n",
            'Colocation Choisy le roi 670 €',
        ];
    }

    #[DataProvider('priceLedCards')]
    public function testATitleThatCarriesAPriceIsStillATitle(string $body, string $expected): void
    {
        self::assertSame(1, preg_match($this->pattern(), $body, $m), 'the template must yield a title at all');
        self::assertSame($expected, trim($m[1]));
    }

    /**
     * THE COUNTERWEIGHT. Without it the fix is satisfied by deleting the `€` guard outright, and
     * the pattern would start reading the rent line as the flat's title — a plausible-looking
     * string in the field `exclude_title_patterns` runs over, which is the worse failure.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function bareRentLines(): iterable
    {
        yield 'rent per month' => ["600 €/mois\n\nhttps://click.by.seloger.com/?qs=X\n5 pièces . 70 m²\n"];
        yield 'rent with no period' => ["1 450 €\n\nhttps://click.by.seloger.com/?qs=X\n3 pièces . 65 m²\n"];
        yield 'rent, comma decimals' => ["1 450,50 €\n\nhttps://click.by.seloger.com/?qs=X\n3 pièces . 65 m²\n"];
        yield 'rent cc' => ["915 € CC\n\nhttps://click.by.seloger.com/?qs=X\n3 pièces . 52 m²\n"];
    }

    #[DataProvider('bareRentLines')]
    public function testABareRentLineIsNeverCapturedAsTheTitle(string $body): void
    {
        $matched = preg_match($this->pattern(), $body, $m) === 1;

        if ($matched) {
            self::assertDoesNotMatchRegularExpression(
                '~^\h*[\d\h.,]+\h*€~u',
                trim($m[1]),
                'a line that is only a price is not a title',
            );
        } else {
            self::assertFalse($matched, 'refusing outright is also correct: no title beats the rent as a title');
        }
    }

    /** The ordinary template is untouched — asserted against the two REAL frozen price-drop captures. */
    public function testTheFrozenPriceDropCapturesStillExtractTheirTitles(): void
    {
        $bodies = [
            'Chambre en colocation appartement meublé',
            'Appartement - 1er étage - 93,33 m2 - 4 pièces - No...',
        ];

        $found = [];
        foreach (glob(self::ROOT . '/tests/fixtures/rent/seloger/2026-08-31-00[45]-*.eml') ?: [] as $path) {
            $raw = (string) file_get_contents($path);
            $message = \Scout\Adapters\Mail\EmailMessage::parse($raw);
            if (preg_match($this->pattern(), $message->body, $m) === 1) {
                $found[] = trim($m[1]);
            }
        }

        foreach ($bodies as $want) {
            self::assertContains($want, $found, 'the ordinary Baisse de prix card must keep reading');
        }
    }
}
