<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * One block of `config/car/sources.json`. Two adapter types in this slice: `email_alert` (a
 * portal's saved-search mail, card readers configured as positional patterns) and
 * `sitemap_jsonld` (a site whose sitemap indexes lot pages carrying a schema.org `Vehicle` block).
 */
final readonly class VehicleSourceDefinition
{
    /**
     * @param string               $family        portal | dealer | auction — a displayed fact and, later, a score component
     * @param array<string,string> $params        the adapter's string parameters (patterns, from, link_host, …)
     * @param array<string,string> $map           `sitemap_jsonld`: listing field => dotted path into the JSON-LD block
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $family,
        public string $type,
        public array $params = [],
        public ?string $url = null,
        public ?string $itemUrlPattern = null,
        public array $map = [],
        public int $lotBudgetPerPass = 50,
        public int $rateLimitMs = 2000,
        public ?int $feedSilentDays = null,
        public ?string $fixture = null,
    ) {}

    public function param(string $key): ?string
    {
        $v = $this->params[$key] ?? null;

        return $v === null || trim($v) === '' ? null : $v;
    }
}
