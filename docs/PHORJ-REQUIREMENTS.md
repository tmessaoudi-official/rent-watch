# What rent-watch needs from phorj

> Requested 2026-08-06: *"give me what you need from phorj so i build it"*.
>
> This is a **requirements spec, not a wishlist.** Two features block rent-watch. Everything else it
> needs, phorj already has. Each item below states the use case, the **minimum** API surface, the
> semantics that actually matter for this product, and — deliberately — **what NOT to build**, so the
> scope stays small.
>
> Signatures follow phorj's own conventions as observed in its tree at `1.0.0-nightly.0`: module-qualified
> functions, member-imported types, typed error taxonomies (`Mail.TlsError`, `Database.UniqueViolationError`),
> `Core.ClosableModule` + `using` for resource release, and `Core.IteratorModule<T>` for streaming.
> Treat the exact names as proposals; the **semantics** are the requirement.

---

## Already sufficient — no work needed

Verified in `Cargo.toml` / `docs/EXTENSIONS.md`. Listing it so nothing here gets built twice:

| Need | phorj today |
|---|---|
| HTTP GET/POST with headers, JSON bodies, HTTPS | `Core.HttpClient` (opt-in `http-client`, rustls + webpki-roots) |
| JSON parse/render | `Core.Json` (**default** feature) |
| SQLite persistence, transactions, typed row access | `Core.Database` (`database = ["dep:rusqlite"]`, bundled — no system libsqlite3) |
| Send the digest email | `Core.Mail` (opt-in: SMTP, auth, STARTTLS/TLS, optional DKIM) |
| Regex (accent-insensitive matching, floor/lift sniffing) | `Core.Regex` |
| Money without float error | `Core.Decimal` + `RoundingMode` |
| Secrets from env, kept out of logs | `Core.Env` + `Core.Secret` |
| Timestamps, freshness windows | `Core.Time` |
| URL building / normalising for dedup keys | `Core.UriModule` |
| CLI entry point with an exit status | `#[Entry(kind: EntryKind.Cli)]` returning `int` |
| The optional read-only web digest | `Core.Http` Router + the `Core.Html` **builder** |
| Config loading | `Core.Config` / `Core.Ini` — see open question Q-A below re YAML |
| Single-file deploy | native single-binary compilation |

---

## ① `Core.Imap` — **the blocker.** Priority 1

### Why rent-watch cannot work around it

