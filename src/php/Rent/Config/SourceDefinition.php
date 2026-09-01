<?php

declare(strict_types=1);

namespace Scout\Rent\Config;

use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;

/**
 * One source block from `config/rent/sources.json`.
 *
 * The loader enforces four rules here that no amount of later care recovers from:
 *
 * 1. **`mixed_tenure` is required.** {@see SourceProfile} already defaults it to `true`, and this
 *    refuses the block that omits it anyway. Two independent guards on the one boolean that arms
 *    §1's fail-closed rule — because the code default protects a source constructed in a test, and
 *    this protects a source someone adds to config in a hurry.
 * 2. **`default_tenure` may not name an excluded tenure.** A source whose default is PLAI is a
 *    source that is wholly out of scope; declaring it is either a mistake or an attempt to route
 *    social stock through the hint channel, and both deserve a refusal rather than a silent
 *    classification the exclusion then has to catch.
 * 3. **An enabled source may not carry an unverified URL.** `REMPLACER` is the placeholder
 *    (`CLAUDE.md` hard rule 1); `enabled: true` next to one is refused at load, not at fetch.
 * 4. **Scraping a private portal is opt-in and refuses to run without the flag** (hard rule 4). It
 *    is not enough for it to be `enabled: false` by default — the refusal has to be in the code
 *    path, so flipping one boolean is not sufficient to start scraping.
 */
