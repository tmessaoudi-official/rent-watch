<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

/** One outbound request. Immutable, so a retry cannot quietly send something different. */
final readonly class HttpRequest
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public array $headers = [],
        public ?string $body = null,
        public int $timeoutSeconds = 20,
    ) {}

    /** @param array<string,string> $params */
    public function withQuery(array $params): self
    {
        if ($params === []) {
            return $this;
        }

        $separator = str_contains($this->url, '?') ? '&' : '?';

        return new self(
            $this->url . $separator . http_build_query($params),
            $this->method,
            $this->headers,
            $this->body,
            $this->timeoutSeconds,
        );
    }
}
