<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

/**
 * One answer. A non-2xx status is DATA here, not an exception.
 *
 * The distinction matters for source health: a 403 is a source telling us something (it blocks a
 * plain client — `docs/SOURCES.md` records five portals that do), and a timeout is a transport
 * failure. Collapsing them would make "this site refuses us" and "the network hiccuped" the same
 * event, and only one of them means the source needs a different route.
 */
final readonly class HttpResponse
{
    /** @param array<string,string> $headers lower-cased names */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
