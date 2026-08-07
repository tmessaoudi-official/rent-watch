<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * Masks credentials and personal identifiers in text that is about to be persisted or shown.
 *
 * This exists because of where an adapter's error message ends up. `Store::recordRun()` stores it
 * verbatim in `source_runs.error`; `Store::health()` interpolates it into `SourceHealth::$detail`;
 * `SourceStatus::isAlerting()` says that detail should reach the user. So an adapter exception —
 * a cURL error carrying the request URL, an IMAP failure carrying the mailbox — would put a token
 * or an address into a committed-adjacent database and into a push notification, through a channel
 * nobody would think to grep. `CLAUDE.md` hard rule 7 forbids exactly that.
 *
 * The store is where the guard belongs because it is the single funnel every adapter passes
 * through. Drawing it in each adapter means drawing it N times and forgetting once.
 *
 * This masks; it does not sanitise. It cannot know every shape a secret takes, so it is a floor
 * rather than a guarantee, and an adapter must still not put a credential in an exception message
 * on purpose.
 */
final readonly class Redact
{
    public const string MASK = '[masqué]';

    /**
     * Query-parameter and header names whose value is a secret. Matched case-insensitively.
     *
     * `key` is here for the IDFM / PRIM API key, which travels as a query parameter — the one
     * concrete credential the spec already names as living in a URL.
     */
    private const array SECRET_NAMES = [
        'api_key', 'apikey', 'api-key', 'access_token', 'auth', 'authorization', 'client_secret',
        'key', 'passwd', 'password', 'pwd', 'secret', 'session', 'sig', 'signature', 'token',
    ];

    public static function text(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $names = implode('|', array_map(preg_quote(...), self::SECRET_NAMES));

        $patterns = [
            // URL userinfo: https://user:hunter2@host/…
            '~(?<=://)[^/\s:@]+:[^/\s@]+(?=@)~i' => self::MASK,
            // key=…, token: …, "client_secret": "…", Authorization: Bearer …
            //
            // The optional `"` after the name is what makes the JSON shape work, and the optional
            // `Bearer`/`Basic` is what stops the name from consuming only the SCHEME and leaving
            // the token standing — both were live leaks until the test provider named them.
            '~\b(' . $names . ')\b"?\s*[:=]\s*"?(?:Bearer\s+|Basic\s+)?[^\s&"\'\)\],;]+~i' => '$1=' . self::MASK,
            // Bare bearer tokens, which carry no parameter name at all.
            '~\b(Bearer|Basic)\s+[A-Za-z0-9._\-+/=]{8,}~i' => '$1 ' . self::MASK,
            // Mailboxes — the IMAP path's own identifier, and personal data in its own right.
            '~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~' => self::MASK,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $message);

            // preg_replace returns null on a PCRE failure — in practice, invalid UTF-8 reaching a
            // `u`-less pattern with a backtrack limit, or a catastrophic backtrack. Refusing to
            // persist ANY message is the wrong trade (it would hide the breakage the message
            // reports), so the masked-so-far text is kept and the rest is dropped rather than
            // passed through unmasked. Failing open here would defeat the whole point of the class.
            if ($replaced === null) {
                return self::MASK;
            }

            $message = $replaced;
        }

        return $message;
    }
}
