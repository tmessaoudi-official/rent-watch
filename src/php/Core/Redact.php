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
     * Names whose value is unambiguously a secret. Matched case-insensitively, after `:` or `=`.
     *
     * `pass` earns its place the hard way: it was missing, and `pass=` is exactly the shape
     * `imap_open()` and DSN errors emit — on the primary ingestion path.
     */
    private const array SECRET_NAMES = [
        'access_token', 'api-key', 'api_key', 'apikey', 'authorization', 'client_secret',
        'mot de passe', 'motdepasse', 'pass', 'passwd', 'password', 'pwd', 'secret',
        'signature', 'token',
    ];

    /**
     * Names that are ALSO ordinary words, so they only count in `name=value` form.
     *
     * `key` and `auth` are the IDFM key's query parameter and a common French error prefix.
     * Accepting `auth:` masked the failing URL out of *"Erreur auth: https://…/api a répondu 403"*,
     * and accepting `key:` ate a word out of a YAML error — the reverse failure, where the breakage
     * report survives but is undiagnosable, which is how a masker gets deleted.
     */
    private const array AMBIGUOUS_NAMES = ['auth', 'key', 'session', 'sig'];

    public static function text(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $names = implode('|', array_map(preg_quote(...), self::SECRET_NAMES));
        $ambiguous = implode('|', array_map(preg_quote(...), self::AMBIGUOUS_NAMES));

        // A value that is itself a URL is never the secret — the secret is INSIDE it, and the
        // query-parameter branch reaches that on its own pass.
        $value = '(?!https?://)(?:Bearer\s+|Basic\s+)?[^\s&"\'\)\],;]+';

        $patterns = [
            // URL userinfo: https://user:hunter2@host/… The `?` exclusions stop a query-borne `@`
            // from turning `host.example.com:8443?redirect=x@y` into a mask over the HOST, which
            // pointed a debugging human at the wrong server entirely.
            '~(?<=://)[^/\s:@?]+:[^/\s@?]+(?=@)~i' => self::MASK,
            // Path-segment credentials, which carry no parameter name and no `=` at all. Both of
            // these are canonical URL shapes for channels `.env.example` names, and both went
            // through untouched: every Telegram API error carries the full bot token, and
            // docs/OPEN-QUESTIONS.md calls the ntfy topic a secret in as many words.
            '~(api\.telegram\.org/bot)[^/\s]+~i' => '$1' . self::MASK,
            '~\b(bot\d{6,}:)[A-Za-z0-9_\-]{10,}~' => '$1' . self::MASK,
            '~(ntfy\.[A-Za-z0-9.\-]+/)[^/\s?]+~i' => '$1' . self::MASK,
            // IMAP `A01 LOGIN <user> <pass>` and POP3 `PASS <pass>` — space-separated, no
            // delimiter at all, and on the primary ingestion path. The mailbox was masked and the
            // password beside it was not.
            //
            // The lookahead is not decoration: `imap_open(): Login failed for user=…` is the
            // commonest message this pattern will ever meet, and without it the mask ate the words
            // that say what went wrong.
            '~\b(LOGIN|AUTHENTICATE|PASS)\s+(?!failed\b|error\b|incorrect\b|refused\b|denied\b)\S+(\s+\S+)?~i'
                => '$1 ' . self::MASK,
            // key=…, token: …, "client_secret": "…", Authorization: Bearer …
            '~\b(' . $names . ')\b"?\s*[:=]\s*"?' . $value . '~i' => '$1=' . self::MASK,
            '~\b(' . $ambiguous . ')\b"?\s*=\s*"?' . $value . '~i' => '$1=' . self::MASK,
            // The RFR income figure. `CLAUDE.md` hard rule 7 names it in the same breath as the
            // IMAP password, and the eligibility comparison of Q6 is exactly the code that would
            // put it in an exception message. It had no pattern at all.
            // The digit class allows an inner space only before another digit, so the French
            // `41 250` is consumed whole while the space before `EUR` is left alone.
            '~\b(RFR)[\w\s\-]{0,8}?[:=]\s*\d(?:[\d.,]|\s(?=\d))*~i' => '$1=' . self::MASK,
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
