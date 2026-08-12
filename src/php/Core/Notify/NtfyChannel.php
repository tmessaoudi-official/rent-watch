<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/**
 * Push notification over ntfy — the channel for listings that go within hours.
 *
 * **THE TOPIC IS A SECRET** (`docs/OPEN-QUESTIONS.md` Q9: anyone who knows it can read every
 * notification), and it is a secret of an awkward shape: it travels as a URL PATH SEGMENT, so there
 * is no `topic=` for a name-based masker to anchor on. `Redact` covers the default `ntfy.*` host by
 * pattern, and a review demonstrated that leaking the moment the server is self-hosted under any
 * other name — which `.env.example` exists to permit. So every error this class raises passes the
 * topic to {@see ChannelError} as a literal, which is masked before the pattern rules even run.
 *
 * Transport is cURL because it is present here and because it fails with a distinguishable error
 * rather than a warning. `check()` says so plainly if it is not, instead of discovering it at the
 * moment a match finally arrives.
 */
final readonly class NtfyChannel implements Channel
{
    public function __construct(
        private string $topic,
        private string $server = 'https://ntfy.sh',
        private int $timeoutSeconds = 10,
    ) {}

    public function name(): string
    {
        return 'ntfy';
    }

    public function check(): ?string
    {
        if (trim($this->topic) === '') {
            return 'NTFY_TOPIC is not set. Treat the topic as a secret — anyone who knows it can read every notification';
        }
        if (trim($this->server) === '' || preg_match('~^https?://~i', $this->server) !== 1) {
            return 'NTFY_SERVER must be an http(s) URL, got ' . var_export($this->server, true);
        }
        if (!extension_loaded('curl')) {
            return 'ext-curl is not loaded, so the ntfy channel cannot send';
        }

        return null;
    }

    public function send(Notification $n): void
    {
        $problem = $this->check();
        if ($problem !== null) {
            throw new ChannelError($this->name(), $problem, null, [$this->topic]);
        }

        $body = $n->title . "\n" . implode("\n", $n->reasons);
        if ($n->url !== null) {
            $body .= "\n" . $n->url;
        }

        $headers = [
            'Title: ' . self::headerSafe($n->title),
            'Priority: ' . $n->priority->ntfyLevel(),
            'Tags: ' . strtolower($n->kind->value),
            'Content-Type: text/plain; charset=utf-8',
        ];
        if ($n->url !== null) {
            // headerSafe, exactly as the Title above — the url is landlord-controlled (listing
            // payload → ListingMapper → Notification), and a CRLF in it would start a second,
            // attacker-chosen header on the POST to the user's own ntfy server. ntfy reads headers
            // as controls (Attach, Actions, Email…), so an unsanitised Click url is a header-
            // injection / SSRF vector. This channel calls libcurl directly, so CurlHttpClient's
            // funnel guard never sees it — the discipline has to be repeated here.
            $headers[] = 'Click: ' . self::headerSafe($n->url);
        }

        $handle = curl_init(rtrim($this->server, '/') . '/' . rawurlencode($this->topic));
        if ($handle === false) {
            throw new ChannelError($this->name(), 'could not initialise the HTTP client', null, [$this->topic]);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            // Identify honestly (CLAUDE.md hard rule 5). This is our own notification server, so it
            // is not a politeness question here — it is consistency with how the project behaves
            // everywhere else, so no code path learns a different habit.
            CURLOPT_USERAGENT => 'rent-watch (self-hosted listing watcher)',
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // No curl_close(): deprecated in PHP 8.5, a no-op since 8.0 — the handle is freed when it
        // goes out of scope. Found in CurlHttpClient by the first wire test; carried here because
        // a deprecated call removed from one member of a class of things must leave none behind.

        if ($response === false || $error !== '') {
            throw new ChannelError($this->name(), 'POST failed: ' . $error, null, [$this->topic]);
        }
        if ($status < 200 || $status >= 300) {
            // The status code is the diagnostic and must survive masking — a 401 and a 404 mean very
            // different things (wrong token vs wrong topic) and both are actionable.
            throw new ChannelError($this->name(), 'server answered HTTP ' . $status, null, [$this->topic]);
        }
    }

    /**
     * A header value may not contain a newline.
     *
     * Not cosmetic: an unfiltered newline in a header is HTTP header injection, and the title is
     * built from listing text that a landlord controls.
     */
    private static function headerSafe(string $value): string
    {
        return trim(preg_replace('~[\r\n]+~', ' ', $value) ?? '');
    }
}
