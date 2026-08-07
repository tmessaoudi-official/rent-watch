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
