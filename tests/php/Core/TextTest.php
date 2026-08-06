<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\MalformedText;
use RentWatch\Core\Text;

#[CoversClass(Text::class)]
final class TextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function foldCases(): iterable
    {
        yield 'accents' => ['Logement intermédiaire', 'logement intermediaire'];
        yield 'uppercase accents' => ['LOGEMENT INTERMÉDIAIRE', 'logement intermediaire'];
        yield 'cedilla' => ['Français', 'francais'];
        yield 'ligature' => ['Cœur de ville', 'coeur de ville'];
        yield 'curly apostrophe' => ["commission d’attribution", "commission d'attribution"];
        yield 'collapsed whitespace' => ["logement\n\n  intermediaire", 'logement intermediaire'];
        yield 'trimmed' => ['  LLI  ', 'lli'];
        yield 'em dash survives as a separator' => ['LLI — T3', 'lli — t3'];
        yield 'NFD-decomposed accent folds like a precomposed one'
            => ["interme\u{0301}diaire", 'intermediaire'];
        yield 'a bare ampersand is not an entity' => ['Dupont & Fils', 'dupont & fils'];
    }

    #[DataProvider('foldCases')]
    public function testFold(string $raw, string $expected): void
    {
        self::assertSame($expected, Text::fold($raw));
    }

    /**
     * Text the classifier must refuse rather than reason about.
     *
     * Both were silent §1 hazards before 2026-08-06: malformed UTF-8 made `preg_replace('/u')`
     * return null, which a `(string)` cast turned into `''` — read downstream as "this listing
     * named no financing scheme" and matched on the source default. Undecoded entities were worse
     * than silent: an entity inside one label deleted that label and left the others standing, so
     * `logement&nbsp;social ... loyer intermediaire` classified as LLI.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTexts(): iterable
    {
        yield 'cp1252 body under a utf-8 declaration' => ["conventionn\xE9 PLAI", 'not valid UTF-8'];
        yield 'truncated mid-multibyte character' => ["logement intermediai\xC3", 'not valid UTF-8'];
        yield 'named html entity' => ['logement&nbsp;social', 'undecoded HTML entities'];
        yield 'accented named entity' => ['interm&eacute;diaire', 'undecoded HTML entities'];
        yield 'numeric entity' => ['logement&#160;social', 'undecoded HTML entities'];
        yield 'hex entity' => ['logement&#xA0;social', 'undecoded HTML entities'];
    }

    #[DataProvider('refusedTexts')]
    public function testFoldRefusesTextItCannotHonestlyRead(string $raw, string $expectedMessage): void
    {
        $this->expectException(MalformedText::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessage, '/') . '/');

        Text::fold($raw);
    }

    /** The refusal must not be a silent empty string — that is the defect it replaces. */
    public function testMalformedTextIsNeverFoldedToAnEmptyString(): void
    {
        try {
            Text::fold("conventionn\xE9 PLAI");
            self::fail('expected MalformedText');
        } catch (MalformedText $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testFoldPreserveCaseKeepsCaseButStripsAccents(): void
    {
        self::assertSame('LOGEMENT INTERMEDIAIRE PLUS', Text::foldPreserveCase('LOGEMENT INTERMÉDIAIRE PLUS'));
        self::assertSame('Logement PLUS', Text::foldPreserveCase("Logement\tPLUS"));
    }

    /**
     * The heart of it. Every one of these is a listing that would be silently dropped, or silently
     * surfaced, if token matching degraded to `str_contains`.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function tokenCases(): iterable
    {
        yield 'plus as a whole word' => ['logement plus', 'plus', true];
        yield 'plus inside surplus' => ['un surplus de charges', 'plus', false];
        yield 'plus inside plusieurs' => ['plusieurs chambres', 'plus', false];
        yield 'plai inside plaine' => ['la plaine de versailles', 'plai', false];
        yield 'plai inside plaisant' => ['cadre plaisant', 'plai', false];
        yield 'plai inside plaisir' => ['un vrai plaisir', 'plai', false];
        yield 'plai as a whole word' => ['logement plai', 'plai', true];
        yield 'pls inside a reference code' => ['reference apls2024', 'pls', false];
        yield 'pls as a whole word' => ['logement pls, plafonds', 'pls', true];
        yield 'lli as a whole word after a slash' => ['programme/lli', 'lli', true];
        yield 'lli inside a word' => ['metallique', 'lli', false];
        yield 'multi-word phrase' => ['ce logement social est', 'logement social', true];
        yield 'phrase not present' => ['une vie sociale riche', 'logement social', false];
        yield 'digit boundary blocks a match' => ['plus2', 'plus', false];
        yield 'empty needle never matches' => ['logement', '', false];
        yield 'empty haystack never matches' => ['', 'plus', false];
    }

    #[DataProvider('tokenCases')]
    public function testHasToken(string $folded, string $needle, bool $expected): void
    {
        self::assertSame($expected, Text::hasToken($folded, $needle));
    }

    public function testTokenPositionIsTheFirstOccurrence(): void
    {
        self::assertSame(9, Text::tokenPosition('logement plus ou plai', 'plus'));
        self::assertSame(17, Text::tokenPosition('logement plus ou plai', 'plai'));
        self::assertNull(Text::tokenPosition('logement', 'plai'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fieldKeyCases(): iterable
    {
        yield 'camelCase' => ['typeProduit', 'typeproduit'];
        yield 'SCREAMING_SNAKE' => ['TYPE_PRODUIT', 'typeproduit'];
        yield 'french with de' => ['Type de produit', 'typeproduit'];
        yield 'kebab' => ['type-produit', 'typeproduit'];
        yield 'accented' => ['Catégorie', 'categorie'];
        yield 'padded' => ['  financement  ', 'financement'];
    }

    #[DataProvider('fieldKeyCases')]
    public function testFieldKey(string $raw, string $expected): void
    {
        self::assertSame($expected, Text::fieldKey($raw));
    }
}
