<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Html\Selector;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpResponse;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Adapters\HtmlSource;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * The `type: html` adapter, and the selector micro-syntax its field maps are written in.
 *
 * Everything here runs against a FROZEN payload — `tests/fixtures/inli/search.html`, captured from
 * the live site on 2026-08-19 with `robots.txt` verified first and the page's embedded third-party
 * API key scrubbed. No test in this file reaches the network; one that did would be a monitoring
 * check wearing a test's clothes, green or red according to someone else's deploy schedule.
 *
 * The suite is arranged around the two things that can go wrong, which are not equally dangerous:
 *
 * 1. **Extraction is wrong** — a selector resolves the wrong element, a number is misparsed, a
 *    relative href is stored unresolved. Loud in the sense that the data looks odd, but nothing
 *    fails, so it is only ever caught by asserting real values against the frozen page.
 * 2. **Extraction returns NOTHING** — the far worse one. A redesign renames a class, the selector
 *    matches zero elements, and the adapter reports a perfectly healthy run with no listings. Zero
 *    listings is exactly what a quiet rental market looks like, so hard rules 2 and 3 say it must
 *    THROW. Several tests here exist only to pin that.
 */
#[CoversClass(HtmlSource::class)]
#[CoversClass(Selector::class)]
final class HtmlSourceTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../fixtures/inli/search.html';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    // ---------------------------------------------------------------- the selector micro-syntax

    #[DataProvider('selectorForms')]
    public function testSelectorResolution(string $entry, ?string $expected, string $why): void
    {
        $html = <<<'HTML'
            <div class="card" data-ref="A1" data-financement="LLI">
              <a href="/location-appartement-massy-91300/PRV-001">voir</a>
              <span class="price">1 005 € </span>
              <span class="details">3 pièces · 55.32 m²</span>
              <span class="blank">   </span>
              <a class="contact" href="mailto:agence@example.fr">écrire</a>
            </div>
            HTML;

        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $card = $document->querySelector('.card');
        self::assertNotNull($card);

        self::assertSame($expected, Selector::parse($entry)->resolve($card), $why);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function selectorForms(): iterable
    {
        yield 'plain text' => ['.price', '1 005 €', 'whitespace normalised, trailing space gone'];
        yield 'an attribute of a descendant' => ['a@href', '/location-appartement-massy-91300/PRV-001', ''];
        yield 'an attribute of the item itself' => ['@data-ref', 'A1', 'an empty selector half means the card element'];
        yield 'a capture group' => ['.details => ([\d.,]+)\s*m²', '55.32', 'the surface, isolated from the room count sharing its node'];
        yield 'attribute then capture' => ['a@href => /([^/]+)$', 'PRV-001', 'the stable id, taken off the end of the href'];
        yield 'a capture with no group falls back to the whole match' => ['.details => \d+\s*pièces', '3 pièces', ''];

        // The reason the `@` split is not a plain `explode`. A CSS selector may legitimately carry
        // an `@` inside an attribute VALUE, and splitting there would leave a broken selector and a
        // nonsense attribute name — silently, because both halves still look like something.
        yield 'an @ inside an attribute value is not an attribute split' => [
            'a[href="mailto:agence@example.fr"]', 'écrire',
            'the tail `example.fr"]` is not a valid attribute name, so the selector stays whole',
        ];

        // Hard rule 9 lives in this block: every one of these is `null` for UNKNOWN, never '' and
        // never a zero that a minimum would then reject the listing against.
        yield 'a selector that matches nothing' => ['.nope', null, 'unknown, not empty'];
        yield 'an attribute that is not there' => ['a@data-nope', null, ''];
        yield 'an element whose text is only whitespace' => ['.blank', null, 'blank is not a value'];
        yield 'a capture that does not match' => ['.price => ([\d.]+)\s*m²', null, 'no surface in a price — null, NOT the unparsed price text'];
        yield 'a capture that matches emptily' => ['.price => (x?)', null, 'an empty capture is not a value'];
        yield 'an invalid CSS selector' => ['div[', null, 'a broken field map yields unknown per field; the missing `ref` is what fails loudly'];
        yield 'an uncompilable pattern' => ['.price => ([0-9', null, ''];
    }

    public function testNormaliseCollapsesTheUnicodeSpacesFrenchTypographyUses(): void
    {
        // U+00A0 and U+202F sit between a figure and its unit on the pages this reads, and `\s`
        // does not match either without the `u` flag. Left in, they make a string that LOOKS equal
        // to its plain-space twin and is not — so a listing's text would hash differently across a
        // redesign that changed nothing a reader could see.
        self::assertSame('1 005 € cc', Selector::normalise("1\u{00A0}005\u{202F}€\n\n  cc"));
    }

    // ---------------------------------------------------------------- the frozen In'li page

    public function testTheFrozenPageYieldsEveryListingWithEveryMappedField(): void
    {
        $rows = $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.featured-items > .featured-item');

        self::assertCount(19, $rows, 'the page says "19 logements" and the extractor must agree');

        foreach ($rows as $row) {
            self::assertNotSame('', $row->externalId, 'every card must yield a stable id');
            self::assertNotNull($row->rentCc, 'every card quotes a rent');
            self::assertNotNull($row->surfaceM2);
            self::assertNotNull($row->rooms);
            self::assertNotNull($row->commune);
            self::assertStringStartsWith('https://www.inli.fr/', (string) $row->url, 'base_url resolves the relative href');
        }
    }

    public function testTheFirstCardsValuesAreExactlyWhatThePageShows(): void
    {
        // Pinned against the real markup rather than a shape assertion. A field map that resolves
        // the WRONG element still produces a full listing — this is what says it resolved the right
        // one, and it is the assertion that goes red when a redesign moves a class.
        $rows = $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.featured-items > .featured-item');
        $first = $rows[0];

        self::assertSame('441-20030-3014', $first->externalId);
        self::assertSame('LONGJUMEAU', $first->commune);
        self::assertSame('91160', $first->postcode);
        self::assertSame(1005, $first->rentCc, 'charges comprises — the card says `cc` beside the figure');
        self::assertSame(55.32, $first->surfaceM2, 'the surface, not the 3 that shares its text node');
        self::assertSame(3, $first->rooms);
        self::assertSame('https://www.inli.fr/location-appartement-longjumeau-91160/441-20030-3014', $first->url);
    }

    public function testTheWholeCardTextReachesTheClassifierEvenWhereNothingIsMapped(): void
    {
        // The classifier reads text as signal tier 2 and field NAMES as tier 1. In HTML the tier-1
        // evidence lives in attributes, and a `data-financement` nobody mapped must still be seen —
        // otherwise §1's excluded labels can sit in the markup, unread, while the listing is
        // notified. Bias runs one way here: seeing more of the card can only make an exclusion
        // easier to catch.
        $rows = $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.featured-items > .featured-item');
        $fields = $rows[0]->fields;

        self::assertArrayHasKey('_text', $fields);
        self::assertStringContainsString('55.32 m²', (string) $fields['_text']);
        self::assertStringContainsString('1 005 €', (string) $rows[0]->description, 'description falls back to the card text');
    }

    public function testAnAttributeCarryingATenureLabelReachesTheClassifierSurface(): void
    {
        $html = '<ul><li class="c" data-financement="PLS"><a href="/x/1">a</a>'
            . '<span class="p">900 €</span></li></ul>';

        $rows = $this->inli(['itemSelector' => 'li.c'])->extract($html, 'li.c');

        self::assertArrayHasKey('data.data-financement', $rows[0]->fields);
        self::assertSame('PLS', $rows[0]->fields['data.data-financement']);
    }

    // ---------------------------------------------------------------- the failure paths

    public function testASelectorThatMatchesNothingThrowsRatherThanReturningAnEmptyList(): void
    {
        // Hard rules 2 and 3, and the single most valuable assertion in this file. A redesign that
        // renames `featured-item` leaves a 200 response, valid HTML and zero listings — and zero
        // listings is byte-identical to a quiet market. It has to be loud.
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~matched no element~');

        $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.renamed-in-a-redesign');
    }

    public function testTheZeroMatchMessageNamesTheAntiBotCaseToo(): void
    {
        // An interstitial or a JSON error body parses as HTML without error — the HTML5 parser
        // takes almost any byte sequence. So the failure surfaces here, as "matched nothing", and
        // the message has to say so or it sends someone to audit selectors that were fine.
        //
        // (The wording here is deliberate. An earlier draft used a synonym for "error" that has
        // the excluded-tenure abbreviation buried inside it, close to a permissive-sounding verb,
        // and the §1 tripwire fired on the pair. It was pure prose — no tenure logic anywhere near
        // it — and CLAUDE.md's rule for that case is to reword the sentence, never the pattern.
        // A tripwire that gets relaxed each time it is inconvenient stops being a tripwire.)
        try {
            $this->inli()->extract('{"error":"forbidden"}', '.featured-item');
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('interstitial', $e->getMessage());
        }
    }

    public function testAnInvalidItemSelectorIsDiagnosedAsSuchRatherThanAsAnEmptyPage(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~not a valid CSS selector~');

        $this->inli()->extract('<div class="featured-item"></div>', 'div[');
    }

    public function testAPlaceholderUrlIsRefusedBeforeAnyRequestIsMade(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html></html>'));

        try {
            $this->inli(['url' => 'https://www.inli.fr/REMPLACER'], $client)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('hard rule 1', $e->getMessage());
        }

        self::assertSame(0, $client->calls, 'and nothing was fetched');
    }

    public function testAnHtmlSourceWithNoItemSelectorIsRefusedBeforeAnyRequestIsMade(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html></html>'));

        try {
            $this->inli(['itemSelector' => null], $client)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('item_selector', $e->getMessage());
        }

        self::assertSame(0, $client->calls);
    }

    public function testRobotsDisallowRefusesAndNeverWorksAround(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html></html>'));
        $robots = Robots::parse("User-agent: *\nDisallow: /locations/");

        try {
            $this->inli(['url' => 'https://www.inli.fr/locations/offres/x'], $client, $robots)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('email-alert route', $e->getMessage(), 'hard rule 5 names the alternative');
        }

        self::assertSame(0, $client->calls, 'refused before the request, not after');
    }

    public function testA403PointsAtTheEmailAlertRouteRatherThanAtABetterDisguise(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~email-alert route~');

        $this->inli([], new FakeHttpClient(new HttpResponse(403, 'nope')))->fetch();
    }

    public function testATransportFailureIsASourceErrorNotAnEmptyResult(): void
    {
        $this->expectException(SourceError::class);

        $this->inli([], new FakeHttpClient(null, new HttpError('connection reset')))->fetch();
    }

    public function testAFetchThatSucceedsMapsTheFrozenPageEndToEnd(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, (string) file_get_contents(self::FIXTURE)));

        $rows = $this->inli([], $client)->fetch();

        self::assertCount(19, $rows);
        self::assertSame(1, $client->calls);
    }

    // ---------------------------------------------------------------- pagination

    /**
     * Pagination exists because of a measurement, not a hunch: In'li answered `92 logements` to an
     * ordinary set of filters and put 24 on page one [verified 2026-08-19 against the live site].
     * A page-one-only adapter would have dropped 68 listings every pass and reported a healthy run.
     */
    public function testEveryPageIsWalkedAndTheListingsAreConcatenated(): void
    {
        $client = new PagedHttpClient([
            1 => self::page(['a', 'b'], 5),
            2 => self::page(['c', 'd'], 5),
            3 => self::page(['e'], 5),
        ]);

        $rows = $this->paged($client)->fetch();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($r) => $r->externalId, $rows));
        self::assertSame([null, '2', '3', '4'], $client->pages, 'page one carries no page param');
    }

    public function testTheWalkStopsAtTheFirstPageWithNoListings(): void
    {
        $client = new PagedHttpClient([1 => self::page(['a'], 1)]);

        self::assertCount(1, $this->paged($client)->fetch());
        self::assertSame([null, '2'], $client->pages, 'one probe past the end, then stop');
    }

    public function testAWalkThatCollectsFewerThanThePageDeclaresIsAFailureNotAShortResultSet(): void
    {
        // The assertion the whole mechanism exists for. Walking until a page comes back empty is a
        // termination rule, not a proof — a `page=2` that quietly 404s or redirects to page one
        // ends the walk exactly like a real last page. Only the site's own declared total can tell
        // those apart, and without it 24-of-92 is indistinguishable from a thin market.
        $client = new PagedHttpClient([1 => self::page(['a', 'b'], 92)]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~collected 2 listings but the page declares 92~');

        $this->paged($client)->fetch();
    }

    public function testAPageParamTheSiteIgnoresIsRefusedRatherThanRequestedForever(): void
    {
        // Every page returns page one, so the walk never terminates naturally. Unbounded, this is
        // an infinite request loop against somebody else's server — the one bug in this adapter
        // that could actually get an IP banned, which hard rule 5 leaves polite pacing to prevent.
        $client = new PagedHttpClient([], self::page(['same'], null));

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~reached the 4-page bound~');

        $this->paged($client, ['maxPages' => 4])->fetch();
    }

    public function testASourceWithNoPageParamMakesExactlyOneRequest(): void
    {
        $client = new PagedHttpClient([1 => self::page(['a'], null)]);

        self::assertCount(1, $this->paged($client, ['pageParam' => null])->fetch());
        self::assertSame([null], $client->pages);
    }

    /** A minimal results page: `$refs` cards, and optionally a declared total. */
    private static function page(array $refs, ?int $total): string
    {
        $cards = '';
        foreach ($refs as $ref) {
            $cards .= '<div class="featured-item"><a href="/l/' . $ref . '"><img alt="MASSY">'
                . '<div class="featured-price"><span class="demi-condensed">900 €</span></div>'
                . '<div class="featured-details"><span>2 pièces · 40 m²</span></div></a></div>';
        }

        return '<html><body>'
            . ($total === null ? '' : '<div class="sf-results-head"><h5>' . $total . ' logements</h5></div>')
            . '<div class="featured-items">' . $cards . '</div></body></html>';
    }

    /** @param array<string, mixed> $overrides */
    private function paged(PagedHttpClient $client, array $overrides = []): HtmlSource
    {
        $definition = new SourceDefinition(
            name: 'inli',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: false,
            defaultTenure: Tenure::LLI,
            url: 'https://www.inli.fr/locations/offres/x',
            baseUrl: 'https://www.inli.fr',
            itemSelector: '.featured-items > .featured-item',
            map: new FieldMap(
                ref: ['a@href => /([^/]+)$'],
                url: ['a@href'],
                commune: ['img@alt'],
                rent: ['.featured-price .demi-condensed'],
                chargesIncluded: true,
            ),
            pageParam: array_key_exists('pageParam', $overrides) ? $overrides['pageParam'] : 'page',
            totalSelector: '.sf-results-head h5',
            // Zero, so the suite does not actually sleep between pages. The delay itself is a
            // production concern (see the walk); what these tests pin is the walk's shape.
            maxPages: $overrides['maxPages'] ?? 20,
            rateLimitMs: 0,
        );

        return new HtmlSource($definition, $this->store(), $client);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $overrides */
    private function inli(array $overrides = [], ?FakeHttpClient $client = null, ?Robots $robots = null): HtmlSource
    {
        $definition = new SourceDefinition(
            name: 'inli',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: false,
            defaultTenure: Tenure::LLI,
            url: $overrides['url'] ?? 'https://www.inli.fr/locations/offres/ile-de-france-region_r:11',
            baseUrl: 'https://www.inli.fr',
            itemSelector: array_key_exists('itemSelector', $overrides)
                ? $overrides['itemSelector']
                : '.featured-items > .featured-item',
            map: new FieldMap(
                ref: ['a@href => /([^/]+)$'],
                url: ['a@href'],
                commune: ['img@alt'],
                postcode: ['a@href => -(\d{5})/'],
                rent: ['.featured-price .demi-condensed', '.p'],
                surface: ['.featured-details span => ([\d.,]+)\s*m²'],
                rooms: ['.featured-details span => (\d+)\s*pièce'],
                chargesIncluded: true,
            ),
        );

        return new HtmlSource($definition, $this->store(), $client ?? new FakeHttpClient(null), $robots);
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-html-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }
    // ---------------------------------------------------------------- pagination in the PATH

    /**
     * Some sites paginate in the path, not the query string — and for CDC Habitat that is the whole
     * reason the source is pollable at all.
     *
     * Its `robots.txt` disallows every search QUERY PARAMETER by name (`?nbPiece`, `?nbLoyerMin`,
     * `?cdTypeBien`, …). Its own sitemap advertises `/recherche/location/<region>/page-2/`, which
     * carries no query string. A `page_param` walk would append `?page=2` to a URL the site asked
     * not to be queried; `page_path` walks the shape the site publishes.
     */
    public function testAPathPaginatedSourceWalksPagesInThePathNotTheQueryString(): void
    {
        $client = new PathPagedHttpClient([
            1 => self::page(['a', 'b'], 5),
            2 => self::page(['c', 'd'], 5),
            3 => self::page(['e'], 5),
        ]);

        $rows = $this->pathPaged($client)->fetch();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($r) => $r->externalId, $rows));
        self::assertSame([
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france',
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france/page-2/',
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france/page-3/',
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france/page-4/',
        ], $client->urls, 'page one is the bare url; the rest carry the path segment');
        self::assertSame([], $client->queries, 'no query string is ever added — the site disallows those');
    }

    /**
     * robots.txt is consulted for EVERY page, not only the first.
     *
     * With query-string pagination the path never changes, so one check covered the whole walk. A
     * path-paginated walk visits a different path on every request, and a site may allow the index
     * while disallowing the paginated form. Checking only page one would then walk straight into
     * the disallowed paths with a clean conscience — the exact shape of hard rule 5 violation that
     * is invisible from the outside because every request returns 200.
     */
    public function testPathPaginationChecksRobotsForEveryPageNotJustTheFirst(): void
    {
        $client = new PathPagedHttpClient([1 => self::page(['a', 'b'], 5), 2 => self::page(['c'], 5)]);
        $robots = Robots::parse("User-agent: *\nDisallow: /recherche/location/ile-de-france/page-\n");

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~robots.txt disallows /recherche/location/ile-de-france/page-2/~');

        $this->pathPaged($client, [], $robots)->fetch();
    }

    /** The `{page}` placeholder is the whole contract — a template without it would refetch page one forever. */
    public function testAPagePathWithNoPlaceholderIsRefused(): void
    {
        $client = new PathPagedHttpClient([1 => self::page(['a'], 5)]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~\{page\}~');

        $this->pathPaged($client, ['pagePath' => '/page-2/'])->fetch();
    }

    /** @param array<string, mixed> $overrides */
    private function pathPaged(PathPagedHttpClient $client, array $overrides = [], ?Robots $robots = null): HtmlSource
    {
        $definition = new SourceDefinition(
            name: 'cdc_habitat',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            defaultTenure: null,
            url: 'https://www.cdc-habitat.fr/recherche/location/ile-de-france',
            baseUrl: 'https://www.cdc-habitat.fr',
            itemSelector: '.featured-items > .featured-item',
            map: new FieldMap(
                ref: ['a@href => /([^/]+)$'],
                url: ['a@href'],
                commune: ['img@alt'],
                rent: ['.featured-price .demi-condensed'],
                chargesIncluded: true,
            ),
            pagePath: array_key_exists('pagePath', $overrides) ? $overrides['pagePath'] : '/page-{page}/',
            totalSelector: '.sf-results-head h5',
            maxPages: $overrides['maxPages'] ?? 20,
            rateLimitMs: 0,
        );

        return new HtmlSource($definition, $this->store(), $client, $robots);
    }

}