The private-portal half (Track 2: SeLoger, Leboncoin, PAP, Bien'ici, Logic-Immo…) is ingested by
**subscribing to each portal's own alert email** and reading a dedicated mailbox over IMAP. That is not a
workaround for scraping — it is the primary, ToS-respecting path, and it is *faster* than polling because
alerts fire on publication.

There is no alternative route: **5 of 11 portals return HTTP 403 to an honest non-browser client**
(measured, `docs/SOURCES.md`). Without IMAP, Track 2 cannot be built at all.

phorj's `Core.Mail` is SMTP **send**. This is the **receive** twin — the same relationship
`Core.Mail` already has to `Core.Database` ("twin-of-Db", per its own Cargo comment).

### Minimum API

```phorj
import Core.Imap.ImapClient;
import Core.Imap.ImapConfig;
import Core.Imap.Message;
import Core.Imap.MailboxStatus;

// Connection. MUST implement Core.ClosableModule's Closable so `using` releases it on every path.
ImapConfig  config    = new ImapConfig(host, port, username, password, tls: TlsMode.Implicit);
using (ImapClient c = Imap.connect(config)) {
    MailboxStatus st = Imap.selectReadOnly(c, "INBOX");   // read-only is the DEFAULT verb

    // Server-side filtering — do NOT make us fetch everything and filter locally.
    List<int> uids = Imap.searchSince(c, sinceInstant);   // or searchUnseen / searchFrom

    // STREAMING, not a slurp: a mailbox with 5 000 alert emails must not be materialised.
    for (Message m in Imap.fetch(c, uids)) {
        string  from    = m.from();
        string  subject = m.subject();
        Instant date    = m.date();
        int     uid     = m.uid();
        string? html    = m.htmlBody();   // DECODED — see "MIME" below
        string? text    = m.textBody();
    }
}
```

### The semantics that actually matter

These are the requirements. The names above are negotiable; these are not.

1. **Read-only must be the default and it must be real.** rent-watch must never delete, move, or mark
   mail. A destructive default would risk the developer's own mailbox on a bug. Give
   `selectReadOnly` first-class status; if a writable `select` exists at all, make it the longer name.
2. **Stable UIDs plus `UIDVALIDITY`.** rent-watch stores the last-processed UID so it does not
   re-notify. `MailboxStatus` **must expose `uidValidity`** — when a server changes it, every cached UID
   is meaningless and the client must be able to detect that rather than silently re-processing or
   silently skipping the whole mailbox. This is the classic IMAP correctness trap and it maps exactly
   onto rent-watch's "an exception must not become an empty list" rule.
3. **Server-side search.** `SEARCH SINCE <date>` / `UNSEEN` / `FROM <addr>` at minimum. Fetching the
   whole mailbox each run and filtering locally is what makes IMAP tools slow and impolite.
4. **Failures must be LOUD and typed.** A network drop, an auth failure, a TLS failure and "mailbox
   empty" must be **distinguishable**. rent-watch's hard rule 3 is that an exception must never become an
   empty list — a silent breakage is indistinguishable from a quiet rental market, which is the exact
   failure this product exists to avoid. Mirror the `Core.Mail` taxonomy:
   `ImapError` · `ImapAuthError` · `ImapTlsError` · `ImapProtocolError` · `MailboxNotFoundError`.
   **A returned empty list must mean "the mailbox has no matching messages", never "something broke".**
5. **MIME decoding, because portal alerts are multipart HTML.** `htmlBody()` / `textBody()` must return
   **decoded** text: `quoted-printable` and `base64` transfer encodings undone, charset (`ISO-8859-1`,
   `UTF-8`, `windows-1252` — French senders use all three) converted to phorj strings, and the right part
   picked out of `multipart/alternative` and `multipart/mixed`. If this is not in scope for `Core.Imap`,
   it needs to be a sibling (`Core.Mime.parse`) — but **rent-watch cannot use raw RFC822 bytes**, and
   hand-rolling MIME decoding in application code is exactly the wrong place for it.
6. **TLS: implicit on 993 and STARTTLS on 143.** Gmail and most providers are implicit/993. `rustls` is
   already admitted for `Core.HttpClient` and `Core.Mail`, so no new dependency domain.
7. **Idempotent re-processing.** Reading the same UID twice must be side-effect-free. That falls out of
   read-only, but it should be an explicit guarantee: rent-watch will re-read on restart.

### ⭐ The one item that unlocks rent-watch's tests — do not skip it

**A file-backed transport, mirroring `Mail.FileTransport` / `Mail.NullTransport`.**

```phorj
ImapClient c = Imap.connectDirectory("tests/fixtures/private/seloger/");  // *.eml on disk
```

rent-watch's testing rules require **fixture-based parser tests that run offline, with no network in
CI** — a parser test that reaches the network is a monitoring check, not a test. Without a file-backed
IMAP client, every Track 2 parser test needs a live mailbox, which means Track 2 ships untested.

phorj already has this pattern on the send side. This is the receive twin, and for rent-watch it is not a
nicety — it is the difference between a tested and an untested half of the product.

### Explicitly NOT needed — please don't build these for us

IDLE / push, APPEND, flag writes, folder create/delete/rename, quotas, ACLs, threading, server-side sort,
CONDSTORE/QRESYNC, attachment extraction, multiple simultaneous connections. rent-watch polls a
single mailbox read-only on an interval. **A minimal read-only client is genuinely sufficient**, and
`docs/plans/MASTER-PLAN.md` DEC-413 deferred IMAP partly on breadth grounds — this scope is narrow enough
to sidestep that reasoning.

---

## ② An HTML **parser** with CSS selectors — Priority 2

### Why

`Core.Html` today is a **builder** (`Html.div`, `Html.el`, `Html.attr`) — it renders HTML, it cannot read
it. rent-watch needs to read it in two places:

- **`type: html` source adapters.** Not every landlord exposes a JSON endpoint; the fallback is CSS
  selectors over server-rendered HTML. `config/sources.yaml` is designed around
  `item_selector` + `select: {title: "h3.card-title", url: "a.card-link@href"}`.
- **Portal alert emails, which are HTML.** Even with `Core.Imap`, each alert must be parsed into listings
  — the message body *is* an HTML document.

So this is not only a Track 1 concern: **without it, Track 2 gets its emails and cannot read them.**

### Minimum API

```phorj
import Core.Html.Document;
import Core.Html.Node;

Document doc = Html.parse(htmlString);          // MUST be lenient — real-world HTML is malformed
List<Node> cards = Html.select(doc, "article.annonce-card");
for (Node card in cards) {
    Node?   title = Html.selectOne(card, "h3.card-title");
    string  text  = Html.text(title);            // normalised whitespace
    string? href  = Html.attribute(card, "a.card-link", "href");
}
```

### Semantics that matter

1. **Lenient parsing, HTML5-style.** Listing pages and marketing emails are full of unclosed tags and
   stray markup. A strict XML parser is unusable here. (Your tree already references a planned W4-10 HTML5
   parser — that is exactly this.)
2. **CSS selector support: tag, `.class`, `#id`, descendant, and attribute presence.** That covers every
   selector shape `config/sources.yaml` needs. Full CSS4 is not required.
3. **Scoped queries.** `select` within a `Node`, not only from the document root — the adapter selects
   cards, then queries fields *inside* each card.
4. **Text extraction with whitespace normalisation**, and attribute reads returning **`string?`** — a
   missing attribute is `null`, not `""`. rent-watch's hard rule 9 is that absent ≠ zero, and an empty
   string that should have been `null` becomes a silent wrong filter decision.
5. **Entity decoding** (`&eacute;`, `&#39;`, `&nbsp;`) — French listings are full of them, and a
   non-breaking space inside a price breaks number parsing.

### NOT needed

DOM mutation, serialisation back to HTML (the builder covers that), XPath, JavaScript execution, CSS
cascade/computed styles.

---

## Open questions back to you

**Q-A — YAML config.** rent-watch's design has `config/criteria.yaml` + `config/sources.yaml`. I saw
`Core.Config` and `Core.Ini`, but no YAML. Three ways out, and this is a genuine choice:
1. **Use whatever phorj has** — if `Core.Config` covers a nested key/value tree, use that format instead
   of YAML and change rent-watch's design. My preference: config is our detail, not a reason to grow the
   language.
2. Add `Core.Yaml` — more work, and YAML is a famously large spec to do properly.
3. Use **JSON** for config, since `Core.Json` is already a default feature. Less pleasant to hand-edit
   (no comments), but zero new language surface.

**Q-B — build order.** `Core.Imap` blocks Track 2 only. The HTML parser blocks `type: html` adapters in
Track 1 **and** all of Track 2's email parsing. So if Track 2 matters soon, **the HTML parser is
arguably the higher-leverage one to land first**, even though IMAP is the harder blocker.

**Q-C — is `http-client` production-ready?** It is opt-in and its Cargo comment calls it *"the TOP-20 #2
parity blocker"*. If it is still in progress, that changes what Track 1 can do today — Track 1 is
*entirely* HTTP. Worth knowing before I write an adapter against it.

---

## Summary

| Need | Status | Blocks | Priority |
|---|---|---|---|
| HTTP, JSON, SQLite, SMTP, Regex, Decimal, Env, Time, Uri, CLI entry, Router, native binary | ✅ present | — | — |
| **`Core.Imap`** (read-only, streaming, typed errors, MIME-decoded, **file-backed test transport**) | ❌ deferred (DEC-413) | **all of Track 2** | **1** |
| **HTML parser + CSS selectors** | ❌ builder only | `type: html` adapters **and** Track 2 email parsing | **2** |
| YAML config | ❌ absent | nothing — workaroundable (Q-A) | 3 |

Two features. Both narrow. Neither needs a new dependency domain — `rustls` is already admitted, and an
HTML parser is pure parsing.
