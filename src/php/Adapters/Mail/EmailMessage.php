<?php

declare(strict_types=1);

namespace RentWatch\Adapters\Mail;

/**
 * A parsed RFC-822 message: headers, a text body, and the links it contains.
 *
 * Hand-rolled for the same reason {@see ImapMailbox} is — no `ext-imap`, no Composer. The subset is
 * the one a listing alert actually uses: headers with folded continuation lines, MIME multipart,
 * `quoted-printable` and `base64` transfer encodings, and a charset conversion to UTF-8.
 *
 * **The charset conversion is the part that matters most and looks least important.** French alert
 * emails are routinely `ISO-8859-1`, and a `é` read as Latin-1 bytes and stored as UTF-8 becomes
 * mojibake — which then fails to match `Text::fold()`, which then means the tenure classifier never
 * sees `logement intermédiaire`. That is a §1 hole arriving through a text encoding, and it would
 * present as "the classifier is oddly bad at email".
 */
final readonly class EmailMessage
{
    /**
     * @param array<string,string> $headers lower-cased names
     * @param list<string>         $links   every http(s) URL found, in order, de-duplicated
     */
    private function __construct(
        public array $headers,
        public string $body,
        public array $links,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function subject(): string
    {
        return $this->header('subject') ?? '';
    }

    public function from(): string
    {
        return $this->header('from') ?? '';
    }

    /** Stable identity for a message, for the seen-set. */
    public function messageId(): ?string
    {
        $id = $this->header('message-id');

        return $id === null ? null : trim($id, '<> ');
    }

    public static function parse(string $raw): self
    {
        // Normalise line endings first. A message that mixes CRLF and LF — common once it has passed
        // through a filter — otherwise splits its header block in the wrong place, and everything
        // downstream reads headers as body.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $split = preg_split('~\n\n~', $raw, 2);
        $headerBlock = $split[0] ?? '';
        $bodyBlock = $split[1] ?? '';

        $headers = self::parseHeaders($headerBlock);
        $body = self::decodeBody($headers, $bodyBlock);

        return new self($headers, $body, self::extractLinks($body));
    }

    /** @return array<string,string> */
    private static function parseHeaders(string $block): array
    {
        $headers = [];
        $name = null;
        $value = '';

        foreach (explode("\n", $block) as $line) {
            // A leading space or tab CONTINUES the previous header — RFC 822 folding. Real subjects
            // fold constantly, and a parser that ignores it truncates every long one.
            if ($name !== null && ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))) {
                $value .= ' ' . trim($line);

                continue;
            }

            if ($name !== null) {
                $headers[$name] = self::decodeHeader($value);
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                $name = null;
                $value = '';

                continue;
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
        }

        if ($name !== null) {
            $headers[$name] = self::decodeHeader($value);
        }

        return $headers;
    }

    /** RFC 2047 encoded words — `=?UTF-8?Q?Nouvelle_annonce?=`, which every French subject uses. */
    private static function decodeHeader(string $value): string
    {
        $decoded = preg_replace_callback(
            '~=\?([^?]+)\?([BbQq])\?([^?]*)\?=~',
            static function (array $m): string {
                $text = strtoupper($m[2]) === 'B'
                    ? (base64_decode($m[3], true) ?: '')
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));

                return self::toUtf8($text, $m[1]);
            },
            $value,
        );

        // Adjacent encoded words are separated by whitespace that the RFC says to drop.
        return trim(preg_replace('~\?=\s+=\?~', '?==?', $decoded ?? $value) ?? $value);
    }

    /** @param array<string,string> $headers */
    private static function decodeBody(array $headers, string $body): string
    {
        $contentType = $headers['content-type'] ?? 'text/plain';

        if (stripos($contentType, 'multipart/') !== false
            && preg_match('~boundary="?([^";]+)"?~i', $contentType, $m) === 1) {
            return self::preferredPart($body, $m[1]);
        }

        $decoded = self::decodeTransfer($body, $headers['content-transfer-encoding'] ?? '');
        $charset = preg_match('~charset="?([^";]+)"?~i', $contentType, $c) === 1 ? $c[1] : 'UTF-8';
        $text = self::toUtf8($decoded, $charset);

        return stripos($contentType, 'text/html') !== false ? self::stripHtml($text) : $text;
    }

    /**
     * From a multipart body, the part worth classifying.
     *
     * `text/plain` is preferred over `text/html` — not for tidiness, but because an HTML part
     * carries markup that would need stripping, and stripping is where a tenure label hidden in an
     * attribute gets lost. When only HTML exists it is stripped, and the classifier sees the
     * result; `Text` deliberately does NOT decode entities, so a broken strip stays visible rather
     * than being silently papered over.
     */
    private static function preferredPart(string $body, string $boundary): string
    {
        $parts = explode('--' . $boundary, $body);
        $plain = null;
        $html = null;

        foreach ($parts as $part) {
            $part = ltrim($part, "\n");
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            $split = preg_split('~\n\n~', $part, 2);
            $partHeaders = self::parseHeaders($split[0] ?? '');
            $partBody = $split[1] ?? '';

            $type = $partHeaders['content-type'] ?? 'text/plain';

            if (stripos($type, 'multipart/') !== false
                && preg_match('~boundary="?([^";]+)"?~i', $type, $m) === 1) {
                $nested = self::preferredPart($partBody, $m[1]);
                $plain ??= $nested;

                continue;
            }

            $decoded = self::decodeTransfer($partBody, $partHeaders['content-transfer-encoding'] ?? '');
            $charset = preg_match('~charset="?([^";]+)"?~i', $type, $c) === 1 ? $c[1] : 'UTF-8';
            $text = self::toUtf8($decoded, $charset);

            if (stripos($type, 'text/plain') !== false) {
                $plain ??= $text;
            } elseif (stripos($type, 'text/html') !== false) {
                $html ??= self::stripHtml($text);
            }
        }

        return $plain ?? $html ?? '';
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode(preg_replace('~\s+~', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * Convert to UTF-8, and NEVER silently mangle when the charset is unknown.
     *
     * `iconv` with `//IGNORE` would drop the bytes it cannot map, which for a Latin-1 French email
     * means every accented character vanishing — and a listing whose text lost its accents still
     * looks like text, so nothing downstream reports a problem. Falling back to the original bytes
     * keeps the damage visible: `Text::foldTolerant()` returns null on invalid UTF-8, and the
     * classifier treats an unreadable surface as a reason to withhold rather than to match.
     */
    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, "\"' "));

        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'US-ASCII') {
            return $text;
        }

        // ISO-8859-1 IS READ AS CP1252, and for this project that is not pedantry — it decides
        // whether a rent is extracted at all.
        //
        // Mail declaring `charset=ISO-8859-1` is almost always Windows-1252 in fact; every mail
        // client renders it that way. The two differ exactly in 0x80–0x9F, which strict Latin-1
        // leaves undefined and CP1252 fills with the characters French commerce uses most: `€` is
        // 0x80, and the curly quotes are 0x91–0x94.
        //
        // Running the fixture is what showed it. `Loyer : 1 450 =80 charges comprises` converted from
        // strict ISO-8859-1 loses the euro sign to an invisible control character — and EVERY rent
        // pattern in `EmailAlertSource` requires a currency marker, so the rent came out NULL. A
        // listing with no rent is not disqualified (hard rule 9), so it would have been notified with
        // "loyer non communiqué" while the alert stated the rent plainly.
        $effective = in_array($charset, ['ISO-8859-1', 'ISO8859-1', 'LATIN1', 'ISO_8859-1'], true)
            ? 'CP1252'
            : $charset;

        $converted = @iconv($effective, 'UTF-8', $text);

        if ($converted === false && $effective !== $charset) {
            // A byte genuinely invalid in CP1252 but valid in Latin-1 is rare and possible; fall
            // back rather than lose the whole body.
            $converted = @iconv($charset, 'UTF-8', $text);
        }

        return $converted === false ? $text : $converted;
    }

    private static function stripHtml(string $html): string
    {
        // Block-level tags become newlines BEFORE stripping, so `<li>Chatou</li><li>Houilles</li>`
        // does not collapse into `ChatouHouilles` — which would then match neither commune.
        $spaced = preg_replace('~</(p|div|br|li|tr|h[1-6]|td)\s*>|<br\s*/?>~i', "\n", $html) ?? $html;
        $spaced = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $spaced) ?? $spaced;

        return trim(preg_replace('~\n{3,}~', "\n\n", strip_tags($spaced)) ?? '');
    }

    /** @return list<string> */
    private static function extractLinks(string $body): array
    {
        preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $body, $matches);

        $seen = [];
        foreach ($matches[0] as $link) {
            $link = rtrim($link, '.,;:');
            $seen[$link] = true;
        }

        return array_keys($seen);
    }
}
