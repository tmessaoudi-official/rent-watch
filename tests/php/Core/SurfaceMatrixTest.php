<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Outcome;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;

/**
 * THE SURFACE MATRIX — every excluded vocabulary item, on every surface a listing has.
 *
 * WHY THIS FILE EXISTS, stated plainly because it is a finding about how this module was built:
 * seven consecutive review rounds each found a P0, and every one had the same shape — a correct
 * rule applied to only a SUBSET of the surfaces it belongs on. Round 4 fixed the collocation
 * fail-open for `pls` and left `PLUS`; round 5 gave the doubt floor to structured fields and left
 * prose; round 5 ruled a newline is a phrase boundary and wired it into one of five rules that
 * consume whitespace adjacency; round 6 taught the §1 invariant the procedural literals on the
 * surface that was already safe; round 7 closed unrecognised fields for `PLAI` and left `PLUS`,
 * because the helper read `LABELS` and `PLUS` lives in `AMBIGUOUS_LABELS`.
 *
 * Every one of those was found by a human-equivalent reviewer reading code, and none by the suite.
 * The corpus could not find them: it is a list of listings someone thought to write, so it can only
 * cover the cells someone thought of. A per-fixture corpus is the wrong shape for a "did this rule
 * reach that surface" question.
 *
 * So this test does not ask what a listing says. It takes the CROSS PRODUCT of the classifier's own
 * excluded vocabulary — read out of its tables by reflection, so a literal added tomorrow joins
 * automatically — and every surface a `RawListing` presents, and asserts the one rule that matters
 * on each cell. An empty cell is a failing test, not a review finding.
 *
 * The source is deliberately the WORST case: pure (so the mixed-tenure fail-closed rule cannot
 * rescue anything) and declaring LLI (so the tier-5 default actively pushes toward MATCH). That is
 * a profile the config schema permits, and it is the shape every §1 breach of rounds 4–7 was
 * demonstrated on. It used to name In'li as a real configured example of it; that stopped being
 * true when hydration proved In'li is not pure LLI (the shipped block is `mixed_tenure: true`, and
 * no enabled source is `false` today). The profile stays worst-case either way — a source that
 * cannot exist would weaken nothing here, since every real profile is strictly safer.
 */
