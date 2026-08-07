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
     * Names whose value is unambiguously a secret. Matched case-insensitively, after `:`, `=` or
     * PHP's `=>` — `var_export()` on a config array is the likeliest accidental leak in this
     * language, and it emits neither of the first two.
     *
     * `pass` earns its place the hard way: it was missing, and `pass=` is exactly the shape
     * `imap_open()` and DSN errors emit — on the primary ingestion path.
     */
    private const array SECRET_NAMES = [
        'access_token', 'api-key', 'api_key', 'apikey', 'authorization', 'client_secret',
        'mot de passe', 'motdepasse', 'pass', 'passwd', 'password', 'pwd', 'secret',
        'signature', 'token',
        // `docs/OPEN-QUESTIONS.md` Q9 calls the ntfy topic a secret in as many words — anyone who
        // knows it reads the notifications. It sat in the ambiguous list, which accepts only `=`,
        // so `{"topic":"…"}` from an HTTP client went through while `NTFY_TOPIC=…` was masked.
        'topic',
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

    /**
     * Words that follow `PASS` in a protocol RESPONSE rather than a command.
     *
     * This list preserves diagnostics; it does not decide what is secret. Anything NOT here is
     * masked, so an omission costs one masked word in an error message and never a leaked password.
     * Both languages, because this project's own messages are French.
     */
    private const string PROSE_AFTER_VERB =
        'command|commande|failed|required|requis|invalid|invalide|incorrect|expected|attendu'
        . '|argument|arguments|syntax|syntaxe|missing|manquant|denied|refused|refuse|refusé|error'
        . '|erreur|unknown|inconnu|supported|allowed|obligatoire|only|must|cannot|not|non|is|was'
        . '|before|after|avant|apres|après';

    public static function text(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $names = implode('|', array_map(preg_quote(...), self::SECRET_NAMES));
        $ambiguous = implode('|', array_map(preg_quote(...), self::AMBIGUOUS_NAMES));

        // A name may carry an affix, because `_` is a word character and `\bpassword\b` therefore
        // does NOT match inside `IMAP_PASSWORD`. Every credential variable in `.env.example`
        // defeated the masker on exactly that.
        //
        // But the affix must be a SEPARATED component — `FOO_password_BAR`, not `passage`. The
        // first version allowed any surrounding letters, which turned every name into a substring
        // match and ate this project's own vocabulary: `passage:` (the PRIM enrichment domain),
        // `passed:`, `tokens:`, `bypass:`, `signatures:`, `keyword=`, `signal=` and `design=`.
        // All of those survived before the affix existed. Hence the boundaries below: a component
        // ends at `_`, `-` or `.`, and never mid-word.
        // A component boundary is a DELIMITER *or* a case transition. Requiring a delimiter alone
        // was the round-13 P0: camelCase is the dominant JSON key convention and the literal
        // spelling of every OAuth field, so `clientSecret`, `accessToken`, `botToken`,
        // `refreshToken` and `userPassword` all stopped matching — an OAuth 401 body reached
        // `source_runs.error` and the notification in cleartext. `apiKey` survived only by accident,
        // because `apikey` happens to be a whole entry in the list above.
        //
        // The camel assertions are wrapped in `(?-i:…)` because the patterns run case-INSENSITIVELY
        // and `[A-Z]` would otherwise match a lowercase letter, collapsing the boundary to nothing.
        // The right-hand camel form demands `[A-Z][a-z]` — a real hump like `Key`, not `AGE` — so
        // `PASSAGE` and `SIGNAL` in an all-caps log line stay intact.
        $boundaryL = '(?:(?<![A-Za-z])|(?-i:(?<=[a-z0-9])(?=[A-Z])))';
        $boundaryR = '(?:(?![A-Za-z])|(?-i:(?=[A-Z][a-z])))';
        $before = '[A-Za-z0-9_.\-]*' . $boundaryL;
        $after = $boundaryR . '[A-Za-z0-9_.\-]*';

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
        // …and the UNQUOTED branch must contain at least one alphanumeric. `unexpected token: }` is
        // the canonical JSON parse failure — the message a broken adapter emits — and `}` is not a
        // secret. The requirement sits inside that branch only: applied to the whole value it also
        // rejected every quoted one, because the lookahead ran past the closing quote.
        $hasAlnum = '(?=[^\s&"\'\)\],;]*[A-Za-z0-9])';
        $value = '(?!https?://)(?!' . preg_quote(self::MASK, '~') . ')'
            . '(?:Bearer\s+|Basic\s+)?'
            . '(?:' . $quoted . '|' . $hasAlnum . '[^\s&"\'\)\],;]+)';

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
            // Base64 credential blobs, in three shapes, because the obvious single rule excluded
            // the commonest secret in this project. `base64_encode()` of a 16-character Google app
            // password is `YWJjZGVmZ2hpamtsbW5vcA==` — 22 characters, no digit — so a rule demanding
            // 24 characters AND a digit missed exactly the secret the plaintext rule leaks, using
            // the very discriminator that leak was caused by. PHPMailer's `SMTPDebug` emits it.
            //
            //   `==` double padding is decisive on its own: a query parameter has one `=`, not two.
            //   A single `=` still needs a digit, `+` or `/`, or `?typeDeBienRecherche…=appartement`
            //     — a real French portal parameter — is eaten NAME-first, keeping the value.
            //   A line that is NOTHING but base64 is a credential blob; SMTP AUTH puts the user and
            //     the password on their own lines, where no verb precedes them.
            '~\b[A-Za-z0-9+/]{16,}==~' => self::MASK,
            '~\b(?=[A-Za-z0-9+/]*[0-9+/])[A-Za-z0-9+/]{16,}=~' => self::MASK,
            '~^[A-Za-z0-9+/]{16,}={0,2}[ \t]*\r?$~m' => self::MASK,
            // IMAP `<tag> LOGIN <user> <pass>` and POP3 `PASS <pass>` — space-separated, no
            // delimiter at all, and on the primary ingestion path. Three rules have failed here and
            // each failure is encoded below rather than summarised.
            //
            // 1. A character class ("the argument contains a digit or a symbol") LEAKED every
            //    all-letter password. A Google app password is sixteen lowercase letters, and a
            //    dedicated Gmail mailbox is what `.env.example` provisions.
            // 2. An end-of-line anchor alone leaked whenever the adapter WRAPPED the library error
            //    with its own context — `… > A01 LOGIN user pass (tentative 1/3)` — which is the
            //    natural way to satisfy hard rule 3. Seven of thirteen realistic shapes got through.
            // 3. A short negative lookahead ate `PASS command issued in wrong state` (RFC 1939).
            //
            // So LOGIN keys off the IMAP TAG, which prose does not have — an untagged end-of-line
            // variant was tried and ate `échec LOGIN sur imap.example.net`, and IMAP commands always
            // carry a tag anyway. PASS keys off a stoplist of protocol and prose
            // words — and note the DIRECTION: an unlisted word is MASKED, so a missing entry costs a
            // masked diagnostic word, never a leaked credential. The earlier lookahead had it the
            // other way round.
            '~\b([A-Za-z]{0,4}[0-9]{1,5})[ \t]+(LOGIN)[ \t]+(?:' . $quoted . '|\S+)[ \t]+(?:' . $quoted . '|\S+)~'
                => '$1 $2 ' . self::MASK,
            '~\b(PASS)[ \t]+(?!(?i:' . self::PROSE_AFTER_VERB . ')\b)(?:' . $quoted . '|\S+)~'
                => '$1 ' . self::MASK,
            // AUTHENTICATE keys off the tag for the same reason LOGIN does: an untagged
            // `AUTHENTICATE XOAUTH2 rejected, server requires PLAIN` is a server RESPONSE, and
            // which mechanism was rejected is the whole diagnostic. The mechanism name is kept.
            '~\b([A-Za-z]{0,4}[0-9]{1,5})[ \t]+(AUTHENTICATE)[ \t]+([A-Za-z0-9\-]{3,20})[ \t]+(?:' . $quoted . '|\S+)~'
                => '$1 $2 $3 ' . self::MASK,
            // key=…, token: …, "client_secret": "…", Authorization: Bearer …
            // The key's own closing quote is captured into `$1`, so `['api_key' => '…']` keeps its
            // bracket count instead of coming out as `['api_key=[masqué]]`.
            '~(' . $before . '(?:' . $names . ')' . $after . '["\']?)\s*(?:=>|[:=])\s*' . $value . '~i'
                => '$1=' . self::MASK,
            '~(' . $before . '(?:' . $ambiguous . ')' . $after . ')["\']?\s*(?:=>|=)\s*' . $value . '~i'
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

            // `preg_replace` returns null on a PCRE failure. The demonstrated trigger is the
            // BACKTRACK LIMIT ({@see RedactTest::testAPcreFailureFailsClosed}, which lowers it) —
            // NOT invalid UTF-8, which a `u`-LESS pattern handles fine. An earlier version of this
            // comment had that backwards, a `u` flag was added elsewhere on its strength, and one
            // Latin-1 byte then masked an entire diagnostic. There are no `u`-flagged patterns
            // here now, deliberately.
            //
            // Failing OPEN would return the original unmasked text, which is the one outcome this
            // class exists to prevent. Losing a diagnostic is recoverable; leaking is not.
            if ($replaced === null) {
                return self::MASK;
            }

            $message = $replaced;
        }

        return $message;
    }
}
