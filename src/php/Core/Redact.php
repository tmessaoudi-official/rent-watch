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
    private const array AMBIGUOUS_NAMES = ['auth', 'key', 'session', 'sig', 'topic'];

    public static function text(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $names = implode('|', array_map(preg_quote(...), self::SECRET_NAMES));
        $ambiguous = implode('|', array_map(preg_quote(...), self::AMBIGUOUS_NAMES));

        // A name may carry an affix, because `_` is a word character and `\bpassword\b` therefore
        // does NOT match inside `IMAP_PASSWORD`. Every credential variable in `.env.example`
        // defeated the masker on exactly that: `IMAP_PASSWORD=`, `SMTP_PASSWORD=`,
        // `TELEGRAM_BOT_TOKEN=`, `IDFM_API_KEY=` and `NTFY_TOPIC=` all went through untouched,
        // while the docblock claimed the IDFM key was covered.
        $affix = '[A-Za-z0-9_.\-]*';

        // A value that is itself a URL is never the secret — the secret is INSIDE it, and the
        // query-parameter branch reaches that on its own pass.
        //
        // The quoted alternatives are not decoration: the unquoted class excludes `'`, so
        // `password='hunter2'` matched nothing at all and was passed through in full. `var_export()`
        // emits exactly that shape, and it is the likeliest way a config array reaches an exception
        // message in this language. Quoting also lets a passphrase CONTAINING SPACES be masked
        // whole, which the unquoted form cannot: there, only the first word goes, because nothing
        // marks where an unquoted value ends. That residue is a known floor, not an oversight.
        $quoted = '"[^"\n]{0,400}"|\'[^\'\n]{0,400}\'';
        // The negative lookahead stops a later pattern from masking an EARLIER pattern's output:
        // `IDFM_API_KEY=<secret>` was matched by the `api_key` rule and then again by the `key`
        // rule, which consumed `[masqué` and left a stray `]` behind.
        $value = '(?!https?://)(?!' . preg_quote(self::MASK, '~') . ')'
            . '(?:Bearer\s+|Basic\s+)?(?:' . $quoted . '|[^\s&"\'\)\],;]+)';

        // `name` `=>` `value` is PHP's own array syntax, and `var_export()` on a config array is the
        // likeliest way a credential reaches an exception message in this language.
        // NO trailing quote here: it would consume the opening quote of the value and stop the
        // quoted alternative below from matching, which is how a quoted passphrase kept leaking all
        // but its first word.
        $delimiter = '["\']?\s*(?:=>|[:=])\s*';

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
            // A Telegram token is `<bot_id>:<hash>`; the literal `bot` prefix exists ONLY in the
            // API path above, so a pattern anchored on `bot\d+:` was dead for a token in a POST
            // body or a bare error string. The digit:hash shape is specific enough on its own.
            '~\b\d{8,12}:[A-Za-z0-9_\-]{30,}~' => self::MASK,
            '~(ntfy\.[A-Za-z0-9.\-]+/)[^/\s?]+~i' => '$1' . self::MASK,
            // SASL: `AUTH PLAIN <base64>` decodes to \0user\0password. The mechanism name is kept
            // because WHICH mechanism the server rejected is the entire diagnostic.
            '~\b(AUTH|AUTHENTICATE)\s+([A-Za-z0-9\-]{3,20})\s+[A-Za-z0-9+/=]{12,}~' => '$1 $2 ' . self::MASK,
            // A padded base64 blob standing alone. The `=` padding is what makes this narrow enough
            // to use: ordinary prose does not produce it.
            '~\b[A-Za-z0-9+/]{24,}={1,2}~' => self::MASK,
            // IMAP `A01 LOGIN <user> <pass>` and POP3 `PASS <pass>` — space-separated, no delimiter
            // at all, and on the primary ingestion path.
            //
            // CASE-SENSITIVE, and the argument must be quoted or contain a non-letter. Both rules
            // are load-bearing, and an earlier five-word negative lookahead was not enough: the
            // pattern ate `PASS command issued in wrong state` (RFC 1939), `LOGIN please use the
            // referral server` (RFC 2221), *"un formulaire de login au lieu des annonces"*, *"does
            // not pass validation"*, and `Pass Navigo` — the literal name of the Paris transit card,
            // in the IDFM enrichment domain this project has. A credential contains a digit or a
            // symbol; an English or French word does not.
            '~\b(LOGIN|PASS)\s+((?:' . $quoted . '|\S*[\d@:!#$%^&*+=/\\\\|?]\S*)(?:\s+(?:' . $quoted . '|\S+))?)~u'
                => '$1 ' . self::MASK,
            // key=…, token: …, "client_secret": "…", Authorization: Bearer …
            '~\b(' . $affix . '(?:' . $names . ')' . $affix . ')\b' . $delimiter . $value . '~i'
                => '$1=' . self::MASK,
            '~\b(' . $affix . '(?:' . $ambiguous . ')' . $affix . ')\b["\']?\s*(?:=>|=)\s*' . $value . '~i'
                => '$1=' . self::MASK,
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
