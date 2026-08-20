<?php

declare(strict_types=1);

namespace RentWatch\Adapters;

use Dom\Element;
use Dom\HTMLDocument;
use RentWatch\Adapters\Html\Selector;
use RentWatch\Adapters\Http\CurlHttpClient;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * Polls a server-rendered search page and maps its listing cards with the source's field map.
 *
 * The twin of {@see HttpJsonSource}, and deliberately its mirror image: same url checks, same
 * `robots.txt` refusal, same honest User-Agent, same rule that a failure THROWS rather than
 * returning an empty list. What differs is one step in the middle — the payload is parsed as HTML
 * and the field map is read as CSS selectors ({@see Selector}) instead of dotted paths.
 *
 * **No hand-written selector engine, and that was not the original plan.** PHP 8.4 introduced
 * `Dom\HTMLDocument`, a spec-compliant HTML5 parser with `querySelectorAll`, and 8.5 ships it here.
 * It handles the unclosed `<li>` and the bare `&euro;` that real pages are full of, which a
 * regex-and-hope parser does not — and it is the language's own, so there is nothing between the
 * markup and the boolean that arms §1.
 *
 * **The extraction still funnels through {@see ListingMapper}.** Every element is resolved into a
 * plain array keyed by field name, and the mapper runs over that unchanged. That is the whole
 * reason there is no second implementation of hard rule 9 here: `null` is not zero, an empty string
 * is not a value, and a missing `ref` is a loud source failure — all of it decided in one place for
 * both adapters. An HTML-specific mapper would have had to re-derive every one of those.
 *
 * **What it does NOT do, stated so it is not mistaken for a bug:** it reads page one only, and it
 * never follows a card through to its detail page. Both are deliberate for a first cut — pagination
 * is a separate failure profile (a page-2 fetch that quietly 404s looks exactly like a short result
 * set), and a detail fetch multiplies request count by the number of listings, which is precisely
 * the polling burst Q37 pacing exists to prevent. Floor and lift therefore stay `null`, which Q5
 * already accounts for: they are score components, never disqualifiers.
 */
