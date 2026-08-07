<?php

declare(strict_types=1);

namespace RentWatch\Adapters;

use RentWatch\Adapters\Http\CurlHttpClient;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * Polls a JSON endpoint and maps it with the source's field map.
 *
 * **THIS ADAPTER IS COMPLETE. WHAT IS MISSING IS A VERIFIED URL**, and those are different things —
 * a distinction I got wrong for a while and it is worth writing down. `CLAUDE.md` hard rule 1
 * forbids writing an ENDPOINT from memory; it says nothing about the transport. So this class exists,
 * is fully tested against a fake client, and shares `ListingMapper` and `Payload` with the fixture
 * adapter — meaning a fixture test exercises the same extraction code a real poll will. Every source
 * in `config/sources.json` stays `enabled: false` with a `REMPLACER` URL until a DevTools capture
 * replaces it, and the config loader refuses the combination of `enabled: true` and a placeholder.
 *
 * Three guarantees this class owes the rest of the system:
 *
 * 1. **It throws; it never returns `[]` to signal failure** (hard rule 3). A transport error, a
 *    non-2xx status, malformed JSON and an absent `items_path` are four distinct failures and all
 *    four are loud.
 * 2. **It checks `robots.txt` before fetching** (hard rule 5), and fails CLOSED when it cannot.
 * 3. **It never disguises itself.** One honest User-Agent, no cookie jar, no proxy, no cross-host
 *    redirect. When a site blocks us the answer is the email-alert route, never a better disguise.
 */
final readonly class HttpJsonSource implements Source
{
    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private HttpClient $client = new CurlHttpClient(),
        /**
         * Injected rather than fetched inside, so a test can state the site's posture without a
         * network round trip — and so the run loop can cache one `robots.txt` per host per pass
         * instead of re-fetching it for every source on that host.
         */
        private ?Robots $robots = null,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
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

    public function fetch(): array
    {
        $url = $this->definition->url;

        if ($url === null || $url === '') {
            throw new SourceError($this->name(), 'no url configured');
        }

        // Belt and braces with the config loader, which already refuses this combination. A second
        // check here costs one comparison and covers a source constructed in code rather than
        // loaded from the file — which is exactly how a test or a future `--dry-run` would build one.
        if (str_contains($url, 'REMPLACER')) {
            throw new SourceError(
                $this->name(),
                'the url is still the REMPLACER placeholder. CLAUDE.md hard rule 1: verify the '
                    . 'endpoint against the live site before enabling this source',
            );
        }

        if ($this->robots !== null && !$this->robots->allows(Robots::pathOf($url))) {
            // Refused, and NEVER worked around. Hard rule 5 has no exception, and the documented
            // alternative for a site that does not want polling is the email-alert route.
            throw new SourceError(
                $this->name(),
                'robots.txt disallows ' . Robots::pathOf($url) . ' — this source must not be polled. '
                    . 'Use the email-alert route instead',
            );
        }

        $request = (new HttpRequest(
            url: $url,
            method: $this->definition->method,
            headers: $this->definition->headers,
            body: $this->definition->body,
        ))->withQuery($this->definition->params);

        try {
            $response = $this->client->send($request);
        } catch (HttpError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        if (!$response->isSuccess()) {
            // A non-2xx is a recorded FAILURE, not an empty result. `docs/SOURCES.md` records five
            // portals that answer 403 to a plain client; that fact has to reach the run log, because
            // it is what says "this source needs the email route" rather than "the market is quiet".
            throw new SourceError(
                $this->name(),
                'HTTP ' . $response->status . ' from ' . $url
                    . ($response->status === 403 ? ' — this source blocks plain clients; use the email-alert route' : ''),
            );
        }

        try {
            $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SourceError($this->name(), 'response was not valid JSON: ' . $e->getMessage(), $e);
        }

        return $this->extract($decoded);
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
    private function extract(mixed $decoded): array
    {
        $itemsPath = $this->definition->itemsPath;
        $items = $itemsPath === null || $itemsPath === '' ? $decoded : Payload::at($decoded, $itemsPath);

        if ($items === null) {
            // The single commonest way a working adapter stops working: still 200, still valid JSON,
            // but the results moved. Returning `[]` here would read as a quiet market forever.
            throw new SourceError(
                $this->name(),
                'items_path `' . (string) $itemsPath . '` is absent from the response — '
                    . 'the payload shape changed, or the path is wrong',
            );
        }

        if (!is_array($items) || !array_is_list($items)) {
            throw new SourceError(
                $this->name(),
                'items_path `' . (string) $itemsPath . '` did not yield a list of items',
            );
        }

        $mapper = new ListingMapper($this->definition);

        $out = [];
        foreach ($items as $item) {
            $out[] = $mapper->map($item);
        }

        return $out;
    }
}