final readonly class SourceDefinition
{
    /** Adapter kinds the loader accepts. `browser` is recognised and REFUSED — see the loader. */
    public const array TYPES = ['json', 'html', 'email_alert', 'fixture'];

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $params
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $family,
        public string $type,
        public bool $mixedTenure,
        public ?Tenure $defaultTenure = null,
        public ?string $url = null,
        /**
         * Absolute prefix for resolving a RELATIVE href out of a payload. Restored 2026-08-07 after
         * a review found it dropped in the YAML→JSON translation: `prototype/scout.py:210` resolves
         * `url` against it, four prototype source blocks set it, and under the new loader's
         * "unknown keys are a hard error" rule a source publishing relative links had no documented
         * key AND a documented hard failure.
         */
        public ?string $baseUrl = null,
        public string $method = 'GET',
        public array $headers = [],
        public array $params = [],
        /** JSON request body for a `POST` source — CDC Habitat's search is one. */
        public ?string $body = null,
        public ?string $itemsPath = null,
        /**
         * CSS selector for an element whose TEXT is the JSON payload, for `type: json`.
         *
         * For a page that serves its results as JSON embedded in HTML rather than as an API
         * response. Logirep/Polylogis is Drupal and ships 113 records inside
         * `<script type="application/json" data-drupal-selector="drupal-settings-json">`, while its
         * visible markup carries two `€` — the search form. Neither adapter could read that: `html`
         * maps selectors over repeated card elements and there is only one script tag, and `json`
         * parses the response body, which is HTML.
         *
         * When set, the element's text is extracted and everything downstream — `items_path`, the
         * field map, `ListingMapper`, and so hard rule 9 — is the ordinary JSON path, unchanged. The
         * loader refuses this key on any type but `json`, for the same reason it refuses
         * `detail_map` outside `html`: a key nobody reads is worse than absent, because it looks
         * like configuration that is switched on.
         */
        public ?string $embeddedJsonSelector = null,
        /** CSS selector picking one listing element, for `type: html`. `items_path` is its JSON twin. */
        public ?string $itemSelector = null,
        /**
         * Query parameter that selects a results page, for `type: html`. `null` means the source is
         * read as a single page.
         *
         * Not cosmetic, and not optional once a search is loosened: In'li returns 24 cards per page
         * and answered `92 logements` to an ordinary set of filters [verified 2026-08-19], so a
         * page-one-only read would have discarded 68 listings on every pass — silently, since a
         * short result set and a quiet market are the same observation.
         */
        public ?string $pageParam = null,
        /**
         * Pagination in the PATH rather than the query string: a template holding `{page}`,
         * appended to `url` for every page after the first (`/page-{page}/`).
         *
         * Mutually exclusive with {@see $pageParam}, and not a stylistic alternative to it. CDC
         * Habitat's `robots.txt` disallows every search QUERY PARAMETER by name — `?nbPiece`,
         * `?nbLoyerMin`, `?cdTypeBien` and four more — while its own sitemap advertises
         * `/recherche/location/<region>/page-2/`. Appending `?page=2` there would query a URL space
         * the site asked robots to stay out of; walking the published path does not. So for that
         * source this field is not a convenience, it is the difference between a pollable source
         * and one that must be refused (hard rule 5).
         *
         * Because the PATH changes per page, robots is re-checked for every page rather than once
         * for the index — a site may publish an index it welcomes and paginated forms it does not.
         */
        public ?string $pagePath = null,
        /**
         * CSS selector for the page's own declared result count (In'li: `92 logements`).
         *
         * This is what makes pagination CHECKABLE rather than hopeful. Walking pages until one
         * comes back empty is a termination rule, not a correctness proof — a pagination link that
         * silently 404s ends the walk exactly like a genuine last page. Comparing the total the
         * site itself states against the number actually collected is the only assertion that can
         * tell those apart.
         */
        public ?string $totalSelector = null,
        /**
         * Upper bound on pages walked in one fetch. Reaching it is an error, never a quiet stop.
         *
         * A bound rather than a target: at 24 per page this is 480 listings, so hitting it means
         * pagination is looping or the filters are far wider than intended — both worth a loud
         * failure. Without it a malformed `page` param that always returns page one is an infinite
         * loop against somebody else's server, which is the one bug in this file that could get an
         * IP banned (hard rule 5).
         */
        public int $maxPages = 20,
        public FieldMap $map = new FieldMap(ref: ['id']),
        /**
         * A SECOND map, resolved against a listing's own detail page — for the sources that do not
         * put everything on the card.
         *
         * Cityloger is the case it was built for: its search cards carry rent, rooms, surface,
         * commune and floor, and no tenure whatsoever. On a mixed-tenure source that means every
         * listing resolves UNKNOWN and goes to the à-vérifier digest — correct under §1, and
         * useless — while the detail page has both an explicit `Financement` code and prose saying
         * "Logement intermédiaire" in words.
         *
         * Two constraints, and neither is stylistic:
         *
         * - **It costs a request per listing**, so it runs only for listings that pass a gate
         *   supplied by the caller. `HtmlSource` REFUSES a detail map with no gate rather than
         *   defaulting either way — see the constructor.
         * - **Its selectors must address the listing's own content, never the whole page.** Measured
         *   2026-08-20 on the real Antony payload: the scoped `.description` classifies LLI at 0.90;
         *   the same listing fed its entire detail page classifies UNKNOWN at 0.00, because generic
         *   furniture ("Commission d'attribution", "demande de logement social") sits on social and
         *   intermediate pages alike and conflicts the verdict away.
         */
        public ?FieldMap $detailMap = null,
        /**
         * How many detail pages one pass may fetch for this source.
         *
         * A cap exists because the cold start is the only expensive moment: every listing is novel
         * at once, and In'li's 174 at Q37's sixty seconds per host is a three-hour pass — a crawl
         * under hard rule 5. Steady state is near zero, because a hydration is stored and read back
         * rather than re-fetched.
         *
         * The DEFAULT is deliberate and the ZERO is deliberately different from it. Omitting the
         * key gives a slow cold start, which is benign and self-correcting. Writing `0` means
         * hydrate nothing, ever, while the source's health stays green and its digest stays
         * plausible — the silent shape — so the loader REFUSES it, exactly as an unusable
         * `RENT_HEARTBEAT_HOURS` is a loud startup refusal rather than a quiet fallback.
         *
         * This REPLACES the older invariant that a `detail_map` without a gate refuses. Novelty is
         * the gate now, supplied by the run rather than configured, so there is no longer a gate to
         * be missing — but the thing that invariant protected, a detail map that quietly does
         * nothing, still needs a refusal and this is it.
         */
        public int $detailBudgetPerPass = 20,
        public bool $legalRisk = false,
        public ?string $fixture = null,
        public int $rateLimitMs = 2000,
        /**
         * This source's own feed-silence threshold in days, or `null` for the global
         * `RENT_FEED_SILENT_DAYS`. One number cannot serve a portal firing thirty alerts a day and one
         * firing weekly: under the global three days the first is noticed ~90 alerts late, and a
         * global one day would alarm on the second every week. Only an `email_alert` source can
         * carry it — nothing else reports a feed date (refused at load otherwise).
         */
        public ?int $feedSilentDays = null,
        /**
         * Does this source's payload carry NO listing prose at all?
         *
         * A declaration about what the payload MEANS, in the same class as {@see $mixedTenure} and
         * `map.charges_included` — never a filter and never a score component. When it is true, the
         * two exclusion lists cannot fire: `exclude_patterns` scans title and description,
         * `exclude_title_patterns` scans the title, and on such a source both are boilerplate.
         *
         * PAP is the case it was written for. Its alert is header + the subscriber's own search
         * criteria + four structured facts + a link + a legal footer; `description` is the literal
         * string `PAP.fr  De Particulier à Particulier ____` on every one of 57 stored rows, and
         * `title` is `Location appartement` or `Location maison` on every one. So a colocation
         * advertised with the whole flat's room count and surface passes every numeric filter and
         * there is no text in which to catch it — measured, and the two ways out are both closed:
         * the detail page is behind a bot challenge (hard rule 5) and rent-per-room has no gap to
         * threshold on. See `docs/plans/pap-detail-hydration.plan.md`.
         *
         * What it buys is honesty rather than filtering: {@see \Scout\Rent\Core\CriteriaEngine}
         * says so in the notification's own reasons, beside the unverifiable-ceiling line it
         * already emits for an HC-only rent. A check that could not be made is reported as one
         * rather than passing silently — which is the same discipline, one layer up, as hard rule 9
         * refusing to read an unknown as a zero.
         *
         * Refused at load on a source that maps a description: a declaration contradicted by the
         * configuration beside it is worse than no declaration, because it reads as considered.
         */
        public bool $proseAbsent = false,
    ) {}

    /**
     * The same source with its request throttle removed — for `scout replay --file`, where the
     * "requests" are answered from a frozen file and there is no host to protect. The adapter's
     * `rate_limit_ms` sleeps between fetches whatever the client answers, so a replay of a
     * `detail_map` source spent 2 s × 20 simulated detail fetches sleeping (measured: 43 s for one
     * dump). Everything else — the field map, the gate, the budget — stays byte-identical, which is
     * the point of replaying through the real adapter.
     */
    public function unthrottled(): self
    {
        return clone($this, ['rateLimitMs' => 0]);
    }

    /** The slice the tenure classifier needs. */
    public function profile(): SourceProfile
    {
        return new SourceProfile(
            name: $this->name,
            family: $this->family === 'private' ? 'private' : 'institutional',
            defaultTenure: $this->defaultTenure,
            mixedTenure: $this->mixedTenure,
        );
    }

    /**
     * Does running this source require the developer to have passed `--allow-scraping`?
     *
     * Direct scraping of a **private portal** is the opt-in case: the private portals are the ones
     * whose terms and anti-bot posture make polling the wrong route, and email-alert ingestion is
     * the sanctioned path (hard rule 4). Polling an institutional landlord's own public search
     * endpoint is not in that class and is not gated.
     */
    public function requiresScrapingOptIn(): bool
    {
        return $this->family === 'private' && in_array($this->type, ['json', 'html'], true);
    }
}
