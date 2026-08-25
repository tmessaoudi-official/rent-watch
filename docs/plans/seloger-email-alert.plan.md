# SeLoger email-alert adapter

> **Written 2026-08-25**, the day the first real portal alert landed. Spec milestone 6 is
> *"email-alert adapter + one private portal"*; `EmailAlertSource`, `EmailMessage`, `FileMailbox`
> and `ImapMailbox` were all written BLIND — the class docblock says so in as many words: *"No real
> portal alert has been seen yet… It is built to be SHAPED by a real message."* This plan is that
> shaping.

## What the input actually was

Two `.eml` files, both from `annonces@alertes.seloger.com`:

| File | Campaign code | What it is |
|---|---|---|
| `1 nouvelle annonce _ Ile-de-France.eml` | `SLG-202501-ALI-RELAXED` | an alert, carrying an *offre alternative* badged `Surface différente` |
| `Consilium vous adresse ses dernières exclusivités.eml` | `SLG-202511-ALI-EXCLUSIVE` | an agency exclusivity routed through SeLoger |

**They are ONE format, not two.** Same card: price → property type → rooms · surface → residence,
commune, (postcode) → *Voir l'annonce*. One parser covers both; the footer campaign code is the
machine-readable discriminator if one is ever needed.

**Neither is an exact-match alert**, which is the first thing worth knowing about this feed: SeLoger
pads it with near-misses and partner placements. They are NOT filtered at ingest — `CriteriaEngine`
re-judges everything, so a listing SeLoger calls a near-miss that clears the real floors is a real
match, and dropping it at the door is the silent over-rejection hard rule 8 exists to forbid.

## What running the real message through the existing parser proved

`EmailMessage::parse()` on either file returns **`body len: 0` and `links: 0`.** The whole email
path yields zero listings against a real portal alert — hard rule 3's exact shape, arrived at
without a single `catch`.

### Defect 1 (P0) — the MIME preamble poisons the body

`preferredPart()` explodes the body on the boundary and treats **index 0 as a part**. Index 0 is the
RFC 2046 *preamble* — `This is a multi-part message in MIME format.` — which carries no
`Content-Type`, so it defaults to `text/plain`, splits to an empty body, and executes
`$plain ??= ''`. `??=` assigns only on `null`, and `''` is not `null`, so **the real `text/plain`
part that follows can never overwrite it.**

Almost every real mailer emits that preamble. The committed `email_demo` fixtures do not, which is
why 1879 green tests said nothing.

Three instances of the same bug, all fixed the same way — `''` is not an answer:

- `$plain ??= $nested` in the nested-multipart branch
- `$plain ??= $text` at the leaf
- `$html ??= self::stripHtml($text)` at the leaf

Plus the structural half: **skip `$parts[0]`**, because RFC 2046 defines everything before the first
boundary as preamble and it is never a part.

### Defect 2 (P1) — adjacent RFC 2047 encoded words keep their separator

`Subject: =?UTF-8?Q?…exclusivit?= =?UTF-8?Q?=C3=A9s?=` decodes to **`exclusivit és`**. The collapse
`preg_replace('~\?=\s+=\?~', …)` runs AFTER `preg_replace_callback` has already decoded both words,
so it can never match — dead code since it was written. Collapse first, then decode.
[Verified: the same two expressions, reordered, yield `exclusivités`.]

## The design decision that has no good option, only a least-bad one

**SeLoger sends no listing URL and no listing id.** Every link is
`click.by.seloger.com/?qs=<opaque per-recipient token>`. There is no SeLoger listing id in the HTML,
no `data-*` attribute, nothing. [Verified: the only 8-digit run in either file is the leading
segment of a photo GUID.]

`EmailAlertSource` keys on the link and strips the query — so **every link in a SeLoger alert
collapses onto the single id `https://click.by.seloger.com/`**. Every card in a message would share
one identity.

**RULED: the redirect is NEVER followed at ingest.** It is a third-party request per listing, the
token is tied to the subscriber's identity, and it manufactures an engagement signal from a click
nobody made. Hard rule 5 is *identify honestly*; this is that principle one step out. The tracking
link goes into the notification **unresolved**, so the redirect is clicked by the human who wanted
to see the ad — which is what it is for.