final readonly class HtmlSource implements Source
{
    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private HttpClient $client = new CurlHttpClient(),
        private ?Robots $robots = null,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    /** Tolerant by design, for the same reason {@see HttpJsonSource::host()} is — see its note. */
    public function host(): ?string
    {
        $url = $this->definition->url;
        if ($url === null || $url === '' || str_contains($url, 'REMPLACER')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public function family(): string
    {
        return $this->definition->family === 'private' ? 'private' : 'institutional';
    }

    public function defaultTenure(): ?Tenure
    {
        return $this->definition->defaultTenure;
    }

    public function profile(): SourceProfile
    {
        return $this->definition->profile();
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->store->health($this->name(), $nowIso);
    }

    /**
     * @return list<\RentWatch\Core\RawListing>
     *
     * @throws SourceError
     */
    public function fetch(): array
    {
        $url = $this->definition->url;

        if ($url === null || $url === '') {
            throw new SourceError($this->name(), 'no url configured');
        }

        if (str_contains($url, 'REMPLACER')) {
            throw new SourceError(
                $this->name(),
                'the url is still the REMPLACER placeholder. CLAUDE.md hard rule 1: verify the '
                    . 'endpoint against the live site before enabling this source',
            );
        }

        $selector = $this->definition->itemSelector;
        if ($selector === null || trim($selector) === '') {
            throw new SourceError(
                $this->name(),
                'no item_selector configured — an html source cannot know which element is a listing',
            );
        }

        if ($this->robots !== null && !$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError(
                $this->name(),
                'robots.txt disallows ' . Robots::pathOf($url) . ' — this source must not be polled. '
                    . 'Use the email-alert route instead',
            );
        }

        $pageParam = $this->definition->pageParam;
        $pagePath = $this->definition->pagePath;

        if ($pageParam !== null && $pagePath !== null) {
            throw new SourceError(
                $this->name(),
                'both page_param and page_path are configured — a source paginates one way or the '
                    . 'other, and guessing which one the site honours is how a walk silently refetches '
                    . 'page one',
            );
        }

        if ($pagePath !== null && !str_contains($pagePath, '{page}')) {
            throw new SourceError(
                $this->name(),
                'page_path `' . $pagePath . '` contains no {page} placeholder, so every page after '
                    . 'the first would request the same url — a walk that never advances and never ends',
            );
        }

        $body = $this->get($url, []);
        $out = $this->extract($body, $selector);

        if ($pageParam === null && $pagePath === null) {
            return $out;
        }

        // ── walk the remaining pages ──────────────────────────────────────────────────────────
        //
        // Terminated three ways, and only one of them is success. A page that yields zero listings
        // ends the walk; `maxPages` ends it as a FAILURE; and the declared total is what decides
        // whether the walk that ended "naturally" actually reached the end. Walking until a page
        // comes back empty is a termination rule, not a correctness proof — a `page=3` that quietly
        // 404s or redirects to page one terminates exactly like a genuine last page.
        $total = $this->declaredTotal($body);
        $page = 1;

        while ($page < $this->definition->maxPages) {
            ++$page;

            // Between pages, because these requests never pass through `PacedSource` — it wraps one
            // `fetch()`, and every page after the first is inside it. Without this a four-page walk
            // is four requests back to back, which is the burst hard rule 5 forbids.
            if ($this->definition->rateLimitMs > 0) {
                usleep($this->definition->rateLimitMs * 1000);
            }

            if ($pagePath !== null) {
                $pageUrl = $url . str_replace('{page}', (string) $page, $pagePath);

                // Re-checked per page, because with path pagination the PATH is what changes. A
                // site may publish an index it welcomes and paginated forms it does not, and a
                // one-shot check on the index would walk into those with every request returning
                // 200 — a hard rule 5 breach that is invisible from the outside.
                if ($this->robots !== null && !$this->robots->allows(Robots::pathOf($pageUrl))) {
                    throw new SourceError(
                        $this->name(),
                        'robots.txt disallows ' . Robots::pathOf($pageUrl) . ' — the index is '
                            . 'pollable but its paginated form is not, so this walk must stop here',
                    );
                }

                $pageBody = $this->get($pageUrl, []);
            } else {
                $pageBody = $this->get($url, [$pageParam => (string) $page]);
            }

            try {
                $rows = $this->extract($pageBody, $selector);
            } catch (SourceError) {
                // A page past the last one legitimately has no cards. Only the FIRST page's
                // emptiness is a breakage — that one is rethrown above, before this loop starts.
                break;
            }

            $out = [...$out, ...$rows];

            // THE SITE'S OWN COUNT IS THE TERMINATOR, when it gives one.
            //
            // The alternative — walk until a page comes back empty — assumes a page past the end
            // comes back empty. CDC Habitat's does not: page 11 of a 9-page result set answers 301
            // [measured 2026-08-20], and this adapter refuses a non-2xx deliberately, because a
            // redirect that lands back on page one ends a walk exactly like a genuine last page.
            // The probe therefore turned a complete, correct walk into `broken / 0 items`.
            //
            // This is not new trust in the total: it is already the assertion the check below
            // makes. Using it to STOP as well as to VERIFY also costs one fewer request per pass
            // against somebody else's server, which is hard rule 5's direction anyway. With no
            // declared total there is nothing to stop on, and the empty-page probe still applies.
            if ($total !== null && count($out) >= $total) {
                break;
            }
        }

        if ($page >= $this->definition->maxPages) {
            throw new SourceError(
                $this->name(),
                'pagination reached the ' . $this->definition->maxPages . '-page bound without ending — '
                    . 'the `' . ($pageParam ?? $pagePath) . '` ' . ($pageParam !== null ? 'parameter' : 'path template')
                    . ' is probably ignored by the site, so every page returns the first one. '
                    . 'Refusing to keep requesting',
            );
        }

        if ($total !== null && count($out) < $total) {
            // The assertion the walk exists to make. Fewer listings than the site itself declares
            // means pages were lost, and a run that quietly reports 24 of 92 looks exactly like a
            // thin market — forever, and with a healthy source badge on it.
            //
            // Strict `<` is deliberate, and its cost is stated: listings can genuinely appear
            // between the first page fetch and the last, so a real change can trip this. That
            // trades a rare loud failure — recovered on the next pass 15 minutes later, and not
            // alerted until three in a row — against permanent silent truncation. Not a close call.
            throw new SourceError(
                $this->name(),
                'collected ' . count($out) . ' listings but the page declares ' . $total
                    . ' — pagination lost results rather than reaching the end',
            );
        }

        return $out;
    }

    /**
     * One request, with the source's own params plus any extra.
     *
     * @param array<string, string> $extra
     *
     * @throws SourceError
     */
    private function get(string $url, array $extra): string
    {
        $request = (new HttpRequest(
            url: $url,
            method: $this->definition->method,
            headers: $this->definition->headers,
            body: $this->definition->body,
        ))->withQuery([...$this->definition->params, ...$extra]);

        try {
            $response = $this->client->send($request);
        } catch (HttpError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        if (!$response->isSuccess()) {
            throw new SourceError(
                $this->name(),
                'HTTP ' . $response->status . ' from ' . $url
                    . ($response->status === 403 ? ' — this source blocks plain clients; use the email-alert route' : ''),
            );
        }

        return $response->body;
    }

    /**
     * The result count the page states about itself, or `null` when the source declares no selector.
     *
     * Read through the same number parser everything else uses, so `92 logements` yields 92 and the
     * fusion trap that `Payload::number()` guards against cannot reappear here in a private copy.
     */
    private function declaredTotal(string $html): ?int
    {
        $selector = $this->definition->totalSelector;
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable) {
            return null;
        }

        $root = $document->documentElement;
        if ($root === null) {
            return null;
        }

        $text = Selector::parse($selector)->resolve($root);

        return $text === null ? null : Payload::int(['v' => $text], ['v']);
    }

    /**
     * @return list<\RentWatch\Core\RawListing>
     *
     * @throws SourceError
     */
    public function extract(string $html, string $itemSelector): array
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable $e) {
            throw new SourceError($this->name(), 'response could not be parsed as HTML: ' . $e->getMessage(), $e);
        }

        try {
            $items = $document->querySelectorAll($itemSelector);
        } catch (\Throwable $e) {
            throw new SourceError(
                $this->name(),
                'item_selector `' . $itemSelector . '` is not a valid CSS selector: ' . $e->getMessage(),
                $e,
            );
        }

        if ($items->count() === 0) {
            // THE failure this adapter exists to make loud. A search page that still returns 200
            // and still parses, whose card markup was renamed in a redesign, yields zero items
            // forever — and zero listings is exactly what a quiet rental market looks like. Hard
            // rules 2 and 3: never `[]` to signal breakage.
            //
            // It is also where a WAF interstitial or a JSON error body lands, because the HTML5
            // parser accepts almost any byte sequence rather than refusing it — so the message says
            // so, instead of sending someone to inspect selectors that were never the problem.
            throw new SourceError(
                $this->name(),
                'item_selector `' . $itemSelector . '` matched no element — the page markup changed, '
                    . 'the selector is wrong, or the response was not the search page at all '
                    . '(an anti-bot interstitial parses as HTML without error)',
            );
        }

        $mapper = new ListingMapper($this->flatMapped());

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof Element) {
                continue;
            }
            $out[] = $mapper->map($this->itemToArray($item));
        }

        return $out;
    }

    /**
     * One listing element, resolved into the flat array {@see ListingMapper} expects.
     *
     * Three kinds of key, and the last two exist for the classifier rather than for the mapper:
     *
     * - **field names** — `ref`, `rent`, `surface`… each resolved from the source's own selectors,
     *   first non-null wins, mirroring {@see Payload::first()}.
     * - **`_text`** — the whole card, whitespace normalised. It is the classifier's text surface,
     *   and it is included unconditionally because the bias runs one way: §1 says an ambiguous
     *   listing must not be notified, so seeing MORE of the card can only make a `logement social`
     *   label easier to catch. It also serves as the `description` fallback.
     * - **`attr.*` / `data.*`** — every attribute of the card and every `data-*` beneath it. The
     *   classifier reads field NAMES as tier-1 evidence, and in HTML that evidence lives in
     *   attributes: a `data-financement="PLS"` nobody thought to map must still be seen.
     *
     * @return array<string, string>
     */
    private function itemToArray(Element $item): array
    {
        $map = $this->definition->map;
        $out = [];

        foreach (self::FIELDS as $field) {
            /** @var list<string> $entries */
            $entries = $map->{$field};
            foreach ($entries as $entry) {
                $value = Selector::parse($entry)->resolve($item);
                if ($value !== null) {
                    $out[$field] = $value;
                    break;
                }
            }
        }

        $out['_text'] = Selector::normalise($item->textContent);

        // ONE key shape per attribute, whatever its depth. Every `data-*` goes under `data.`
        // wherever it sits, and the card's other attributes go under `attr.`.
        //
        // The obvious split — the card's own attributes under `attr.`, descendants' `data-*` under
        // `data.` — was what this had first, and a test caught it: `data-financement` on the CARD
        // landed under `attr.` while the identical attribute one element deeper landed under
        // `data.`. The classifier would then see the same tier-1 evidence under two different names
        // according to markup depth, and a rule written for one of them silently misses the other.
        // That is the exact shape `SurfaceMatrixTest` exists to catch: a correct rule applied to a
        // subset of the surfaces it belongs on.
        foreach ($item->attributes as $attribute) {
            if (!str_starts_with($attribute->name, 'data-')) {
                $out['attr.' . $attribute->name] = $attribute->value;
            }
        }

        foreach ([$item, ...iterator_to_array($item->querySelectorAll('*'))] as $element) {
            if (!$element instanceof Element) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'data-') && trim($attribute->value) !== '') {
                    $out['data.' . $attribute->name] = $attribute->value;
                }
            }
        }

        return $out;
    }

    /**
     * The source, with its field map rewritten to literal keys.
     *
     * {@see itemToArray()} has already done the selecting, so the mapper is handed paths that are
     * just field names. `chargesIncluded` is carried across untouched — it is a declaration about
     * what `rent` MEANS, not a path, and dropping it would make the mapper file a charges-comprises
     * rent as hors charges for a whole source.
     *
     * `description` falls back to `_text`: a card with no dedicated description element still owes
     * the classifier its words, and an absent description is the shape that quietly starves signal
     * tier 2.
     */
    private function flatMapped(): SourceDefinition
    {
        $map = $this->definition->map;
        $literal = [];
        foreach (self::FIELDS as $field) {
            $literal[$field] = $map->{$field} === [] ? [] : [$field];
        }

        return new SourceDefinition(
            name: $this->definition->name,
            enabled: $this->definition->enabled,
            family: $this->definition->family,
            type: $this->definition->type,
            mixedTenure: $this->definition->mixedTenure,
            defaultTenure: $this->definition->defaultTenure,
            url: $this->definition->url,
            baseUrl: $this->definition->baseUrl,
            map: new FieldMap(
                ref: $literal['ref'],
                title: $literal['title'],
                url: $literal['url'],
                commune: $literal['commune'],
                postcode: $literal['postcode'],
                rent: $literal['rent'],
                rentHc: $literal['rentHc'],
                charges: $literal['charges'],
                surface: $literal['surface'],
                rooms: $literal['rooms'],
                bedrooms: $literal['bedrooms'],
                floor: $literal['floor'],
                elevator: $literal['elevator'],
                description: [...$literal['description'], '_text'],
                tenureField: $literal['tenureField'],
                chargesIncluded: $map->chargesIncluded,
            ),
            legalRisk: $this->definition->legalRisk,
            rateLimitMs: $this->definition->rateLimitMs,
        );
    }

    /**
     * Every mappable field, named as {@see FieldMap} names it.
     *
     * Written out rather than reflected, so that adding a field to `FieldMap` without deciding what
     * it means in HTML is a visible omission here instead of a silently unresolved selector.
     */
    private const array FIELDS = [
        'ref', 'title', 'url', 'commune', 'postcode', 'rent', 'rentHc', 'charges',
        'surface', 'rooms', 'bedrooms', 'floor', 'elevator', 'description', 'tenureField',
    ];
}
