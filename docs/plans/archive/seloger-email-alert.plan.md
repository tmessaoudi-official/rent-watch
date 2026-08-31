> SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
> Decisions Log and measurements; do not execute from this file.

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

`scout run --once --seed --source=seloger` against a throwaway `SCOUT_DB` reads both fixtures
and judges two listings for the right reasons. Region mode means a null commune with postcode
`78700` still passes location — so if `communeLabels` does not contain Conflans-Sainte-Honorine,
that is not a bug to fix.

## Decisions Log

- [2026-08-28 01:50] MEASURED and APPLIED (developer, `AskUserQuestion`): **`IMAP_MAX_MESSAGES` 150 → 250.** The deployed run reported `fenêtre IMAP tronquée : 161 message(s) … 150 lus`, so the 7-day catch-up `IMAP_SINCE_DAYS` promises was being cut — the shared-budget class that took this source 9 → 0 on 2026-08-25, caught this time by the warning that incident produced. Verified against the DEPLOYED container: the warning is gone and the source went **209 → 234 annonces** (+25 that the truncated tail was silently dropping), at 7.1 s against 11.2 s. Today's alerts were always read; what was lost was depth, which is why nothing looked wrong.
- [2026-08-28 01:50] NOTED: the window is PER-SOURCE (the `FROM` filter is pushed into the IMAP query), so raising it for seloger does not starve the other portals — which is exactly what the 2026-08-25 fix bought and what makes this a one-source knob rather than a budget reallocation.
- [2026-08-25 10:20] AGREED: the other three portals (PAP, Bien'ici, Leboncoin) are NOT needed yet — spec milestone 6 asks for ONE portal, and SeLoger is the hard case (opaque links, no id, padded feed), so building against it makes the others field maps rather than a redesign.
- [2026-08-25 10:22] AGREED: the click-tracking redirect is never followed at ingest; the unresolved link goes in the notification so the user's own real click resolves it.
- [2026-08-25 10:24] AGREED: SeLoger identity is content-addressed from commune|postcode|rooms|surface|residence, with rent excluded so a price drop stays a price event.
- [2026-08-25 10:26] AGREED: a card below the no-information floor is refused loudly; duplicate ids WITHIN one message are a SourceError.
- [2026-08-25 10:28] AGREED: `card_separator` + `mixed_tenure: true` is refused at config load rather than answered with a batch-veto mechanism.

## What actually happened — four defects, not two

The plan predicted two `EmailMessage` defects. Running the fixtures through the whole pipeline found
two more, and neither was reachable from the plan alone:

3. **A rent could be assembled across a line break** (`src/php/Adapters/EmailAlertSource.php`).
   `\s` in the thousands-separator class matches `\n`, and `Payload::int` truncates there, so the
   rent became whatever sat on the previous line. A first draft of the test put the stray digit at
   the START of the line and PASSED while the defect was fully present — the digit has to be
   adjacent to the newline, which in a real alert is a tracking URL. Worse than it first looked:
   `ref 850` above `1 450 EUR charges comprises` extracts **850** [Verified], inside the
   plausibility band, clearing a ceiling the real rent does not. A first draft of the *docblock*
   then claimed the mechanism was concatenation to 2980; it is truncation to 850. Both drafts were
   the same error — a real finding with an invented mechanism — caught only by running it.
4. **HTML entities reached the classifier undecoded, and that one is §1.** `Text::fold()` refuses
   text carrying entities and names whose job it is; SeLoger's `text/plain` part carries `&rarr;`
   because it is generated from the HTML. `logement conventionn&eacute;` folds to
   `logement conventionn` — label destroyed, listing apparently unlabelled. Found because the
   fixture test errored rather than failed.

## And one test that was wrong in kind

`testNoExcludedTenureVocabularyReachesACard` asserted the word `plus` never appears in a card. It is
one of the commonest words in French and SeLoger's own CTA is `En savoir plus →`. Asserting the
absence of a word is asserting something about French; the guarantee §1 states is about the
CLASSIFIER'S VERDICT. Replaced with a verdict assertion plus a counterweight — an explicit `PLUS`
label injected into the same real card must still REJECT — so the test cannot pass by the classifier
having stopped looking at this source. The CTA is now corpus case
`seloger-001-captured-cta-en-savoir-plus`, the fourth instance of that class and the first from an
email.

## Decisions Log (continued)

- [2026-08-25 12:05] AGREED: HTML entities are decoded once at the `EmailMessage` funnel, for the plain part as much as the HTML one, because a portal's plain alternative is generated from its HTML — decoding can only ever restore a label, never invent one.
- [2026-08-25 12:20] AGREED: the rent separator is `\h`, never `\s`; a figure and a currency sign on two different lines are not one price.
- [2026-08-25 12:40] AGREED: a §1 test asserts the classifier's verdict, never the absence of a French word from real text; every such test carries a counterweight proving the classifier still refuses an explicit label on the same fixture.
- [2026-08-25 13:10] AGREED: `seloger` ships `enabled: false` — the adapter and fixtures are proven, the IMAP credentials are not present; `MAILBOX_DIR` + `--source=seloger` is the documented proof path.

## LIVE MAILBOX VERIFIED — 2026-08-25

`.env` was already correct (`imap.gmail.com`, 993, label `rent-watch`, app password).
`scout doctor --source=seloger` against the real mailbox: **9 annonces, `ok`, ~19 s.**

**The first live pass matched 8 of 9, and four of those were COLIVING ROOMS.** Fixed in `daf0f87`
(`^\s*chambre\b` in `exclude_title_patterns`); the same pass now gives **4 matches**, all genuine
3-pièces at 810–1060 € (77720, 77250, 94420, 95110). See that commit for the full reasoning.

### OUTSTANDING — the one thing between here and `enabled: true`

**Every live listing came back with `commune = null`.** The postcode is extracted; the name is not.

*Cause* [Verified 2026-08-25, by reading `EmailAlertSource::communeIn()` and the live evidence
snapshots]: `communeIn()` scans the body for a member of `Criteria::communeLabels`, which in region
mode is built from the RANKED communes only. None of the live hits (Paris 12e, Le Plessis-Trévise,
Ermont, Chelles…) is a ranked Boucle-de-Seine commune, so the scan finds nothing.

*Why it is not cosmetic.* Matching is unaffected — the postcode carries location in region mode —
but three things degrade, all quietly:
- the notification cannot name the town (`— 77720 · T3 78 m² · 810 € CC`), which is the single most
  useful word in the push;
- `Dedup` gets a weaker key, so the cross-portal `group_key` is likelier to under-merge;
- the S1 commune score cannot fire, which is correct here but indistinguishable from a bug.

*Shape of the fix, NOT yet decided.* The card carries the commune as plain text next to the
postcode (`Nation-Picpus, Paris 12ème arrondissement (75012)`, `Le Plessis Trevise`), so the
information is present and only the reader is wrong. Options, cheapest first: (a) a
`commune_pattern` in `params`, read the same way `residence_pattern` is — config-only, per-portal,
and consistent with the adapter's existing shape; (b) derive the commune from the postcode via a
lookup, which needs a data file this repo does not have and would be wrong for multi-commune
postcodes; (c) widen `communeLabels` to every IdF commune, which needs the same data file.
**(a) is the recommendation** — it uses evidence the payload already carries rather than importing a
dataset, and hard rule 9 keeps `null` safe when the pattern misses.

*Do NOT flip `enabled: true` before this lands.* A source that pushes nameless notifications is a
source the operator learns to distrust, and the whole point of the notification is that it is
readable on a phone in ten seconds.

### What is NOT needed from the developer

Nothing, for SeLoger. Credentials are in place and verified; the commune gap is a code change.

## Decisions Log (continued)

- [2026-08-25 18:05] AGREED: a coliving room advertised with the whole flat's rooms and surface is excluded by an ANCHORED TITLE pattern, never by a description match — `3 chambres` in a description is the family flat the user wants.
- [2026-08-25 18:20] AGREED: `seloger` stays `enabled: false` until a listing's commune NAME is extracted; a notification that cannot name the town is not finished work.

## COMMUNE EXTRACTION — CLOSED 2026-08-25

Option (a) built: **`commune_pattern` in `params`**, read through the same `matchParam()` helper as
`title_pattern` and `residence_pattern`, compiled at load by the refusal added in `2d810e2`.

**The anchor is the parenthesised postcode BELOW the name, not the quartier comma above it.** That
choice is measured, not stylistic. All 50 messages were pulled from the live mailbox on 2026-08-25;
three carry the `alertes.seloger.com` From and between them hold the nine cards `doctor` reports.
The location block has two shapes:

```
 Nation-Picpus,          <- quartier, OPTIONAL
                         <- blank
 Paris 12ème arrondissement
 (75012)
```

and, on `Mormant` (77720), `Garches` (92100) and `Moret-Loing-et-Orvanne` (77250), the commune sits
directly above its postcode with no quartier at all. **Three of nine.** Anchoring on the comma would
have read six cards and silently missed a third of the source — and both frozen fixtures happen to
carry a quartier, so a fixture-only test would have proven the wrong shape and said so confidently.
That is the n=1 generalisation this repo has already paid for twice (the In'li lift claim, the
"live yield is 0" claim), so the no-quartier shape gets its own test rather than a fixture.

**Result** [Verified 2026-08-25: 11/11 — the 9 live cards plus both frozen fixtures]. The pattern
also refuses the price line that sits above the location block, because a commune name starts with a
letter and `[^\W\d_]` says so; and it does not fire on SeLoger's *contact receipt* template
(`seloger@s.seloger.com`, 12 of the 50 messages), whose postcode sits on the same line as its text.

**Ordering: the pattern beats the vocabulary, and the vocabulary stays as the fallback.** The scan
is a substring search over the whole card, so a card in Mormant whose copy reads *"proche Dourdan"*
returns Dourdan — the prototype's documented over-matching defect. The pattern reads the field the
portal laid out. Keeping the scan underneath means a source with no `commune_pattern` behaves
exactly as it did before this existed, which is how the blast radius is zero rather than believed
to be.

### Two things found while measuring, both worth more than the fix

- **The mailbox is the developer's personal one, not a dedicated alert address.** 50 messages, of
  which 3 are SeLoger alerts. The `from: alertes.seloger.com` filter is what isolates them, and it
  is doing real work: 12 messages come from `seloger@s.seloger.com` (contact receipts) carrying
  `En avant-première870,00 €cc /mois. · m² · pièces · chambres` and the commune
  *Saint-Germain-de-Tallevend-la-Lande* (Calvados) beside the postcode `75015` — SeLoger's own
  unfilled template defaults. A rent in the plausible band, a valid IdF postcode, and a commune 250
  km away. They are excluded by the From filter, and would ALSO be refused by the no-information
  floor (no rooms, no surface, no residence), which is the belt-and-braces working as designed.
- **A real Bien'ici alert is already in that mailbox** — `1 nouvelle annonce pour "Louer à
  Sartrouville"`, from `no_reply@bienici.com`. So the next portal needs no new input from the
  developer either, only a scrub into a fixture. PAP and leboncoin show account-creation mail only;
  no alert has fired from either, so the ask there is *finish creating the alert*, not *send a file*.

### DEPLOYMENT ORDERING — config is mounted, code is baked

`compose.yaml` mounts `./config`; `src/` comes from the image. `params` is a free-form string map
with no allowlist, so **old code reading new config ignores `commune_pattern` silently** — no
error, no warning, just `commune = null` again. A container restarted without a rebuild would
therefore look enabled and push nameless notifications, which is precisely what the 18:20 ruling
forbade. *"Lands"* in that ruling means **deployed**, not committed.

Post-redeploy verification, in this order:
1. `docker image inspect scout:local --format '{{.Created}}'` — newer than this commit.
2. `docker compose run --rm scout doctor --source=seloger` — inside the deployed image, which also
   proves `IMAP_*` reaches the container environment. If compose does not pass `.env` through,
   seloger looks enabled and never polls: this repo's signature silent failure.

The first production pass will notify the current matches at once. The seen-set holds 666 rows
across four sources and no `seloger` rows at all [Verified 2026-08-25: `select source, count(*)`],
so Q36 does not fire and nothing is re-notified — those pushes are the product working, not a flood.

## Decisions Log (continued)

- [2026-08-25 19:10] AGREED: a portal's commune is read from a per-source `commune_pattern` anchored on the portal's own layout, and the ranked-vocabulary scan stays underneath as the fallback so an unconfigured source is unchanged.
- [2026-08-25 19:15] AGREED: the SeLoger anchor is the parenthesised postcode below the name, because the quartier line above it is absent on three of nine live cards and present on both frozen fixtures — the shape a fixture-only test would have got wrong.
- [2026-08-25 19:20] AGREED: `commune_pattern` joins the loader's compile-check list, because its failure mode has an alibi — a broken pattern is indistinguishable from a listing in an unranked town.
- [2026-08-25 19:25] AGREED: `seloger` is `enabled: true`; the ruling of 18:20 is satisfied, and "lands" is read as DEPLOYED, so the redeploy check is part of the same change.

## A §1 RESIDUAL, STATED RATHER THAN DISCOVERED LATER

`seloger` is `mixed_tenure: false`, which `CLAUDE.md` already rules defensible for a private-market
portal. Going live is what makes that flag act on real listings, so here is exactly what it does and
does not buy.

**What holds, and is asserted.** An explicit excluded label — `PLS`, `PLUS`, `PLAI`, `conventionné`
— anywhere in a card is caught at 0.90 by the tier-2 label rules, which **never consult
`mixed_tenure`**. `SelogerFixtureTest::testAnExplicitExcludedLabelInACardIsStillRefused` injects one
into a real frozen card and requires a REJECT, so the flag cannot quietly become the thing standing
between a social listing and a push.

**What does not.** A card stating NO tenure at all takes the source default `LIBRE` at
deliberately-sub-threshold confidence and MATCHES. Every one of the nine live cards is in that
state — the notification says so in its own reason line (*"aucun signal dans l'annonce — défaut de
la source"*).

**Why that is worth writing down rather than shrugging at.** The mailbox proves **In'li and CDC
Habitat both advertise on SeLoger** — 4 of the 12 contact receipts name them — and In'li was itself
proven NOT pure LLI on 2026-08-23, by two live listings stating `PLS` only on their detail page. So
an institutional listing whose regime is unstated *can* reach a SeLoger alert. (The nine live cards
are all agency stock; the `3F` tokens an initial scan flagged were base64 noise inside tracking
links, not Immobilière 3F.)

**Why the flag is NOT flipped.** SeLoger alert cards state no tenure at all, so `mixed_tenure: true`
would send **100% of the source** to the *à vérifier* digest. That is the In'li lesson of 2026-08-23
in its pure form: *"not §1 satisfied, it is the tool switched off."* The mitigation is the label
rules above, plus the domain fact that PLAI and PLUS stock is allocated by commission through the
SNE and is not advertised on commercial portals at all. **PLS occasionally is, and that is the
residual.**

**The one line that reverses it:** `"mixed_tenure": false` → `true` in the `seloger` block. Do that
if a social-financed listing is ever observed in a SeLoger alert, and expect the whole source to go
to the digest until a detail-page tenure signal exists for it — which for an email source means
following the tracking redirect, itself refused on hard-rule-5 grounds.

## Decisions Log (continued)

- [2026-08-25 19:40] AGREED: `seloger` keeps `mixed_tenure: false` on going live — the tier-2 label rules catch an explicit exclusion regardless of the flag, and arming it would digest 100% of a source that states no tenure at all; the residual (an unstated PLS listing from an institutional landlord advertising on the portal) is recorded with the one line that reverses it.

## THE SHARED MAILBOX BROKE THE SOURCE — 2026-08-25, same day it went live

The developer widened their Gmail filter to catch five portals (SeLoger, Bien'ici, PAP, leboncoin,
Jinka) and re-labelled a year of archived alert mail into the same label. **SeLoger went from 9
listings to 0 within the hour**, and the only thing that said so was `SourceHealth`:
`warn_drop — 0 annonces contre une moyenne de 9.0 sur 7 jours`. Hard rule 2 paying for itself.

**Measured, not theorised** [2026-08-25, live mailbox]: the folder held **1436 messages**;
`SEARCH SINCE 24-Aug-2026` matched **124** of them, at sequence numbers beginning **6, 7, 8, 9, 10**.
`fetchRecent(50)` read sequences 1387–1436 — the tail — which contained none of the day's alerts.

**The mechanism by which a Gmail label orders its messages is deliberately NOT recorded**, here or
in the code. A first draft explained it as re-labelling assigning fresh high UIDs; that story does
not survive its own evidence, since the genuinely-recent messages sit at the LOW end. What is
recorded is what was measured: sequence order disagrees with date order, and `INTERNALDATE` survives
whatever re-labelling does (124 ≠ 1436 proves the server filtered on something re-labelling left
alone). This repo has a named failure — *"a true number attached to an invented cause"* — and a
plausible protocol story is exactly that risk.

### Two fixes, and the second is what makes it survive the five-portal plan

- **`SEARCH SINCE`** — what counts as recent is the server's answer about dates, not this client's
  assumption about ordering. `IMAP_SINCE_DAYS`, default 7 to match the health mean. A window of 0 is
  clamped to 1: a query matching nothing is indistinguishable from a quiet market.
- **`FROM <the source's own sender>`, pushed into the QUERY.** One mailbox serves every email
  source, so a single window is a shared BUDGET — and the `SINCE` window alone held 124 messages
  against a limit of 50, five portals' alerts plus the watcher's own notification emails, which land
  in the same inbox. Unscoped, a busy portal starves a quiet one silently and it worsens with every
  source added. `Scout::buildMailbox()` therefore takes the `SourceDefinition`. The post-fetch
  `from` check in `EmailAlertSource` is unchanged: this makes the fetch correct, it is not the
  security boundary.

**Result** [Verified 2026-08-25]: `scout doctor --source=seloger` → **74 annonces, `ok`** — from 0,
and from the 9 that one day's window had held.

### The wider window immediately found a defect the three-message window could not

A live **`Baisse de prix`** alert (Pontault-Combault 77340) quotes three amounts in one card: the
reduction `baissé de 100 €`, the new rent `1 100 €/mois`, and the struck-through old one
`1 200 € ↘ 8%`. The reader took the first figure it saw and returned **100** — below the
plausibility floor, so the card was refused and the source reported `broken` on a template that had
not changed at all.

That is the benign direction, and it is luck. **A 300 € reduction would have been returned as the
rent**: inside the plausibility band, six hundred euros wrong, clearing a ceiling the flat comes
nowhere near — the `ref 850` defect of the same morning, in a different template.

Two rules, one card as the evidence for both. **A rent is a PERIODIC amount** — `/mois` is what
tells the new rent apart from a discount and from an old price, neither of which carries a period —
so a periodic figure now outranks a bare one, with the bare figure still last for cards that state
nothing else. And **every match of a pattern is examined, first plausible one wins**: `preg_match`
stopped at the first hit, so one implausible figure hid a readable rent three lines below it.

> **The price-drop template is also a confirmation, not just a defect.** Content identity
> deliberately excludes the rent so that a price cut stays a price EVENT rather than minting a new
> listing. That flat seen at 1 200 € and again at 1 100 € keeps one identity, and the store records
> the drop. The design worked; only the reader was wrong.

## Decisions Log (continued)

- [2026-08-25 20:10] AGREED: "recent" is a server-side `SEARCH SINCE` query, never the tail of the folder — sequence order and date order were measured to disagree, and the disagreement silently zeroed a live source.
- [2026-08-25 20:15] AGREED: the IMAP search is scoped by the source's own `params.from`, because one mailbox serving many portals makes a single fetch window a shared budget that a busy portal silently exhausts.
- [2026-08-25 20:20] AGREED: the mechanism behind Gmail's label ordering is NOT written down, because it was not measured — only the disagreement was.
- [2026-08-25 20:25] AGREED: a rent is a periodic amount; a figure with no period marker ranks below one that has it, and every match of a pattern is examined rather than only the first.

## THE PUSH LINKED TO THE SAVED SEARCH, NOT THE FLAT — 2026-08-25

Reported by the developer within hours of the source going live: clicking a SeLoger notification
opened **the alert they had created on SeLoger**, not the listing. The one thing a push exists to
deliver, and it was wrong.

`cardListing()` took the FIRST link in a segment that passed the noise filter. On a real message that
is never the flat. Measured across a live five-card alert:

| Card | First link | Last link |
|---|---|---|
| 1 | *"mettre en pause les envois"* — alert management | `Voir l'annonce` |
| 2 | *"Estimez le prix de votre déménagement"* — a third-party advert | `Voir l'annonce` |
| 3 | the photo | `Voir l'annonce` |

One of three reached the flat, by luck. **The last link reaches it structurally**: `card_separator`
IS the call to action, and a URL precedes its own anchor text in this rendering, so a segment ENDS
with the CTA's link whatever furniture the portal puts above it.

**Established without following a single redirect.** The message's HTML part names each anchor, so
the mapping is readable offline: price, title, details, location and `Voir l'annonce` all address the
listing; the header does not. Following one to check would have manufactured an engagement signal
from a click nobody made, on a token tied to the subscriber — the ruling that put the unresolved link
in the notification in the first place.

Reversal applies to the SEGMENTED path only. Without a separator every link IS its own listing, and
reversing there would pick a different FLAT rather than a different link on the same flat.

**Verified** [2026-08-25]: over all 74 live cards, the chosen url is the last link of its own card
**74/74**, is the first link **0/74**, and the 70 stored listings hold 70 distinct urls.

> **The frozen fixtures could prove this and nearly did not.** `tools/scrub-eml.php` replaces each
> tracking token with a sequential `FIXTURE###`, which preserves ORDER — so the assertion can be
> about position (`FIXTURE007` is the CTA, `FIXTURE001` the header) even though the real tokens are
> opaque. A scrubber that randomised them would have made this class of defect untestable offline.

## Decisions Log (continued)

- [2026-08-25 21:05] AGREED: on a segmented email source the listing link is the LAST qualifying link in the card, because the separator is the call to action and its own url precedes its anchor text; the first link is portal furniture and differs per card.
- [2026-08-25 21:10] AGREED: link identity for a card was established from the message's HTML part, never by following a redirect — the per-subscriber token makes a verification click an engagement signal nobody made.

## A title is a position, never a vocabulary (2026-08-26)

`title_pattern` required the title line to begin with
`Appartement|Maison|Studio|Duplex|Loft|Chambre` — a guess at what an estate agent types.

**Verified** [2026-08-26], measured over 72 live cards pulled from the production v7 snapshots:
the old pattern missed **27 (37.5%)**, and every miss fell back to the message SUBJECT. The stored
title of a real Moret-Loing-et-Orvanne flat was `2 nouvelles annonces : Ile-de-France`.

Found by querying production, not by any test:

```sql
SELECT title, COUNT(*) FROM listings WHERE source='seloger' GROUP BY title ORDER BY 2 DESC;
```

### What it cost — narrower than it first appeared

`Criteria::excludedBy()` holds two lists. `exclude_patterns` matches title **and** description, so
`colocation`, `coloc` and the meublé family kept firing through the description throughout.
`exclude_title_patterns` matches the TITLE ONLY, deliberately (`3 chambres` in a description is the
family flat the criteria want) — so what actually went unreachable is `^\s*chambre\b` and the
parking/box/garage/cave/cellier/bureau/terrain family. `^\s*chambre\b` is the exclusion added
*because* four of this source's first nine matches were coliving rooms passing every numeric filter.

> A first version of the regression test asserted the Saint-Denis colocation was now rejected. It
> passed before the fix as well as after — `\bcolocation\b` had been catching it via the description
> all along. A true observation attached to the wrong mechanism, produced while fixing an instance
> of exactly that. The replacement test holds the description constant and varies only the title.

### The replacement

Structural, anchored on the layout the portal emits — the same correction `commune_pattern` took on
this source. SeLoger writes `<rent> €/mois`, then the agency's free text, then `<n> pièces . <s> m²`;
the title is the line above the `pièces` line.

**Verified** [2026-08-26] over the same 72 cards: **27 rescued, 45 identical, 0 lost, 0 fallbacks
left.** The capture floor is 2 characters because `T5` and `T3` are real titles; `APARTMENT` is real
too, and no French vocabulary list would have held it.

### The half no fixture reaches

A configured pattern that misses now yields `''`. **The obvious sabotage does not detect that** — with
the positional pattern in place every frozen card extracts, the fallback branch is never entered, and
all six fixture suites stay green while the safety is deleted [measured 2026-08-26].
`EmailAlertSegmentationTest` enters it with a pattern that cannot match; both halves are in the
nightly ledger. A guarantee whose branch no fixture reaches is dead safety code until something
reaches it.

## Decisions Log (continued)

- [2026-08-26 09:40] AGREED: a listing title is read from its POSITION in the portal's layout, never from a vocabulary of dwelling nouns — the vocabulary form missed 27 of 72 live SeLoger cards and an agency writes the title, not the portal.
- [2026-08-26 09:45] AGREED: a CONFIGURED `title_pattern` that misses yields `''` and never the message subject; a source configuring NO pattern keeps subject semantics, because there the subject is the documented answer rather than a substitute for one.
- [2026-08-26 09:50] AGREED: `\bcoliving\b` joins `exclude_patterns`; the meublé patterns are NOT widened until the negation shapes (`non meublé`) are checked, per the lift-negation precedent.
- [2026-08-29 21:30] NOTED: **that check was done and the widening landed the same evening
  (`8357d9b`, 2026-08-26 21:12)** — `exclude_title_patterns` carries
  `^(?!.*\b(?:non|pas|sans)\b[^\n]{0,15}meuble).*\bmeuble`, a negative lookahead for the negation
  shapes. Measured on the production store on the 29th: 89 titles contain `meubl`, ONE reads
  `non meublé`, and all 89 are `REJECT` — including `MEUBLE - RUE WAGRAM` and `Beau 3P MEUBLÉ 59m²`,
  the two escapes this entry named. The entry above was read as open for three days after it
  closed; recorded so the next reader does not re-do the measurement.
- [2026-08-26 09:55] AGREED: after a source goes live, its stored titles are audited against production with a GROUP BY — this defect was invisible to the suite and visible in one query.
