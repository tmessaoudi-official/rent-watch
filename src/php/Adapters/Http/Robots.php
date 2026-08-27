<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

/**
 * A minimal `robots.txt` reader — enough to answer *"may we fetch this path?"* honestly.
 *
 * `CLAUDE.md` hard rule 5 requires respecting `robots.txt`, and Q15 turns on it concretely: CDC
 * Habitat disallows `/Recherche/show/`, which is very likely its search-results path. A rule the
 * project states and never checks is a rule it does not have.
 *
 * **IT FAILS CLOSED ON ANYTHING IT DOES NOT UNDERSTAND.** A `robots.txt` that cannot be fetched, or
 * that parses to something ambiguous, yields *"not allowed"* rather than *"allowed"*. That is the
 * opposite of the usual convention, and deliberately so: the cost of a false "disallowed" is one
 * source staying off until someone looks, and the cost of a false "allowed" is polling a site that
 * asked us not to — with an honest User-Agent identifying whose tool it is.
 *
 * The subset implemented is the one that decides this question: `User-agent`, `Disallow`, `Allow`,
 * and longest-match-wins between the last two. `Crawl-delay` is read and exposed rather than obeyed
 * automatically, because the pacing decision belongs to the run loop (Q37), which knows about the
 * other sources. Wildcards (`*`, `$`) are honoured because real files use them heavily.
 */
final readonly class Robots
{
    /**
     * @param list<array{path: string, allow: bool}> $rules most specific first is NOT assumed —
     *                                                      longest match wins, per the convention
     */
    private function __construct(
        private array $rules,
        public ?int $crawlDelaySeconds,
        public bool $parsed,
        public ?string $unavailableReason = null,
    ) {}

    /**
     * A `robots.txt` we could not read. Everything is disallowed — see the class docblock.
     *
     * @param ?string $reason what stopped us, in words a person can act on: `HTTP 500 sur …`, a
     *                        cURL message, a refused cross-host redirect. It is carried so that
     *                        {@see refusal()} can say *"illisible"* rather than *"disallows"* — a
     *                        distinction that matters, because reporting an unread file as a RULE
     *                        sends the reader hunting through a robots.txt for a line that is not
     *                        there, when the actual fault is a 500 or an expired certificate.
     */
    public static function unavailable(?string $reason = null): self
    {
        return new self([], null, false, $reason);
    }

    public static function parse(string $body, string $userAgent = 'rent-watch'): self
    {
        $needle = strtolower($userAgent);
        $rules = [];
        $crawlDelay = null;

        // Two passes so a `User-agent: rent-watch` group anywhere in the file beats the wildcard
        // group, whatever the order. A single pass that took the first matching group would let a
        // `*` block earlier in the file mask a specific one below it.
        foreach ([$needle, '*'] as $target) {
            $inGroup = false;
            $found = false;

            foreach (preg_split('~\R~', $body) ?: [] as $line) {
                $line = trim(preg_replace('~#.*$~', '', $line) ?? '');
                if ($line === '') {
                    continue;
                }

                $parts = explode(':', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $field = strtolower(trim($parts[0]));
                $value = trim($parts[1]);

                if ($field === 'user-agent') {
                    $agent = strtolower($value);
                    // A group header after rules have started opens a NEW group; consecutive
                    // headers before any rule belong to the same group, which real files use.
                    $inGroup = $agent === $target || ($target !== '*' && str_contains($needle, $agent) && $agent !== '*');
                    if ($inGroup) {
                        $found = true;
                    }

                    continue;
                }

                if (!$inGroup) {
                    continue;
                }

                if ($field === 'disallow') {
                    // `Disallow:` with an EMPTY value means "allow everything" — it is the documented
                    // way to opt out, and reading it as "disallow /" would silently ban a site that
                    // explicitly welcomed us.
                    if ($value !== '') {
                        $rules[] = ['path' => $value, 'allow' => false];
                    }
                } elseif ($field === 'allow') {
                    if ($value !== '') {
                        $rules[] = ['path' => $value, 'allow' => true];
                    }
                } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                    $crawlDelay = (int) ceil((float) $value);
                }
            }

            if ($found) {
                return new self($rules, $crawlDelay, true);
            }

            $rules = [];
        }

        // No group applies to us and none applies to `*`: the file said nothing about this client,
        // which is the ordinary "no restrictions" case.
        return new self([], $crawlDelay, true);
    }

    /**
     * May we fetch this path?
     *
     * Longest matching rule wins, and `Allow` wins a tie — the convention every major crawler uses,
     * and the one a site author writes against.
     */
    public function allows(string $path): bool
    {
        if (!$this->parsed) {
            return false;
        }

        if ($path === '') {
            $path = '/';
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->rules as $rule) {
            if (!self::matches($rule['path'], $path)) {
                continue;
            }

            $length = strlen($rule['path']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $best = $rule['allow'];
                $bestLength = $length;
            }
        }

        return $best ?? true;
    }

    /**
     * Why this path is refused, in the words that are actually true of it.
     *
     * Two different facts reach the same refusal and must not print the same sentence. A parsed
     * file with a matching `Disallow` genuinely *disallows* the path — the reader should go and
     * read that line. An unread file disallows nothing; the fail-closed posture is what refuses,
     * and the reader should go and look at a 500, a certificate or a firewall. Collapsing the
     * second into the first is a false statement about a site's own configuration.
     *
     * The `robots.txt disallows` wording is asserted by several suites and is deliberately
     * unchanged: it is the rule case, and the rule case still says exactly what it always said.
     */
    public function refusal(string $path): string
    {
        if ($this->parsed) {
            return 'robots.txt disallows ' . $path;
        }

        return 'robots.txt illisible (' . ($this->unavailableReason ?? 'cause inconnue')
            . ') — posture fail-closed, ' . $path . ' est refusé';
    }

    /** The path portion of a URL, which is what {@see allows()} judges. */
    public static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        $out = is_string($path) && $path !== '' ? $path : '/';

        // The query string is part of what a `Disallow` can match — `Disallow: /*?search=` is a real
        // shape — so it is kept rather than stripped.
        return is_string($query) && $query !== '' ? $out . '?' . $query : $out;
    }

    /** `*` matches any run, a trailing `$` anchors the end. Everything else is a literal prefix. */
    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . ($anchored ? '$' : '') . '~';

        return preg_match($regex, $path) === 1;
    }
}
