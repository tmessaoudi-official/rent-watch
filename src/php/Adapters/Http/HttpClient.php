<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

/**
 * The HTTP surface every network adapter uses, and the ONE place a test can replace.
 *
 * An interface rather than a curl call inline, for a reason this project keeps proving: without it,
 * a network adapter can only be tested by making a request, and `spec/PROJECT_BRIEF.md` §11 forbids
 * network in CI. With it, `HttpJsonSource` is exercised against exactly the same field-map and
 * classifier code the real poll uses, and the only untested strip is the socket itself.
 *
 * This is also what separates the two things `CLAUDE.md` hard rule 1 governs. The rule forbids
 * writing an ENDPOINT from memory — it says nothing about the transport. So the adapter is buildable
 * today; only the URL in `config/sources.json` waits on a real capture.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     *
     * @throws HttpError on a transport failure — a timeout, a DNS failure, a TLS error. NOT on a
     *                   non-2xx status, which is a legitimate answer the caller must be able to see.
     */
    public function send(HttpRequest $request): HttpResponse;
}