**RULED: identity is content-addressed, and rent is NOT in the key.** `ListingMapper` refuses a
synthetic id because a hash of the ad's *text* changes when the text is touched, and the listing
then notifies for ever. A hash of the dwelling's *structural facts* does not have that property —
commune, postcode, rooms, surface, residence are stable across re-sends. **Rent is excluded
deliberately**: a price drop is the event this project wants to detect, and a rent in the key would
turn every drop into a brand-new listing instead of a price-history event.

**A no-information floor is mandatory**, and it is the part that would otherwise have shipped as a
defect: a card whose extraction fails across the board hashes to `sha1("seloger|||||")`, and *every*
such card collapses onto that one id — the Store's own *"nothing collapses onto a shared key"*
identity guarantee, violated one layer up. Below the floor, the card is refused loudly.

Two guards travel with it:
- **Per-MESSAGE duplicate ids are a `SourceError`.** Two distinct cards in one email resolving to
  one id is an extraction failure, not a re-send. Across messages the same id IS a legitimate
  re-send, so the guard is scoped to the message.
- **Stated cost:** two units in one residence with identical rooms and surface share an identity, so
  the second is treated as seen — a miss, in the §1-safe direction. And a card that gains a
  previously-missing surface in a later email changes id once and re-notifies once.

## §1 × segmentation — closed by refusal, not by mechanism

Segmenting means a card's description is the CARD, not the message. That is *more* correct — the
Cityloger ruling is that a map must address the LISTING, never the page, and whole-body description
is the furniture failure class that cost CDC 14 of 16 correctly-badged listings.

But it loses a batch-level tenure statement. Rather than build a batch-veto nobody has needed yet:
**`card_separator` together with `mixed_tenure: true` is REFUSED at config load.** No shipped config
combines them (seloger and leboncoin are `LIBRE`; `email_demo` is mixed and unsegmented), and a
mixed source with segmentation is a §1 decision nobody has made against a real payload. Same shape
as `detail_budget: 0`. Description is the card text plus the message subject.

## Steps, each its own commit

1. **`EmailMessage` fixes, standalone.** Both defects, TDD, red first for the stated reason. They
   fix the parser for every future portal, so a red build names one change.
2. **Segmentation + content identity** in `EmailAlertSource`, plus the load-time refusal.
3. **Fixtures, config block, offline proof.** Scrub both `.eml` in place — keep the real structure
   (`=_?:` boundary, 8bit utf-8, folded headers, the RFC 2047 subject ARE the ground truth) and
   remove subscriber identity: `Delivered-To`, `To`, `Received`, `Return-Path`, `Reply-To`,
   `Feedback-ID`, `List-Unsubscribe`, every `qs=` token, and the address in the footer. Grep-verify
   zero hits before committing. `enabled: false` stays — no IMAP credentials exist; the proof is
   `--source=seloger` force-run against `MAILBOX_DIR`, which is what `/add-source` step 5 prescribes.
4. **Sabotage cases + docs.**

## Acceptance

`scout run --once --seed --source=seloger` against a throwaway `RENT_WATCH_DB` reads both fixtures
and judges two listings for the right reasons. Region mode means a null commune with postcode
`78700` still passes location — so if `communeLabels` does not contain Conflans-Sainte-Honorine,
that is not a bug to fix.

## Decisions Log

- [2026-08-25 10:20] AGREED: the other three portals (PAP, Bien'ici, Leboncoin) are NOT needed yet — spec milestone 6 asks for ONE portal, and SeLoger is the hard case (opaque links, no id, padded feed), so building against it makes the others field maps rather than a redesign.
- [2026-08-25 10:22] AGREED: the click-tracking redirect is never followed at ingest; the unresolved link goes in the notification so the user's own real click resolves it.
- [2026-08-25 10:24] AGREED: SeLoger identity is content-addressed from commune|postcode|rooms|surface|residence, with rent excluded so a price drop stays a price event.
- [2026-08-25 10:26] AGREED: a card below the no-information floor is refused loudly; duplicate ids WITHIN one message are a SourceError.
- [2026-08-25 10:28] AGREED: `card_separator` + `mixed_tenure: true` is refused at config load rather than answered with a batch-veto mechanism.
