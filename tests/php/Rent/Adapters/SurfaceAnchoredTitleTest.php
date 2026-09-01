<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;

/**
 * F24 / T5B-9 — A SELOGER CARD THAT STATES NO ROOM COUNT HAD NO TITLE ANCHOR AT ALL, and one such
 * listing was pushed.
 *
 * The anchor was the `pièces` line alone. A card that never states a room count therefore yielded
 * `''` — and an empty title is not merely a cosmetic gap, because **`exclude_title_patterns` is the
 * only surface those entries have**: `\bcolocation\b` lives in `exclude_patterns` and matches the
 * DESCRIPTION too, but the anchored `chambre` rule and the parking/box/garage/cave family match the
 * TITLE ONLY, deliberately — `3 chambres` in a description is the family flat the criteria want.
 * Nothing matches an empty string, so all of them were inert.
 *
 * **Both victims are real and were read out of schema v7's stored evidence, not imagined.** Their
 * card text is laid out exactly like any other, with the title present and readable — only the
 * landmark below it differs:
 *
 * ```
 * 750 €/mois charges comprises
 * <redirect url>
 * chambre a louer dans une maison     <- the title, perfectly readable
 * <redirect url>
 * 140 m²                              <- and NO `pièces` line anywhere on the card
 * ```
 *
 * That one — Clamart, 750 € for a room in a house quoting the whole house's 140 m² — was
 * **NOTIFIED AS A MATCH on 2026-08-27**. The second, `Chambre colocation evry village`, was rejected
 * only because its text happens to contain `colocation`, which fires through the description. Luck,
 * on both counts, not a guard.
 *
 * The repair is a SECOND ANCHOR on the surface line, and it was trialled over the whole store before
 * it shipped, which is this repo's rule: **617 unchanged, 2 gained, 0 changed, 0 LOST** over all 619
 * stored SeLoger cards — the two gained being exactly these victims.
 */
#[CoversClass(ConfigLoader::class)]
final class SurfaceAnchoredTitleTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /** The Clamart card, byte-for-byte as schema v7 stored it, minus the opaque redirect tokens. */
    private const string ROOM_IN_A_HOUSE = <<<'TXT'
        1 nouvelle annonce pour vous à Ile-de-France

        https://click.by.seloger.com/?qs=FIXTURE001

        https://click.by.seloger.com/?qs=FIXTURE002
        750 €/mois charges comprises

        https://click.by.seloger.com/?qs=FIXTURE003
        chambre a louer dans une maison

        https://click.by.seloger.com/?qs=FIXTURE004
        140 m²

         Jardin Parisien,

         Clamart
         (92140)
        TXT;

    /** An ordinary card, which states its rooms — the counterweight. */
    private const string ORDINARY_FLAT = <<<'TXT'
        https://click.by.seloger.com/?qs=FIXTURE005
        915 €/mois charges comprises

        https://click.by.seloger.com/?qs=FIXTURE006
        Appartement lumineux proche RER

        https://click.by.seloger.com/?qs=FIXTURE007
        3 pièces . 52,37 m²

         Dourdan
         (91410)
        TXT;

    private function titlePattern(): string
    {
        $p = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger']->params['title_pattern'] ?? null;
        self::assertIsString($p, 'the shipped config must still configure a title pattern');

        return $p;
    }

    private function read(string $card): string
    {
        return @preg_match($this->titlePattern(), $card, $m) === 1 ? trim($m[1] ?? '') : '';
    }

    /** THE DEFECT ITSELF: a card with no room count still yields its title. */
    public function testACardWithNoRoomCountIsStillTitledFromItsSurfaceLine(): void
    {
        self::assertSame('chambre a louer dans une maison', $this->read(self::ROOM_IN_A_HOUSE));
    }

    /**
     * AND THE TITLE IS WHAT ACTUALLY REJECTS IT.
     *
     * Asserted through the shipped criteria rather than by eyeballing the pattern: recovering a
     * title is worth nothing unless the exclusion it exists to feed then fires. The description is
     * held EMPTY on purpose — a first version of a sibling test asserted a rejection that
     * `\bcolocation\b` was delivering through the description all along, and passed before the fix
     * as well as after.
     */
    public function testTheRecoveredTitleIsWhatExcludesTheListing(): void
    {
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        self::assertNotNull(
            $criteria->excludedBy($this->read(self::ROOM_IN_A_HOUSE), ''),
            'a room advertised with the whole house\'s surface passes every numeric filter — the title is the only thing that stops it',
        );
    }

    /**
     * THE COUNTERWEIGHT, and it is the half that stops the fix being "delete the anchor".
     *
     * A card that states its rooms must read identically to before. Measured over the store the
     * widening changed 0 titles and lost 0; this pins the shape of that claim so a later "tidy" of
     * the alternation cannot quietly start preferring the surface branch.
     */
    public function testAnOrdinaryCardIsUnchangedByTheSecondAnchor(): void
    {
        self::assertSame('Appartement lumineux proche RER', $this->read(self::ORDINARY_FLAT));
        self::assertNull(
            ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json')
                ->excludedBy($this->read(self::ORDINARY_FLAT), ''),
            'an ordinary flat must not be excluded by its title',
        );
    }

    /**
     * BOTH SURFACE SPELLINGS. 128 stored SeLoger titles write the ASCII `m2` rather than `m²`, and a
     * pattern that accepted only the typographic form would recover the title on some cards and not
     * others — which is the same partial inertness, one encoding narrower.
     */
    public function testTheSurfaceAnchorAcceptsTheAsciiSpelling(): void
    {
        $ascii = str_replace('140 m²', '140 m2', self::ROOM_IN_A_HOUSE);

        self::assertSame('chambre a louer dans une maison', $this->read($ascii));
    }

    /**
     * THE PRICE LINE IS STILL REFUSED AS A TITLE (F23's guarantee, re-asserted under the new anchor).
     *
     * Widening an anchor is exactly how a pattern starts reading the rent line as the flat's name —
     * a plausible-looking string in the very field the exclusions run over. The candidate before the
     * surface line here IS a bare price, and it must lose.
     */
    public function testABarePriceIsStillNotATitle(): void
    {
        $card = "https://click.by.seloger.com/?qs=FIXTURE008\n1 450 €\n\nhttps://click.by.seloger.com/?qs=FIXTURE009\n88 m²\n\n Sartrouville\n (78500)";

        self::assertNotSame('1 450 €', $this->read($card));
    }
}
