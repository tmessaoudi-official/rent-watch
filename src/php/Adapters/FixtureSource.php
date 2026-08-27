<?php

declare(strict_types=1);

namespace Scout\Adapters;

use Scout\Config\SourceDefinition;
use Scout\Core\SourceHealth;
use Scout\Core\SourceProfile;
use Scout\Core\Tenure;
use Scout\Store\Store;

/**
 * A source backed by a frozen payload file. No network, no credentials.
 *
 * This is not a test double bolted on beside the real thing — it is the adapter `scout replay` uses
 * (`spec/PROJECT_BRIEF.md` §10) and the one that makes `spec` §11's *"parser tests run offline, no
 * network in CI"* possible. It shares `ListingMapper` and `Payload` with every network adapter, so a
 * fixture test exercises the same extraction code the real poll does; a fixture source that parsed
 * payloads its own way would prove nothing about production.
 *
 * It is also why `scout run --once` can be demonstrated end to end today, with every real endpoint
 * still unverified under hard rule 1.
 */
final readonly class FixtureSource implements Source
{
    public function __construct(
        private readonly SourceDefinition $definition,
        private readonly Store $store,
        /** Repo root, so a config path stays relative and portable. */
        private readonly string $rootDir,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    /** A frozen payload read off the local disk. No host, so Q37 pacing does not apply. */
    public function host(): ?string
    {
        return null;
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
        $relative = $this->definition->fixture;
        if ($relative === null) {
            throw new SourceError($this->name(), 'no fixture file configured');
        }

        // Refused rather than resolved. A `..` in a config-supplied path reaching the filesystem is
        // a traversal whether or not this particular file is attacker-controlled, and there is no
        // legitimate fixture outside the repo.
        if (str_contains($relative, '..')) {
            throw new SourceError($this->name(), 'fixture path may not contain `..`: ' . $relative);
        }

        $path = rtrim($this->rootDir, '/') . '/' . ltrim($relative, '/');
        if (!is_file($path)) {
            throw new SourceError($this->name(), 'fixture file not found: ' . $relative);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new SourceError($this->name(), 'fixture file could not be read: ' . $relative);
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SourceError($this->name(), 'fixture is not valid JSON: ' . $e->getMessage(), $e);
        }

        return $this->extract($decoded);
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->store->health($this->name(), $nowIso);
    }

    /**
     * @return list<\Scout\Core\RawListing>
     *
     * @throws SourceError
     */
    private function extract(mixed $decoded): array
    {
        $itemsPath = $this->definition->itemsPath;
        $items = $itemsPath === null ? $decoded : Payload::at($decoded, $itemsPath);

        if ($items === null) {
            // The single commonest way a working adapter stops working: the payload is still valid
            // JSON and still 200, but the results moved. Silently yielding zero items here is the
            // exact shape hard rule 3 forbids — it would read as a quiet market forever.
            throw new SourceError(
                $this->name(),
                'items_path `' . (string) $itemsPath . '` is absent from the payload — '
                    . 'the response shape changed, or the path is wrong',
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
