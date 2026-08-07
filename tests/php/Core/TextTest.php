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
        // NEWLINES SURVIVE, everything else collapses. Changed in review round 5: a newline is the
        // title/description boundary that `RawListing::text()` inserts, and the `conventionné`
        // adjacency rule has to see it. Flattening it to a space let a title ending
        // `Logement intermédiaire` excuse a description opening `Conventionné, …` — MATCH, with the
        // word absent from `reasons[]`, while a mere comma correctly blocked the same exception.
        // Runs of newlines collapse to one and surrounding spaces are absorbed, so the only
        // whitespace bytes the output can contain are still exactly two: ' ' and "\n".
        yield 'horizontal whitespace collapses' => ["logement \t  intermediaire", 'logement intermediaire'];
        yield 'a newline survives as a phrase boundary' => ["logement\n\n  intermediaire", "logement\nintermediaire"];
        yield 'spaces around a newline are absorbed into it' => ["logement  \n  social", "logement\nsocial"];
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

    /**
     * `fold()` and `foldPreserveCase()` produce byte-identical LENGTHS, for every codepoint.
     *
     * Not a stylistic property — a correctness one. `TenureClassifier` matches explicit labels
     * against `fold()` output and the ambiguous acronym against `foldPreserveCase()` output, then
     * compares the resulting `TenureSignal::position` values directly: the resolver breaks ties on
     * them, and the `conventionné` adjacency rule measures a span across them. If one surface is a
     * byte longer than the other, every offset after the divergence is wrong on one of the two.
     *
     * The invariant was asserted in a docblock and was FALSE for 27 codepoints under
     * `mb_strtolower` — `İ`, `ẞ`, the Kelvin sign and 24 others all change byte length when
     * lowercased. Nothing tested it. Enumerated rather than sampled, because the failures were
     * scattered across Latin Extended-D and the letterlike-symbols block and no plausible hand-
     * written list would have contained them.
     */
    public function testFoldPreservesByteOffsets(): void
    {
        $diverged = [];

        for ($cp = 0x20; $cp <= 0x2FFFF; ++$cp) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;                                  // surrogates are not scalar values
            }

            $char = mb_chr($cp, 'UTF-8');

            if ($char === false) {
                continue;
            }

            // Embedded in real text: the property that matters is that a character sitting BEFORE
            // a label cannot shift that label's offset on one surface but not the other.
            $subject = 'logement ' . $char . ' social';

            try {
                $cased = Text::foldPreserveCase($subject);
                $folded = Text::fold($subject);
            } catch (MalformedText) {
                continue;                                  // refused input has no offsets to align
            }

            if (strlen($cased) !== strlen($folded)) {
                $diverged[] = sprintf('U+%04X', $cp);
            }
        }

        self::assertSame(
            [],
            $diverged,
            'fold() and foldPreserveCase() disagree on byte length for: ' . implode(' ', array_slice($diverged, 0, 30)),
        );
    }

    /**
     * A PCRE failure is never quietly folded into a clean string.
     *
     * Every guard in this file exists for one shape of bug: `false`/`null` from a preg call read as
     * "nothing matched", which turns an unreadable listing into an unlabelled one and lets it match
     * on the source default. The property under test is end-to-end — whatever fails inside
     * `foldPreserveCase`, the caller gets an exception, never text.
     *
     * PCRE limits are process-global and restored in `finally`, which is why this test drives the
     * failure that way rather than by crafting a pathological subject: the entity gate and the
     * invisible-character strip share the limit, so no input reaches one broken and the other
     * working. That sharing is what makes the entity gate's own guard defence-in-depth rather than
     * a live fix, and it is stated as such in the code.
     */
    public function testAPcreFailureNeverBecomesCleanText(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');

        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '1');
            ini_set('pcre.recursion_limit', '1');

            $subject = str_repeat('a', 100) . '&abcdefghij;';

            $this->expectException(MalformedText::class);
            Text::foldPreserveCase($subject);
        } finally {
            ini_set('pcre.backtrack_limit', $backtrack === false ? '1000000' : $backtrack);
            ini_set('pcre.recursion_limit', '100000');
            ini_set('pcre.jit', $jit === false ? '1' : $jit);
        }
    }
}
