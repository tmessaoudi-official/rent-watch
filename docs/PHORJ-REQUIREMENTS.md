# What rent-watch needs from phorj

> Hand this to phorj. Every row is **measured against phorj's tree at `1.0.0-nightly.0`**, not recalled:
> phorj's `Cargo.toml` features, phorj's `docs/EXTENSIONS.md`, its `conformance/` corpus, and the `Core.*` symbols the
> examples and tests actually reference.
>
> Three sections: **EXISTS** (don't build it twice) · **MUST BUILD** (3 items) · **MUST CONFIRM**
> (4 questions where the answer changes what rent-watch can do). Names are proposals; **semantics are the
> requirement**.
>
> Paths like `Cargo.toml`, phorj's `docs/EXTENSIONS.md` and `DEC-413` are **phorj's**, cited as evidence —
> not pointers a rent-watch session should follow. Only rent-watch's own `CLAUDE.md` is authority here.

---

## Executive summary

| | Item | Blocks | Effort |
|---|---|---|---|
| **1** | **`Core.Imap`** — read-only client + **file-backed test transport** | **all of Track 2** (private portals) | Large |
| **2** | **HTML parser + CSS selectors** | `type: html` adapters in Track 1 **and** Track 2's email parsing | Medium |
| **3** | **`sleep` / duration pause** | **`scout run --watch` — the core feature** | **Tiny** ⭐ |
| Q1 | Can `HttpClient` **set request headers**? | possibly **all of Track 1** | — |
| Q2 | Is the HTTP **timeout** configurable? | source-health correctness | — |
| Q3 | **Cookies / session** support? | AL'in only | — |
| Q4 | Config format — YAML, `Core.Config`, or JSON? | nothing (workaroundable) | — |

**Item 3 is the best effort-to-value ratio here by a wide margin** — it is a few lines and without it the
product's headline mode cannot exist.

---

## ✅ EXISTS — verified present, do not build

| Need | phorj provides | Note |
|---|---|---|
| HTTP GET/POST, HTTPS | `Core.HttpClient` (`get`, `request`, `status`, `bodyText`, `bodyBytes`, `header`, `headerNames`, `allowPrivateHosts`) + `Http.get`/`Http.post` sugar | opt-in `http-client`, rustls + webpki-roots. See Q1–Q3. |
| JSON parse/render | `Core.Json` | **default** feature, **no transpile gate** |
| SQLite | `Core.Database` — richer than I first assumed. **Both** manual `begin`/`commit`/`rollback`/`rollbackQuiet` **and** the closure form `db.transaction(fn)`; `db.transaction(fn, retries)` retries **only** on the transient `SerializationFailureError`; **nested `transaction` opens a SAVEPOINT** so an inner rollback leaves the outer intact; typed taxonomy `DatabaseError` ⊃ `UniqueViolationError` / `ConstraintViolationError` / `ConnectionError` / `SerializationFailureError` / `TimeoutError` / `SyntaxError`; typed hydration (`getString`/`getInt`/`getDecimal`, `queryOneInto`, `DatabaseStream`) | **`default` feature**, bundled SQLite — no system libsqlite3. Also ships a **`W-SQL-INJECTION` lint** steering to bound `?` placeholders, which is exactly the discipline rent-watch wants. |
| Send email | `Core.Mail` (`Mailer`, `SmtpConfig`, `Email`, `Address`, `SmtpTransport`, **`FileTransport`**, **`NullTransport`**, `MailError`, `TlsError`) | opt-in. The Null/File transports are the pattern item 1 should copy. |
| Regex | `Core.Regex` | |
| Money / no float error | `Core.Decimal` + `RoundingMode` | |
| Fuzzy matching for dedup | `String.levenshtein`, `similarText` | byte-oriented, **transpilable tier** — good, dedup stays pure |
| Stable hashing for IDs | `Core.Hash`, `Core.Cryptography` | |
| Secrets from env | `Core.Env` (`get`, `all`, `load`) + `Core.Secret` | |
| Time, freshness windows | `Core.Time` — `Instant.now`, `ofEpochSeconds`, `parse`, `Duration.minutes/hours/days` | |
| **Deterministic test clock** | **`Time.freeze(ms)` / `Time.unfreeze`** | ⭐ excellent — makes freshness-window tests exact rather than flaky |
| CLI args | `Process.args` / `Process.arguments` | raw argv; flag parsing is our code, fine |
| Console + exit status | `Core.Console` (`println`, `eprintln`), `#[Entry(kind: EntryKind.Cli)]` returning `int` | |
| **Web digest server** | `Core.Http` — `Router`, `Route`, `ServeConfig`, `Request`, `Response`, `Cookie`, `HeaderSafety`, `autoRouter` | ⭐ serving **exists**, so the read-only digest is buildable |
| HTML **rendering** for the digest | `Core.Html` builder (`el`, `div`, `attr`, `booleanAttribute`) | renders; does **not** parse — that's item 2 |
| Structured logging | `Core.Log` | |
| Resource release | `Core.ClosableModule` + `using (T h = …) { }` — the language feature ships (`guide/scope-guard.phg`: `close()` on fall-through, `return`, `break`, throw; nested guards release inner-first) | ⚠️ **but `Core.Database` has not adopted it yet** — its auto-close is `DEC-203`, a separate slice, so `db.close()` is manual today. So asking `ImapClient` to implement `Closable` is a **real (small) ask**, not free — flagging it so the estimate is honest. If that slice is not ready, a manual `Imap.close(c)` plus the `try/finally` idiom is acceptable. |
| Streaming iteration | `Core.IteratorModule<T>` (`hasNext`/`next`, foreach-able) | item 1's fetch should return one of these |
| URL normalising for dedup keys | `Core.UriModule` (RFC 3986, `parse`, `resolve`, `equals`, normalised getters) | |
| CSV export | `Core.Csv` | nice-to-have for exporting a shortlist |
| Single-file deploy | native single-binary compilation | better deploy story than an interpreter |

**Conclusion: phorj already covers the large majority of this product.** Three gaps, one of them tiny.

---

## ❌ MUST BUILD

### ① `Core.Imap` — read-only IMAP client. **Blocks all of Track 2**

Track 2 (SeLoger, Leboncoin, PAP, Bien'ici, Logic-Immo, A Vendre A Louer, Gens de Confiance) is ingested
by subscribing to each portal's **own alert email** and reading a dedicated mailbox over IMAP. That is the
primary, ToS-respecting path — not a workaround — and it is *faster* than polling, because alerts fire on
publication.

**There is no alternative route: 5 of those 11 portals return HTTP 403 to an honest non-browser client**
(measured, `docs/SOURCES.md`). Without IMAP, Track 2 cannot be built at all.

`Core.Mail` is SMTP **send**. This is the **receive** twin.

```phorj
import Core.Imap.ImapClient;
import Core.Imap.ImapConfig;
import Core.Imap.MailboxStatus;
import Core.Imap.Message;

ImapConfig cfg = new ImapConfig(host, port, user, password, tls: TlsMode.Implicit);

using (ImapClient c = Imap.connect(cfg)) {              // Closable → `using` releases on every path
    MailboxStatus st = Imap.selectReadOnly(c, "INBOX");  // read-only is the DEFAULT verb
    int validity     = st.uidValidity();                 // see requirement 2

    List<int> uids = Imap.searchSince(c, sinceInstant);  // server-side, not local filtering

    for (Message m in Imap.fetch(c, uids)) {             // Iterator<Message>, streaming
        int     uid  = m.uid();
        string  from = m.from();
        string  subj = m.subject();
        Instant date = m.date();
        string? html = m.htmlBody();                     // DECODED — requirement 5
        string? text = m.textBody();
    }
}
```

**The seven requirements, in priority order. Names negotiable; these are not:**

1. **Read-only is the DEFAULT verb, and real.** rent-watch must never delete, move or flag mail — a
   destructive default risks the developer's own mailbox on a bug. If a writable `select` exists, give it
   the longer name.
2. **Expose `uidValidity`.** rent-watch stores the last-processed UID to avoid re-notifying. When a server
   changes `UIDVALIDITY`, every cached UID becomes meaningless. The client must let us *detect* that
   rather than silently re-processing the whole mailbox (notification flood) or silently skipping it
   (permanent silence). This is the classic IMAP correctness trap.
3. **Server-side `SEARCH`** — `SINCE <date>`, `UNSEEN`, `FROM <addr>` minimum. Fetch-everything-then-filter
   is what makes IMAP tools slow and impolite.
4. **Loud, typed failures.** Network drop / auth failure / TLS failure / genuinely-empty must be
   **distinguishable**: `ImapError` · `ImapAuthError` · `ImapTlsError` · `ImapProtocolError` ·
   `MailboxNotFoundError`, mirroring the `Core.Mail` taxonomy. **An empty list must mean "no matching
   messages", never "something broke"** — rent-watch's hard rule 3, because a silent breakage is
   indistinguishable from a quiet rental market, which is the exact failure this product exists to avoid.
5. **MIME-decoded bodies.** Portal alerts are `multipart/alternative` HTML. `htmlBody()`/`textBody()` must
   return **decoded** text: `quoted-printable` and `base64` undone, charset converted (`UTF-8`,
   `ISO-8859-1`, `windows-1252` — French senders use all three), correct part selected. If out of scope
   for `Core.Imap`, it needs a sibling `Core.Mime` — **raw RFC822 bytes are not usable**, and hand-rolling
   MIME decoding in application code is the wrong place for it.
6. **TLS: implicit/993 and STARTTLS/143.** `rustls` is already admitted, so no new dependency domain.
7. **Idempotent re-reads.** Reading the same UID twice is side-effect-free. Falls out of read-only, but
   state it — rent-watch re-reads on restart.

#### ⭐ Do not skip: a file-backed transport

```phorj
ImapClient c = Imap.connectDirectory("tests/fixtures/private/seloger/");   // *.eml on disk
```

rent-watch's rules require **offline fixture tests with no network in CI** — a parser test that reaches
the network is a monitoring check, not a test. Without this, every Track 2 parser test needs a live
mailbox, so **Track 2 ships untested**. phorj already has this exact pattern on the send side
(`Mail.FileTransport` / `Mail.NullTransport`).

#### Explicitly NOT needed

IDLE/push, APPEND, flag writes, folder create/delete/rename, quotas, ACLs, threading, server-side SORT,
CONDSTORE/QRESYNC, attachment extraction, connection pooling. rent-watch polls one mailbox, read-only, on
an interval. phorj's `DEC-413` deferred IMAP partly on breadth grounds — **this scope is narrow enough to
sidestep that reasoning.**

---

### ② HTML parser with CSS selectors. Blocks `type: html` adapters **and** Track 2 email parsing

`Core.Html` today is a **builder** — `Html.div`, `Html.el`, `Html.attr` construct HTML. rent-watch needs to
**read** it, in two places:

- **`type: html` source adapters.** Not every landlord exposes JSON; the fallback is CSS selectors over
  server-rendered HTML. `config/sources.yaml` is designed around
  `item_selector: "article.annonce-card"` + `select: {title: "h3.card-title", url: "a.card-link@href"}`.
- **Portal alert emails.** Even with item ①, each alert body **is** an HTML document that must be parsed
  into listings. So this blocks Track 2 too — item ① delivers the emails and this reads them.

```phorj
Document doc      = Html.parse(htmlString);                    // lenient, HTML5-style
List<Node> cards  = Html.select(doc, "article.annonce-card");
for (Node card in cards) {
    Node?   t     = Html.selectOne(card, "h3.card-title");     // scoped to the card
    string  title = Html.text(t);                              // whitespace-normalised
    string? href  = Html.attribute(card, "a.card-link", "href"); // MISSING → null, not ""
}
```

1. **Lenient parsing.** Real listing pages and marketing emails are full of unclosed tags. A strict XML
   parser is unusable. (phorj's tree already references a planned **W4-10 HTML5 parser** — that is this.)
2. **Selectors: tag, `.class`, `#id`, descendant, attribute presence.** Covers every shape
   `config/sources.yaml` needs. Full CSS4 not required.
3. **Scoped queries** — select within a `Node`, not only from the document root.
4. **`string?` for a missing attribute**, never `""`. rent-watch's hard rule 9: absent ≠ empty, and an
   empty string that should have been `null` becomes a silent wrong filter decision.
5. **Entity decoding** — `&eacute;`, `&#39;`, `&nbsp;`. A non-breaking space inside a price breaks number
   parsing, and French listings are full of them.

**NOT needed:** DOM mutation, serialisation back to HTML (the builder covers that), XPath, JS execution,
CSS cascade.

---

### ③ `sleep` / duration pause. **Blocks `scout run --watch`** ⭐ smallest item, largest ratio

I searched the interpreter, the VM, `Core.Time`, `Core.Process`, `Core.Runtime` and the conformance
corpus: **there is no way for a phorj program to pause.** `Core.Time` has `Instant.now`, `Duration.*`, and
`Time.freeze` — but nothing that yields.

`scout run --watch` is the product's headline mode: poll every N minutes with jitter, forever. Without a
pause primitive it cannot be written — a busy-loop would burn a core continuously, and shelling out to
`sleep` per iteration abandons the single-binary story for the most common operation.

```phorj
Process.sleep(Duration.ofSeconds(1800));    // or Time.sleep — wherever it belongs
```

**Requirements:** takes a `Duration` (not raw millis, since `Duration` already exists); interruptible by
SIGINT so `Ctrl-C` stops a watch loop promptly; and **`Time.freeze` should ideally make it a no-op**, so
watch-loop tests do not actually wait — that would be a genuinely elegant pairing with the existing test
clock. Native-only is fine (`E-TRANSPILE-*` is expected; the watch loop is I/O-edge, not pure core).

---

## ❓ MUST CONFIRM — the answers change what rent-watch can do

**Q1 — Can `Core.HttpClient` SET request headers?** I can see `HttpClient.header` / `headerNames`, but
they look like *response* accessors. rent-watch **must** send:
- a custom **`User-Agent` identifying itself honestly** — that is `CLAUDE.md` hard rule 5, non-negotiable,
  and the alternative (a default or a browser-impersonating UA) is exactly what the project forbids
- `Referer` and `Content-Type: application/json` — several landlord XHR endpoints require them
- `Accept: application/json`

**If request headers cannot be set, Track 1 is blocked too**, not just Track 2. This is the single most
important answer on this page.

**Q2 — Is the request timeout configurable?** `Http.HttpTimeoutError` exists, so timeouts happen — but can
we choose the value? Source health depends on distinguishing "slow" from "broken", and an unbounded or
fixed-and-wrong timeout makes a `--watch` loop unreliable.

**Q3 — Cookies / session reuse?** `Http.Cookie` exists on the server side. The client side matters for
**AL'in** only (A4), which likely needs an authenticated session. Not a blocker — AL'in can be last.

**Q4 — Config format.** rent-watch's design has `config/criteria.yaml` + `config/sources.yaml`. There is
no `Core.Yaml`. Three ways, and my preference is first:
1. **Use `Core.Config`** if it handles a nested tree — config format is *our* detail, not a reason to grow
   the language.
2. **JSON**, since `Core.Json` is already default. Zero new surface; less pleasant to hand-edit, no comments.
3. Add `Core.Yaml` — YAML is a famously large spec; not worth it for one consumer.

---

## Two things we will build in rent-watch, not ask you for

Recording them so they are not mistaken for gaps:

- **Accent-insensitive matching.** The classifier must treat `conventionné` and `conventionne` as equal.
  `Core.String` has `lowercase`, `equalsIgnoreCase`, `containsIgnoreCase` but no accent folding, and
  `String.unicodeUpper/Lower` is **native-only** (`E-TRANSPILE-UNICODE`) — which would break the pure-core
  transpile plan. So rent-watch will fold accents with a **plain replace table in pure phorj**, keeping
  `core/tenure` transpilable. *Say if you'd rather it were stdlib — a transpilable `String.foldAccents`
  would be broadly useful — but we are not blocked.*
- **French number parsing** (`1 450,50` with a non-breaking space) — `String.replace` + `parseFloat`. Ours.

---

## The transpile split, so the requirements make sense

`phg` refuses 18 domains. Four are rent-watch's I/O: `E-TRANSPILE-HTTPCLIENT`, `E-TRANSPILE-DB`,
`E-TRANSPILE-MAIL`, `E-TRANSPILE-SERVE`. So a **whole-app** transpile is impossible today, and we are not
asking you to change that.

Instead rent-watch keeps `core/tenure`, `core/criteria`, `core/dedup` and `core/models` **pure** — `json`,
`decimal` and `regex` carry no gate — so the **tenure classifier**, the highest-risk component in the
product, runs **byte-identically on interpreter, VM and transpiled PHP**. Free differential testing on
exactly the code where a bug means surfacing housing the user is not eligible for.

That is why item ③ being native-only is fine, and why the accent-folding decision above matters: anything
the classifier touches must stay in the transpilable tier.

---

## The classifier's cross-implementation contract

Added 2026-08-07, after a review found this recorded only in a PHP docblock. "Byte-identical
differential testing" above is a promise, and these are the four properties it actually rests on. A
phorj port that gets any of them wrong will disagree with PHP on real fixtures while looking correct.

**1. Confidence is an integer 0–100, divided by 100 only at the boundary.** Float arithmetic is not
guaranteed bit-identical across two runtimes; integer arithmetic is. Never accumulate in floats.

**2. `TenureSignal` carries `position` AND `length`, both byte offsets into the folded text.**
`position` is where the match starts; `length` is the length of the text that ACTUALLY matched, which
is not the length of the table literal once French inflection is in play — `logement locatif
intermediaire` is 30 bytes and the matched `logements locatifs intermediaires` is 33. Ties within a
tier are broken on `position`, so a port that omits it gets a verdict that depends on the iteration
order of its pattern table. The `conventionné` adjacency rule computes `position + length` and
requires the span between two signals to be blank, so a port that measures the literal mis-places the
end of the span by exactly the inflection.

**3. The two folded surfaces must agree byte for byte.** Explicit labels are matched against the
lowercased fold; the ambiguous acronym `PLUS` is matched against the case-preserving fold, because
case is evidence there. Their offsets are then compared directly. This holds only if lowercasing
cannot change byte length — so it is ASCII-only (`strtolower`, not a Unicode-aware lowercaser). A
Unicode lowercaser breaks it for 27 codepoints, among them `İ` U+0130, `ẞ` U+1E9E and the Kelvin sign
U+212A. Nothing in the label tables is non-ASCII, so ASCII-only folding loses no detection.

**4. Folding preserves newlines and collapses every other whitespace run to one space.** The newline
is the title/description boundary, and the `conventionné` adjacency rule treats it as a phrase break —
a title ending in an intermediate label must not qualify a `conventionné` opening the description.
The only whitespace bytes a folded string may contain are `U+0020` and `U+000A`.

`tests/fixtures/tenure/corpus.json` is the shared oracle for all four: same file, same expected
verdicts, both implementations.
