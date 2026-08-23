<?php

declare(strict_types=1);

namespace RentWatch\Adapters;

use Dom\Element;
use Dom\HTMLDocument;
use RentWatch\Adapters\Html\Selector;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\RawListing;
use RentWatch\Core\Redact;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;
use RentWatch\Store\StoredDetail;

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
 * **It paginates, three ways, and exactly one per source:** `page_param` (a query parameter),
 * `page_path` (a suffix appended to the url) or a `{page}` template in the url itself, for a site
 * whose page number sits mid-path. Configuring two is refused at load AND here, because whichever
 * one the adapter picks, the ignored mechanism fails silently. A walk ends on the site's own
 * declared total where it publishes one, on an empty page otherwise, and on `maxPages` as a
 * FAILURE — a page that quietly 404s or redirects to page one otherwise terminates a walk exactly
 * like a genuine last page.
 *
 * **It follows a card to its detail page only when told to, and only for listings that matter.**
 * A `detail_map` costs one request PER LISTING, which is the polling burst Q37 pacing exists to
 * prevent, so hydration runs behind a gate the CALLER supplies — the run's own geographic criteria
 * — and a `detail_map` with no gate is refused rather than defaulted either way. Two things about
 * that path are load-bearing rather than incidental: it does not add `_text` (on a detail page that
 * would be the whole page, whose furniture conflicts a correct verdict into UNKNOWN), and a detail
 * value never erases what the card knew (hard rule 9, in {@see RawListing::mergedWith()}).
 *
 * **What it still does NOT do, stated so it is not mistaken for a bug:** nothing here reads a
 * source's own filters. Fields a source publishes only behind a form — `bedrooms` on every source
 * so far — stay `null`, which Q5 and hard rule 9 already account for: unknown is not zero, and the
 * criteria engine must not disqualify on it.
 */
