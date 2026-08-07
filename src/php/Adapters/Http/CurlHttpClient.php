<?php

declare(strict_types=1);

namespace RentWatch\Adapters\Http;

/**
 * The real transport. cURL, because it is present here and reports failures distinguishably.
 *
 * `CLAUDE.md` hard rule 5 is enforced HERE rather than left to each adapter, so no adapter can
 * accidentally be the polite exception:
 *
 * - **Identify honestly.** One User-Agent, naming the tool and its purpose. No browser
 *   impersonation, no rotation — those are the fingerprint-spoofing the rule forbids outright.
 * - **No cookie jar, no proxy support, no redirect to a different host.** Each of those is a step
 *   toward the anti-bot arms race this project refuses to enter. When a site blocks us, the answer
 *   is the email-alert route, never a better disguise.
 * - **Redirects are followed at most three times**, and only within the same host. An open redirect
 *   would otherwise let a compromised source point the poller anywhere.
 */
final readonly class CurlHttpClient implements HttpClient
{
    public const string USER_AGENT = 'rent-watch/1.0 (+self-hosted personal listing watcher; contact via repository)';

    private const int MAX_REDIRECTS = 3;

    /** 8 MB. A listing page that large is a misconfiguration, and reading it unbounded is a DoS on ourselves. */
    private const int MAX_BODY_BYTES = 8 * 1024 * 1024;

    public function send(HttpRequest $request): HttpResponse
    {
        if (!extension_loaded('curl')) {
            throw new HttpError('ext-curl is not loaded, so no network source can run');
        }

        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new HttpError('could not initialise the HTTP client for ' . $request->url);
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $request->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            // Followed by US, not by cURL, so the same-host rule below is actually applied.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($request->method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            // A TRANSPORT failure. Distinct from a non-2xx answer on purpose: a 403 is a source
            // telling us it blocks plain clients, and a timeout is the network. Only one of those
            // means the source needs a different route.
            throw new HttpError('request to ' . $request->url . ' failed: ' . $error);
        }

        $body = (string) $body;
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpError(sprintf(
                'response from %s exceeded %d bytes — refusing to parse it',
                $request->url,
                self::MAX_BODY_BYTES,
            ));
        }

        return new HttpResponse($status, $body, $responseHeaders);
    }

    /**
     * Follow a redirect chain manually, refusing to leave the original host.
     *
     * cURL's own `FOLLOWLOCATION` would happily cross hosts, and a source that redirects elsewhere
     * is either compromised or not the source we verified — either way, following it silently means
     * polling something nobody approved, with our honest User-Agent attached.
     */
    public function sendFollowing(HttpRequest $request): HttpResponse
    {
        $current = $request;
        $origin = parse_url($request->url, PHP_URL_HOST);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $response = $this->send($current);

            if ($response->status < 300 || $response->status >= 400) {
                return $response;
            }

            $location = $response->header('location');
            if ($location === null || $location === '') {
                return $response;
            }

            $target = self::resolve($current->url, $location);
            if (parse_url($target, PHP_URL_HOST) !== $origin) {
                throw new HttpError(sprintf(
                    'refusing a cross-host redirect from %s to %s',
                    (string) $origin,
                    (string) parse_url($target, PHP_URL_HOST),
                ));
            }

            $current = new HttpRequest($target, 'GET', $request->headers, null, $request->timeoutSeconds);
        }

        throw new HttpError('too many redirects from ' . $request->url);
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $prefix = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return str_starts_with($location, '/')
            ? $prefix . $location
            : $prefix . '/' . ltrim($location, '/');
    }
}
