<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        yield 'html entities are NOT decoded' => ['interm&eacute;diaire', 'interm&eacute;diaire'];
    }

    #[DataProvider('foldCases')]
    public function testFold(string $raw, string $expected): void
    {
        self::assertSame($expected, Text::fold($raw));
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
