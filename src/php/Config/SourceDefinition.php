<?php

declare(strict_types=1);

namespace RentWatch\Config;

use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;

/**
 * One source block from `config/sources.json`.
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
        public bool $legalRisk = false,
        public ?string $fixture = null,
        public int $rateLimitMs = 2000,
    ) {}

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
