<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

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
    /** @param string $topicKey the environment key the caller read the topic from — the domain's own, so a refusal names the line to fix */
    public function __construct(
        private string $topic,
        private string $server = 'https://ntfy.sh',
        private int $timeoutSeconds = 10,
        private string $topicKey = 'NTFY_TOPIC',
    ) {}

    public function name(): string
    {
        return 'ntfy';
    }

    /** YES — a push to a phone is the whole reason this channel exists. */
    public function reachesRecipient(): bool
    {
        return true;
    }

    /**
     * The SERVER, never the topic.
     *
     * `.env.example` says to treat the topic as a secret: anyone who knows it can read every
     * notification. `doctor` prints this, and a `doctor` transcript is exactly the thing pasted
     * into a report.
     */
    public function describe(): string
    {
        return 'push ntfy via ' . $this->server;
    }

    public function check(): ?string
    {
        if (trim($this->topic) === '') {
            return 'the ntfy topic is not set (' . $this->topicKey . '). Treat the topic as a secret — anyone who knows it can read every notification';
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

        $url = rtrim($this->server, '/') . '/' . rawurlencode($this->topic);

        // THE OFFLINE TRIPWIRE, and this channel needs its own call because it is not behind the
        // HTTP funnel: it drives libcurl directly (see the CRLF note above, which repeats the same
        // discipline for the same reason). `tests/bootstrap.php` presents `SCOUT_OFFLINE=1` as
        // the backstop for anything not given a fake, and until 2026-08-24 that backstop did not
        // cover the one component whose default server is a third party and whose topic is a
        // documented secret. A review panel showed the flag set and this channel still resolving
        // and dialling a non-loopback host.
        //
        // Loopback stays allowed — `ScoutDigestTest` points it at a closed port on 127.0.0.1 to
        // exercise a delivery failure without leaving the machine, which is exactly the use the
        // exemption exists for.
        // THE REFUSAL NAMES THE SERVER, NEVER THE URL — because the url ends in the topic, and the
        // topic is the secret. The first version of this passed the whole url and relied on
        // `Redact` masking the topic literal, which fails twice over: `Redact` masks by
        // `str_replace`, so `rawurlencode` touching any character breaks the match
        // (`rw secret topic` leaked verbatim), and it deliberately ignores literals under four
        // characters, since masking those would eat ordinary words — so a short topic leaked too.
        // Both measured by a review panel on 2026-08-24.
        //
        // Not putting the secret in the string is the fix; masking it afterwards is a race against
        // every transformation the string may undergo. The server is what the operator needs to
        // see, and it is not a credential. The topic is still passed as a literal, as a backstop.
        $refusal = \Scout\Core\Offline::refusalForHost($this->server, 'the ntfy server');
        if ($refusal !== null) {
            throw new ChannelError($this->name(), $refusal, null, [$this->topic]);
        }

        $handle = curl_init($url);
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
            CURLOPT_USERAGENT => 'scout (self-hosted listing watcher)',
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
