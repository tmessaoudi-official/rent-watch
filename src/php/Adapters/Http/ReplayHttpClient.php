<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

/**
 * The client behind `scout replay <source> --file=<payload>`: a network source's own adapter and
 * field map, fed a frozen page, with no request made.
 *
 * Three answers, and each is a rule rather than a convenience:
 *
 * - **`/robots.txt` is 404.** RFC 9309 §2.3.1.3 — an absent file is knowledge, and this repo's
 *   resolver reads a 404 as "allow". Serving the payload there instead would hand an HTML page to
 *   the robots parser, which fails CLOSED on markup (the 2026-08-25 SPA rule), and the replay
 *   would refuse itself.
 * - **The source's own search URL — and its paginated variants — get the payload.** Matched on a
 *   PREFIX, because `page_param` appends `?page=2`, `page_path` appends `/page-2`, and a `{page}`
 *   template substitutes mid-path; the prefix is the template up to the placeholder.
 * - **Everything else is 404**, and that is the detail-hydration answer on purpose. A
 *   `detail_map` source would otherwise be handed the SEARCH page as every listing's detail page
 *   and print a title selected from the wrong document — plausible, and wrong. A 404 is recorded
 *   as an ordinary per-listing fetch failure, in whatever store the replay runs against.
 *
 * Nothing here consults the network, so a replay is offline by construction — which is also why
 * the test suite (`SCOUT_OFFLINE=1`) can run it against a real source block.
 */
final readonly class ReplayHttpClient implements HttpClient
{
    private string $prefix;

    public function __construct(
        string $searchUrl,
        private string $body,
        private string $contentType,
    ) {
        $cut = strpos($searchUrl, '{page}');
        $this->prefix = $cut === false ? $searchUrl : substr($searchUrl, 0, $cut);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $path = (string) (parse_url($request->url, PHP_URL_PATH) ?? '');

        if (str_ends_with($path, '/robots.txt')) {
            return new HttpResponse(404, '', ['content-type' => 'text/plain']);
        }

        if (str_starts_with($request->url, $this->prefix)) {
            return new HttpResponse(200, $this->body, ['content-type' => $this->contentType]);
        }

        return new HttpResponse(404, '', ['content-type' => 'text/plain']);
    }
}