#[CoversClass(TenureClassifier::class)]
final class SurfaceMatrixTest extends TestCase
{
    /**
     * No excluded vocabulary item, on any surface, may reach `MATCH`.
     *
     * @param callable(string): RawListing $build
     */
    #[DataProvider('excludedTokenOnEverySurface')]
    public function testNoExcludedTokenReachesMatchOnAnySurface(string $token, string $surface, callable $build): void
    {
        $result = (new TenureClassifier())->classify($build($token), self::worstCaseSource());

        self::assertNotSame(
            Outcome::MATCH,
            $result->outcome,
            sprintf(
                "\"%s\" on the %s surface reached MATCH.\n"
                . "That is CLAUDE.md §1: the listing names an excluded tenure and was notified.\n"
                . "reasons[]: %s\n"
                . 'Fix the rule so it reads this surface — do not narrow this matrix.',
                $token,
                $surface,
                implode(' | ', $result->reasons()) ?: '(none — the surface is not read at all)',
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string, callable(string): RawListing}>
     */
    public static function excludedTokenOnEverySurface(): iterable
    {
        // Cells that are only meaningful for a MULTI-WORD literal, each for its own reason:
        //   - the spanning cells, because a hard wrap falls at a space (`pl`/`ai` is a broken token);
        //   - the all-lowercase identifier, because `champplai` is indistinguishable from *plaisir*
        //     by exactly the word-boundary logic that stops `plai` matching inside it. That gap is
        //     deliberate and is the price of not re-introducing the substring false positive; a real
        //     feed spells such a key `champPlai` or `champ_plai`, which the other two cells cover.
        $multiWordOnly = ['multi-line field value, token spanning the break',
                          'title/description join, token spanning the break',
                          'field name, lowercased identifier'];

        foreach (self::excludedVocabulary() as $token) {
            foreach (self::surfaces() as $surface => $build) {
                // See $multiWordOnly above for why each of these skips a single-word literal.
                if (!str_contains($token, ' ') && in_array($surface, $multiWordOnly, true)) {
                    continue;
                }

                yield sprintf('%s on %s', $token, $surface) => [$token, $surface, $build];
            }
        }
    }

    /**
     * THE COUNTERWEIGHT, and it is not symmetry for its own sake.
     *
     * Every other corpus-level invariant in this repo is one-directional: they all assert that
     * MATCH is not reached. A review pointed out what that costs — across three commits the corpus
     * went MATCH 33.3% → 30.1% → 28.0%, every relabel ran MATCH→DIGEST and none ever ran the other
     * way, and nothing could have noticed if the classifier had simply started rejecting
     * everything. A classifier that digests the whole market looks exactly like a quiet market
     * (`CLAUDE.md` hard rule 8), and the cheapest way to make any change pass a one-directional
     * suite is to move a fixture to DIGEST.
     *
     * So: an ordinary eligible listing must still MATCH with the excluded token nowhere in sight,
     * on every one of the same surfaces. This is the assertion that makes over-rejection visible.
     *
     * @param callable(string): RawListing $build
     */
    #[DataProvider('everySurface')]
    public function testAnOrdinaryEligibleListingStillMatchesOnEverySurface(string $surface, callable $build): void
    {
        $result = (new TenureClassifier())->classify($build('residence calme'), self::worstCaseSource());

        self::assertSame(
            Outcome::MATCH,
            $result->outcome,
            sprintf(
                "an ordinary listing with NO excluded vocabulary stopped matching on the %s surface.\n"
                . "reasons[]: %s\n"
                . 'This is over-rejection: nothing arrives, which is indistinguishable from a quiet '
                . 'market. Fix the rule — moving this to DIGEST would delete the only assertion in '
                . 'the suite that points this direction.',
                $surface,
                implode(' | ', $result->reasons()) ?: '(none)',
            ),
        );
    }

    /**
     * The counterweight runs over every surface EXCEPT the two that are doubts by construction.
     *
     * A non-scalar field value is unreadable whatever it contains, so digesting it is the rule
     * working, not over-rejection — `CLAUDE.md` hard rule 3, a breakage is never an absence. Every
     * other surface must still be able to produce a match. Exclusion by name rather than by a flag
     * on the surface list, so that adding a surface opts INTO the counterweight by default: the
     * failure mode this whole file exists for is a check that quietly covers less than it looks.
     *
     * @return iterable<string, array{string, callable(string): RawListing}>
     */
    public static function everySurface(): iterable
    {
        $unreadableByConstruction = [
            'non-scalar field value',
            'non-scalar recognised field',
            // An entity in the DESCRIPTION is the adapter-stopped-short case that `Text::fold()`
            // refuses by doctrine — refusing is the rule working, not over-rejection. The tolerant
            // path is for incidental surfaces only, which is the distinction being asserted here.
            'description with an entity inside the token',
        ];

        foreach (self::surfaces() as $surface => $build) {
            if (in_array($surface, $unreadableByConstruction, true)) {
                continue;
            }

            yield $surface => [$surface, $build];
        }
    }

    /**
     * Every surface a `RawListing` presents to the classifier.
     *
     * Adding a surface here is how a future reader makes the matrix cover new ground; the point is
     * that the list lives in ONE place instead of being re-derived by whoever writes the next rule.
     *
     * @return array<string, callable(string): RawListing>
     */
    private static function surfaces(): array
    {
        return [
            'title' => static fn (string $t): RawListing => self::listing(title: 'T3 Le Vesinet ' . $t),
            'description' => static fn (string $t): RawListing => self::listing(description: 'Bel appartement. ' . $t . '.'),
            // The join is its own surface: folding preserves the newline as a phrase boundary, and
            // four separate rules have to agree about that.
            'title/description join' => static fn (string $t): RawListing => self::listing(
                title: 'T3 Le Vesinet ' . $t,
                description: 'Grand sejour, proche RER A.',
            ),
            // `_text` — the html adapter's whole-card surface, and as of 2026-08-20 it has its OWN
            // routing rather than being one more field: the field loop skips it and
            // `RawListing::text()` returns it, because the field path matches financing acronyms
            // case-insensitively and read the French adverb in `au plus pres` as `PLUS`. A surface
            // with bespoke routing and no cell here is precisely the "correct rule applied to a
            // subset of the surfaces it belongs on" P0 this file exists to catch — the re-route was
            // pinned by four hand-picked literals, and this cell is the whole vocabulary.
            //
            // The input is the shape CDC Habitat actually emits: a badge, its tooltip, then the
            // card's own line of facts.
            '_text, the whole card' => static fn (string $t): RawListing => self::listing(
                fields: ['_text' => $t . ' Appartement 4 pieces - 2eme etage - 78m2 CERGY (95000) 612,40 EUR'],
            ),
            'recognised tenure field' => static fn (string $t): RawListing => self::listing(fields: ['financement' => $t]),
            // `TENURE_FIELDS` is an exact-match list, so a feed spelling a key any other way lands
            // here. This is the cell round 7 found open for `PLUS`.
            'unrecognised field value' => static fn (string $t): RawListing => self::listing(fields: ['gamme' => $t]),
            'unrecognised field value, in a phrase' => static fn (string $t): RawListing => self::listing(
                fields: ['programme' => 'Programme neuf ' . $t . ' livre en 2025'],
            ),
            // A field NAME carries evidence too: `numeroUnique` and `demandeLogementSocial` are
            // ordinary bailleur-social JSON keys and both are procedural literals.
            //
            // THREE SPELLINGS, and the verbatim one is the least useful of them. The first version
            // of this cell passed the table literal as the key — `['demande de logement social' =>
            // 'oui']`, a JSON key containing spaces, which no feed produces. It was green while
            // both keys the rule was written for still MATCHED. A cell satisfied by an input that
            // cannot occur is exactly the "check that quietly covers less than it looks" this file
            // opens by warning about, sitting inside the file.
            'field name, verbatim' => static fn (string $t): RawListing => self::listing(fields: [$t => 'oui']),
            'field name, camelCase' => static fn (string $t): RawListing => self::listing(
                fields: [lcfirst(str_replace(' ', '', ucwords($t))) => 'oui'],
            ),
            'field name, snake_case' => static fn (string $t): RawListing => self::listing(
                fields: [str_replace([' ', "'"], ['_', '_'], $t) => 'oui'],
            ),
            // PREFIXED camelCase — only the word-splitting pass can see this one, because the
            // separator-free key path covers multi-word literals only (a bare `plai` compared as a
            // substring matches inside *plaisir*).
            'field name, prefixed camelCase' => static fn (string $t): RawListing => self::listing(
                fields: ['type' . str_replace([' ', "'"], '', ucwords($t)) => 'oui'],
            ),
            // ALL-LOWERCASE identifier — only the separator-free key path can see this one, because
            // there is no case or separator transition for the split to work from.
            'field name, lowercased identifier' => static fn (string $t): RawListing => self::listing(
                fields: ['champ' . str_replace([' ', "'"], '', $t) => 'oui'],
            ),
            // SPANNING the newline, not sitting beside it. The first version placed the token on a
            // line of its own, so it never exercised the rule it was there to check — the
            // eligible-may-not-span asymmetry could be deleted at all three call sites with the
            // whole suite green.
            // Split AT A SPACE, which is where a hard wrap actually falls, so these cells apply only
            // to multi-word literals. Splitting `plai` into `pl`/`ai` is not a listing shape — it is
            // a broken token, and the entity cells below cover that class properly.
            'multi-line field value, token spanning the break' => static fn (string $t): RawListing => self::listing(
                fields: ['categorie' => 'Residence Les Tilleuls ' . self::acrossANewline($t) . ' 2025'],
            ),
            'title/description join, token spanning the break' => static fn (string $t): RawListing => self::listing(
                title: 'Residence Les Tilleuls ' . self::firstHalf($t),
                description: self::secondHalf($t) . ' a Cergy.',
            ),
            // AN ENTITY INSIDE THE TOKEN. `&shy;` is ordinary hyphenation markup in justified French
            // CMS output and `&#39;` is how every French CMS emits an apostrophe — three procedural
            // literals contain one. A repair that substituted a space for each entity turned
            // `PL&shy;AI` into `pl ai` and NOTIFIED it.
            'field value with an entity inside the token' => static fn (string $t): RawListing => self::listing(
                fields: ['gamme' => self::withEntityInside($t)],
            ),
            'description with an entity inside the token' => static fn (string $t): RawListing => self::listing(
                description: 'Bel appartement. ' . self::withEntityInside($t) . '.',
            ),
            // Annotated `array<string,string>`; an annotation is not a runtime guarantee, and a JSON
            // feed decodes a repeated key to a list. Round 7 found this silently dropped.
            'non-scalar field value' => static fn (string $t): RawListing => self::listing(fields: ['gamme' => [$t]]),
            'non-scalar recognised field' => static fn (string $t): RawListing => self::listing(fields: ['financement' => [$t]]),
            // THE PROPERTIES NO RULE READS. `RawListing` presents five more strings than
            // `text()` returns, and a review found 21/21 tokens MATCHing on each. `url` is the
            // load-bearing one: `https://…/logement-social/plai/t3-cergy` is the ordinary slug shape
            // of a bailleur portal and survives untouched into `$url`.
            // A SLUG, which is what a portal URL actually contains — `/logement-social/plai/t3-cergy`.
            // `rawurlencode()` would give `%20` between the words and test a shape no CMS emits.
            'url' => static fn (string $t): RawListing => self::listing(
                url: 'https://bailleur.test/' . str_replace([' ', "'"], '-', $t) . '/t3-cergy',
            ),
            'commune' => static fn (string $t): RawListing => self::listing(commune: $t),
            'postcode' => static fn (string $t): RawListing => self::listing(postcode: $t),
            'externalId' => static fn (string $t): RawListing => self::listing(externalId: $t . '-2024-17'),
        ];
    }

    /** Split a token across a newline, so the span guard is actually exercised. */
    private static function acrossANewline(string $token): string
    {
        return self::firstHalf($token) . "\n" . self::secondHalf($token);
    }

    private static function firstHalf(string $token): string
    {
        $at = strrpos($token, ' ');

        return $at === false ? substr($token, 0, max(1, intdiv(strlen($token), 2))) : substr($token, 0, $at);
    }

    private static function secondHalf(string $token): string
    {
        $at = strrpos($token, ' ');

        return $at === false ? substr($token, max(1, intdiv(strlen($token), 2))) : substr($token, $at + 1);
    }

    /** Put an `&shy;` INSIDE the last word, where `\s*` between a literal's words cannot help. */
    private static function withEntityInside(string $token): string
    {
        $at = strrpos($token, ' ');
        $head = $at === false ? '' : substr($token, 0, $at + 1);
        $word = $at === false ? $token : substr($token, $at + 1);

        return $head . substr($word, 0, 1) . '&shy;' . substr($word, 1);
    }

    /**
     * The excluded vocabulary, read from the classifier's own tables.
     *
     * ALL THREE TABLES. Reading only `LABELS` is exactly the round-7 P0 — `PLUS` is the sole entry
     * of `AMBIGUOUS_LABELS`, and it is the acronym this whole bug class has been about. `PROCEDURAL`
     * contributes the social tells, filtered to the excluded ones so that `attribution directe`
     * (intermediate) does not end up asserted as if it were social.
     *
     * @return list<string>
     */
    private static function excludedVocabulary(): array
    {
        $class = new \ReflectionClass(TenureClassifier::class);
        $tokens = [];

        foreach (['LABELS', 'AMBIGUOUS_LABELS', 'PROCEDURAL'] as $table) {
            /** @var array<string, Tenure> $entries */
            $entries = $class->getConstant($table);

            self::assertNotSame([], $entries, sprintf('%s is empty — §1 lost its vocabulary', $table));

            foreach ($entries as $literal => $tenure) {
                // NOT `isExcluded()`. `PROCEDURAL` holds `numero unique => Tenure::UNKNOWN`, a
                // deliberate DOUBT — and a filter on `isExcluded()` skips it, in both this matrix
                // AND the classifier's own scan, so the one key the field-name rule names in its
                // own comment stayed invisible to the control written to find it. §1 covers a
                // listing whose tenure never resolved reaching a notification, not only an excluded
                // verdict, so the vocabulary is everything that is not eligible.
                if (!$tenure->isEligible()) {
                    $tokens[] = $literal;
                }
            }
        }

        self::assertContains('plus', array_map('strtolower', $tokens), 'the matrix lost `plus` — see this method');
        self::assertGreaterThan(15, count($tokens), 'the excluded vocabulary shrank unexpectedly');

        return array_values(array_unique($tokens));
    }

    /**
     * Pure AND declaring LLI — the worst case, not a neutral one.
     *
     * Pure means `mixedTenure: false`, so the fail-closed downgrade cannot rescue a bad verdict.
     * `defaultTenure: LLI` means tier 5 actively pushes toward an eligible answer. Every §1 breach
     * demonstrated in rounds 4 through 7 was reproduced on exactly this profile. It is named `inli`
     * for history, not because In'li still has this shape — see the class docblock.
     */
    private static function worstCaseSource(): SourceProfile
    {
        return new SourceProfile(name: 'inli', family: 'institutional', defaultTenure: Tenure::LLI, mixedTenure: false);
    }

    /** @param array<string, mixed> $fields */
    private static function listing(
        string $title = 'T3 Le Vesinet',
        string $description = 'Bel appartement lumineux.',
        array $fields = [],
        string $externalId = 'matrix',
        ?string $url = null,
        ?string $commune = null,
        ?string $postcode = null,
    ): RawListing {
        /** @var array<string, string> $fields intentionally violated for the non-scalar surfaces */
        return new RawListing(
            sourceName: 'inli',
            externalId: $externalId,
            title: $title,
            description: $description,
            fields: $fields,
            url: $url,
            commune: $commune,
            postcode: $postcode,
        );
    }

    /**
     * Every string property of `RawListing` is represented above.
     *
     * Asserted rather than trusted, because the surface list was hand-written and a review found it
     * spanned ten of fifteen while its docblock said "every surface a RawListing presents". A
     * property added to the model now fails this until the matrix is told how to reach it.
     *
     * **And for a while it did not.** The condition carried a second clause,
     * `&& !str_contains($covered, 'title')` — and `$covered` is built from `surfaces()`, which
     * hard-codes a key called `title`. So the clause was ALWAYS false, `$missing` could never be
     * non-empty, and the sentence above was a promise nothing kept: round 7 added a real unread
     * string property to `RawListing` and this test stayed green while the snapshot reflection
     * guard caught it. The matrix is the structural §1 control that eight review rounds produced,
     * and the mechanism that makes it grow with the model was inert.
     *
     * **"Pinned in the ledger now" is what that sentence said next, and it was false when it was
     * written** — the ledger only ever mutates `src/`, never test files, so it could not acquire a
     * case for this by accident and had none. Round 8 caught it, in the commit that fixed eight
     * overclaimed guarantees. The real pin is a SRC-side case that adds a constructor parameter to
     * `RawListing` and expects red: *"a new listing field reaches no matrix surface and nothing
     * says so"*.
     */
    public function testTheSurfaceListCoversEveryStringPropertyOfTheModel(): void
    {
        $covered = implode(' ', array_keys(self::surfaces()));
        $missing = [];

        foreach ((new \ReflectionClass(RawListing::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = (string) $parameter->getType();

            if (!str_contains($type, 'string') || str_contains($type, 'array')) {
                continue;
            }

            // `sourceName` is the source profile's identity, not listing text; the profile itself is
            // the matrix's worst case and is asserted separately.
            if (in_array($parameter->getName(), ['sourceName'], true)) {
                continue;
            }

            if (!str_contains(strtolower($covered), strtolower($parameter->getName()))) {
                $missing[] = $parameter->getName();
            }
        }

        self::assertSame([], $missing, 'RawListing properties with no matrix surface: ' . implode(', ', $missing));
    }
}