final readonly class HtmlSource implements Source
{
    /**
     * @param ?\Closure(RawListing): int $detailPriority which listings go FIRST when the per-pass
     *        budget cannot cover them all. Lower ranks first; ties keep source order. Supplied by
     *        the caller rather than configured, because the ranking is the run's own state — see
     *        {@see \RentWatch\Cli\Scout} for the ranks and why *not yet seen* outranks everything.
     *
     *        It is an ORDERING, no longer a gate. Novelty is the gate, and it lives in the cache: a
     *        listing whose detail page is already on record costs no request at all. Ordering on
     *        card-complete fields decides only WHEN a page is fetched, never whether a listing is
     *        judged on it, so hard rule 8 is untouched.
     *
     *        `null` means every candidate ranks equally and the budget is spent in source order.
     */
    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private HttpClient $client,
        /**
         * REQUIRED, and deliberately not nullable. It was `?Robots $robots = null` until
         * 2026-08-21, and that default is the whole reason hard rule 5 went unenforced for months:
         * a `null` here does not mean *"check later"*, it means *"never check"*, silently, on every
         * real poll — and both production construction sites took the default. A caller that has no
         * verdict must pass a fail-closed one from {@see RobotsResolver}, or an explicit
         * `Robots::parse('')` to say in as many words that this call site is not about robots.
         */
        private Robots $robots,
        private ?\Closure $detailPriority = null,
        /**
         * A FIXED clock for tests, per the convention {@see \RentWatch\Cli\Scout} already uses.
         * The hydration cache records when each attempt happened, and the retry backoff reads it
         * back — a backoff measured by SQL `now()` cannot be tested, and an untested backoff is how
         * a dead page gets re-fetched every fifteen minutes for ever.
         */
        private ?string $nowIso = null,
    ) {}

    /**
     * How many times a detail page may fail before it is left alone.
     *
     * Three, not one: a 503 during a deploy is not a dead page. Not unlimited, because the failure
     * that matters is the permanent one — a listing whose page has been removed while the card
     * lingers — and retrying that every pass for ever is a slow crawl aimed at a 404.
     */
    public const int DETAIL_ATTEMPT_CAP = 3;

    /** Hours between retries of a failed detail page. Long enough that a bad afternoon passes. */
    public const int DETAIL_RETRY_BACKOFF_HOURS = 6;

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

        if (!$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($url)) . ' — this source must not be polled. '
                    . 'Use the email-alert route instead',
            );
        }

        $pageParam = $this->definition->pageParam;
        $pagePath = $this->definition->pagePath;

        // A `{page}` in the URL ITSELF, for a site whose page number is not a suffix. Cityloger
        // paginates through `resultats-location-{page}-defaut-`, where the number sits in the middle
        // of the path — which `page_path` cannot express, because it appends.
        //
        // The rejected alternative is worth recording: point `url` at the site root and let page one
        // be the homepage, whose listing widget holds the same ten cards today [verified 2026-08-20,
        // ids identical]. That equality is not a guarantee. The day the widget becomes "featured"
        // rather than ranks 1-10, page one's listings are simply never fetched, and a source that
        // silently drops its first page looks exactly like a smaller market (hard rule 2).
        $urlTemplate = str_contains($url, '{page}');

        if ($urlTemplate && ($pageParam !== null || $pagePath !== null)) {
            throw new SourceError(
                $this->name(),
                'the url carries a {page} template AND ' . ($pageParam !== null ? 'page_param' : 'page_path')
                    . ' is configured — a source paginates one way or the other, and guessing which '
                    . 'one the site honours is how a walk silently refetches page one',
            );
        }

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

        $firstUrl = $urlTemplate ? str_replace('{page}', '1', $url) : $url;

        if ($urlTemplate && !$this->robots->allows(Robots::pathOf($firstUrl))) {
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($firstUrl)) . ' — this source must not be '
                    . 'polled. Use the email-alert route instead',
            );
        }

        $body = $this->get($firstUrl, []);
        $out = $this->extract($body, $selector);

        if (!$urlTemplate && $pageParam === null && $pagePath === null) {
            return $this->hydrate($out);
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

            if ($urlTemplate || $pagePath !== null) {
                $pageUrl = $urlTemplate
                    ? str_replace('{page}', (string) $page, $url)
                    : $url . str_replace('{page}', (string) $page, $pagePath);

                // Re-checked per page, because with path pagination the PATH is what changes. A
                // site may publish an index it welcomes and paginated forms it does not, and a
                // one-shot check on the index would walk into those with every request returning
                // 200 — a hard rule 5 breach that is invisible from the outside.
                if (!$this->robots->allows(Robots::pathOf($pageUrl))) {
                    throw new SourceError(
                        $this->name(),
                        $this->robots->refusal(Robots::pathOf($pageUrl)) . ' — the index is '
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
                    . 'the `' . ($pageParam ?? $pagePath ?? $url) . '` ' . ($pageParam !== null ? 'parameter' : 'path template')
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

        return $this->hydrate($out);
    }

    /**
     * Fetch each gated listing's own page and merge what only that page knows.
     *
     * No detail map means no second request, and that is the whole cost model: a source without one
     * behaves exactly as before, and a source with one spends requests only on listings the run
     * would actually act on.
     *
     * @param list<RawListing> $listings
     *
     * @return list<RawListing>
     *
     * @throws SourceError
     */
    private function hydrate(array $listings): array
    {
        $detailMap = $this->definition->detailMap;

        if ($detailMap === null) {
            return $listings;
        }

        $now = $this->now();
        $owed = [];
        $out = [];

        // Pass one spends NO requests. It answers, per listing, "is the page already on record?" —
        // and a hit is merged here, which is the whole point of the cache: steady state is zero
        // extra requests, and only a genuinely new listing costs one.
        foreach ($listings as $index => $listing) {
            $cached = $this->store->detail($this->name(), $listing->externalId, $detailMap->fingerprint());

            if ($cached !== null && $cached->fields !== null) {
                $out[$index] = $this->mergeDetail($listing, $detailMap, $cached->fields);

                continue;
            }

            $out[$index] = $listing;

            if ($this->mayAttempt($cached, $now)) {
                $owed[$index] = $listing;
            }
        }

        // Pass two spends the budget, and ORDER is load-bearing. Ranked by the caller, whose first
        // rank is "not yet in the seen-set": a listing about to be notified must never lose its
        // slot to backlog, because by the time backlog's slot comes round the new one has already
        // been notified unhydrated and hydrating it then buys nothing.
        $budget = $this->definition->detailBudgetPerPass;
        $spent = 0;

        foreach ($this->rankedForHydration($owed) as $index => $listing) {
            if ($spent >= $budget) {
                break;
            }

            // Counted BEFORE the attempt, so a failure spends a slot too. Counting successes
            // instead lets a pass full of dead pages issue requests without limit, hunting — which
            // is the crawl this budget exists to prevent, wearing a retry for a costume.
            ++$spent;
            $out[$index] = $this->withDetail($listing, $detailMap, $now);
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Is this listing owed a request at all?
     *
     * Three states, and they are not the same: never attempted (fetch), attempted and failed within
     * the cap and past the backoff (retry), attempted and failed too often or too recently (leave
     * it). A hydrated row never reaches here — it was merged without a request.
     */
    private function mayAttempt(?StoredDetail $cached, string $nowIso): bool
    {
        if ($cached === null) {
            return true;
        }

        if ($cached->attempts >= self::DETAIL_ATTEMPT_CAP) {
            return false;
        }

        $last = $cached->lastAttemptAt;

        if ($last === null) {
            return true;
        }

        try {
            $since = new \DateTimeImmutable($last);
            $now = new \DateTimeImmutable($nowIso);
        } catch (\Exception) {
            // An undateable stamp is treated as due rather than as permanently blocked. The bias is
            // one redundant request, never a listing silently frozen out of hydration by a row
            // nobody can read — same choice `Store::upgradeFrom()` makes for an undateable sighting.
            return true;
        }

        return $now->getTimestamp() - $since->getTimestamp() >= self::DETAIL_RETRY_BACKOFF_HOURS * 3600;
    }

    /**
     * The candidates, best first.
     *
     * A stable sort, deliberately: within a rank the source's own order is the fairest thing there
     * is, and an unstable sort would make which listing gets hydrated depend on PHP's internals.
     *
     * @param array<int,RawListing> $owed
     *
     * @return array<int,RawListing>
     */
    private function rankedForHydration(array $owed): array
    {
        if ($this->detailPriority === null || $owed === []) {
            return $owed;
        }

        $ranked = [];

        foreach ($owed as $index => $listing) {
            $ranked[] = [($this->detailPriority)($listing), $index, $listing];
        }

        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $out = [];

        foreach ($ranked as [, $index, $listing]) {
            $out[$index] = $listing;
        }

        return $out;
    }

    /** The convention {@see \RentWatch\Cli\Scout::nowIso()} uses: injected for tests, real otherwise. */
    private function now(): string
    {
        return $this->nowIso ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }

    /**
     * One listing, one extra request, merged under hard rule 9.
     *
     * **WHAT THROWS AND WHAT IS RECORDED — the taxonomy, because this is hard rule 3 territory and
     * the wrong split is a defect either way.**
     *
     * A CONFIG-SHAPED failure throws: robots refusing the detail path, or a card with no url. Those
     * are STATES, not events — every hydration on the source would fail for the same reason, for
     * ever — so recording them per listing would be pretending to try. They mean the `detail_map`
     * is unusable and someone must be told.
     *
     * A PER-LISTING RUNTIME failure is recorded and the pass continues: an HTTP failure, an
     * unparseable page. This USED to throw, on the argument that returning the listing unhydrated
     * converts a broken fetch into a listing that merely looks tenure-less. That argument was right
     * about silence and wrong about blast radius: a throw here voids the ENTIRE pass, so one
     * permanently-404ing detail page meant the source returned nothing, recorded nothing as seen,
     * and never notified a genuinely new listing again — while its health reported SOURCE_BROKEN
     * with a diagnosis that was simply untrue, since the source was fine and one page was gone.
     * Over-rejection at source scale plus a misleading alert, which is two of this project's three
     * named failure modes at once.
     *
     * Recording is not silence, and that distinction is the whole justification: the failure is
     * persisted with its attempt count and its (redacted) message, {@see Store::detailFailureCount}
     * counts it, and health surfaces the pattern that matters — not one dead page, but fifty, which
     * is a landlord who changed their detail markup.
     *
     * Stated cost: a listing whose detail page cannot be read is judged on its card alone, exactly
     * as every listing on this source is judged today. `exclude_title_patterns` cannot fire on it.
     *
     * @throws SourceError on a config-shaped failure only
     */
    private function withDetail(RawListing $listing, FieldMap $detailMap, string $atIso): RawListing
    {
        $url = $listing->url;

        if ($url === null || $url === '') {
            throw new SourceError(
                $this->name(),
                'listing ' . $listing->externalId . ' passed the detail gate but carries no url, so '
                    . 'its detail page cannot be fetched — the card\'s `url` mapping is wrong',
            );
        }

        // Its own robots verdict, because a detail page is a different path from the search index.
        // A site may publish a search it welcomes and listing pages it does not, and a check made
        // once on the index would walk into every one of them returning 200 (hard rule 5).
        if (!$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($url)) . ' — the search index is pollable '
                    . 'but the listing pages are not, so this source cannot be hydrated',
            );
        }

        if ($this->definition->rateLimitMs > 0) {
            usleep($this->definition->rateLimitMs * 1000);
        }

        try {
            $body = $this->get($url, []);
            $flat = $this->detailFields($body, $detailMap);
        } catch (SourceError $e) {
            // Redacted here rather than in the store, because a detail-fetch failure carries the
            // url it failed on and this is the last place that url is a live string.
            $this->store->recordDetailFailure(
                $this->name(),
                $listing->externalId,
                Redact::text($e->getMessage()),
                $atIso,
            );

            return $listing;
        }

        // The RAW extracted strings go to the store, and the MAPPER runs on the way out — on this
        // path and on the cache-hit path alike, from one place. Storing mapped values instead would
        // freeze today's ListingMapper into every row, and a later fix to how `RDC` is read would
        // never reach a listing captured before it.
        $this->store->recordDetail(
            $this->name(),
            $listing->externalId,
            $url,
            $flat,
            $atIso,
            $detailMap->fingerprint(),
        );

        return $this->mergeDetail($listing, $detailMap, $flat);
    }

    /**
     * Raw extracted strings + the card = the hydrated listing.
     *
     * The single funnel, used by the fetch path and the cache-hit path, so a merge cannot behave
     * one way on the pass that fetched a page and another on every pass after it — which is the
     * shape of bug the cache exists to prevent, reintroduced inside the cache.
     *
     * Through the MAPPER, not assigned raw: a detail page's rent, floor and surface are the same
     * prose the card's are, and hard rule 9 lives in `ListingMapper`/`Payload`. A second,
     * hand-rolled conversion here would be a second place for `RDC` to stop meaning zero.
     *
     * @param array<string,string> $flat
     */
    private function mergeDetail(RawListing $listing, FieldMap $detailMap, array $flat): RawListing
    {
        $mapper = new ListingMapper($this->flatMapped($detailMap, detailMode: true));
        $flat['ref'] = $listing->externalId;

        return $listing->mergedWith($mapper->map($flat));
    }

    /**
     * The detail map, resolved against the detail document.
     *
     * Deliberately NOT `extract()`: that one adds `_text` — every word of the element it is given —
     * which on a whole page is the furniture that conflicts a correct verdict into UNKNOWN. Here
     * only the configured selectors are read, so a detail map that addresses the listing's own
     * block contributes the listing's own words and nothing else.
     *
     * @return array<string, string>
     *
     * @throws SourceError
     */
    private function detailFields(string $html, FieldMap $detailMap): array
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable $e) {
            throw new SourceError($this->name(), 'detail page could not be parsed as HTML: ' . $e->getMessage(), $e);
        }

        $root = $document->documentElement;
        if ($root === null) {
            throw new SourceError($this->name(), 'detail page parsed to an empty document');
        }

        $out = [];
        foreach (self::FIELDS as $field) {
            /** @var list<string> $entries */
            $entries = $detailMap->{$field};
            foreach ($entries as $entry) {
                $value = Selector::parse($entry)->resolve($root);
                if ($value !== null) {
                    $out[$field] = $value;
                    break;
                }
            }
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
    private function flatMapped(?FieldMap $source = null, bool $detailMode = false): SourceDefinition
    {
        $map = $source ?? $this->definition->map;
        $literal = [];
        foreach (self::FIELDS as $field) {
            $literal[$field] = $map->{$field} === [] ? [] : [$field];
        }

        if ($detailMode) {
            // The mapper refuses a listing with no `ref`, and it is right to: without a stable id
            // every run re-notifies everything. A detail map has no ref of its own and must not have
            // one — identity belongs to the card — so the caller supplies the card's id under this
            // key and the guarantee stays intact rather than being switched off for detail pages.
            $literal['ref'] = ['ref'];
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
                description: !$detailMode
                    ? [...$literal['description'], '_text']
                    // A DETAIL map gets no `_text` fallback, and that is the point of the flag: on a
                    // detail page `_text` would be the whole page, which is the furniture that
                    // conflicts a correct verdict into UNKNOWN. A detail page with no description
                    // selector match contributes no description, and the card's own stands.
                    : $literal['description'],
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