/**
 * Answers per requested page, and records which pages were asked for.
 *
 * The page is read back out of the URL rather than tracked by call count, so the test also proves
 * the adapter puts the parameter where it said it would — a fake that just counted calls would
 * pass just as happily if every request went to page one.
 */
final class PagedHttpClient implements \RentWatch\Adapters\Http\HttpClient
{
    /** @var list<string|null> */
    public array $pages = [];

    /** @param array<int, string> $bodies */
    public function __construct(
        private readonly array $bodies,
        private readonly ?string $fallback = null,
    ) {}

    public function send(\RentWatch\Adapters\Http\HttpRequest $request): HttpResponse
    {
        $query = parse_url($request->url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        $page = isset($params['page']) ? (string) $params['page'] : null;
        $this->pages[] = $page;

        $body = $this->bodies[(int) ($page ?? 1)] ?? $this->fallback ?? '<html><body><div class="featured-items"></div></body></html>';

        return new HttpResponse(200, $body);
    }
}

/** Serves pages addressed by a `/page-N/` PATH segment, and records the URLs it was asked for. */
final class PathPagedHttpClient implements \RentWatch\Adapters\Http\HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<string> */
    public array $queries = [];

    /** @param array<int, string> $bodies */
    public function __construct(private readonly array $bodies) {}

    public function send(\RentWatch\Adapters\Http\HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;

        $query = parse_url($request->url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $this->queries[] = $query;
        }

        $page = preg_match('~/page-(\d+)/~', $request->url, $m) === 1 ? (int) $m[1] : 1;
        $body = $this->bodies[$page] ?? '<html><body><div class="featured-items"></div></body></html>';

        return new HttpResponse(200, $body);
    }
}

