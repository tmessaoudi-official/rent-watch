# Capturing an alert email — the runbook

> **Why this file exists.** Six saved-search alerts are live across two domains and **not one of
> their payloads has been read**. Every source in this project is built against a real message, never
> against a guess, and that is not caution — it is a measured price.

## The four times this repo paid for writing config blind

All four were behind a green test suite at the time.

| Source | What blind config actually cost |
|---|---|
| **SeLoger** | **Four MIME-parser defects in one day**, behind 1 886 passing tests. The parser returned `body len: 0, links: 0` — zero listings, no exception, a source that would have looked like a quiet market for ever |
| **Bien'ici** | Copying SeLoger's card separator would have mis-read **3 of 13 surfaces and 1 of 13 room counts**, all *under*-reported — so real matches silently rejected for being too small |
| **leboncoin** | The obvious separator matched nothing and the whole message parsed as **one card**: card 3's URL carrying card 1's rent, commune and surface. One plausible-looking listing, nothing reading as a fault |
| **PAP** | The surface reader returned **45** — the *search criteria floor* the portal quotes above the ad — instead of the flat's 50. The first PAP alert ever sent would have been rejected for being too small |

**A real payload is the input. A green suite is not a substitute for one.**

---

## What is owed, and what each capture has to ANSWER

A capture that answers an open question outranks one that confirms a path already known to work.
That is why Autohero is last: its polling payload was confirmed complete on 2026-08-27, so its
alert is a convenience rather than the route.

| # | Source | Domain | Status | The question this capture answers |
|---|---|---|---|---|
| 1 | ~~**Alcopa Auction**~~ | car | **EMAIL ROUTE REFUSED 2026-08-28** | **Does the alert carry a CLOSING TIME?** The lot page does not. Rule 2 of the auction ruling refuses a source that publishes none — so this capture decides whether the email route is admissible at all |
| 2 | **leboncoin — `Voitures : …`** | car | alert live, **NOT ROUTING** — 0 leboncoin messages among 52 in the label on 2026-08-29 | **Does the message carry all the cards its subject counts, or only the first few?** The subject said `58 nouveaux résultats`. This decides how much `card_separator` work it needs |
| 3 | **leboncoin — `… vous propose …`** | car | sender measured (`no.reply@leboncoin.fr`, n=2), `.eml` still in the INBOX | ~~Who sends it~~ **is it one ad per message?** The subject shape (n=2) says yes — `<seller> vous propose <title> à <price> € à <commune> (<CP>)` — so it is its own source block, PAP-shaped. The `.eml` confirms or refutes that |
| 4 | **ParuVendu** | rent | alert created 2026-08-27 | **Is the saved-search name in the subject?** If yes, the Gmail filter matches a discriminator you control and is self-tripwiring. If no, it falls back to the sender |
| 5 | **Agorastore** | car | alert live, **vehicles-only confirmed 2026-08-28** | **Is the feed all categories or vehicles only?** Decides whether an ingest-side category discriminator is needed at all |
| 6 | **Autohero** | car | **CAPTURED 2026-08-29** — two alerts, ONE car per message, the car named in the subject (*"Est-ce bien la MG ZS que vous cherchiez ?"*) | Answered by the capture: the alert is a single-vehicle suggestion, not a digest — structurally the PAP shape, and the only car alert with that shape. Polling stays the route; the alert is corpus |

**CORRECTED 2026-08-28 — La Centrale and AutoScout24 alerts DO exist.** Reading the mailbox found La
Centrale firing (`904 nouveaux véhicules correspondent à votre recherche`, with real prices) and
AutoScout24's saved search confirmed (no alert fired yet). Still not created:
CapCar (blocked on whether its make selector is multi-select), Interencheres (needs no
alert — its route is polling), Carizy (offers none, and is refused anyway).

---

## Part A — exporting a message you already have

**Do this on the desktop web client.** The mobile apps cannot export a raw message; if you only have
a phone to hand, forward the message *as an attachment* to yourself and export that on a desktop
later — a plain forward rewrites the headers and destroys exactly what we need.

1. Open the message in Gmail on the web
2. Top-right of the message, the **⋮** menu (not the one at the top of the window)
3. **Download message** — this saves a `.eml`
   *(If your interface shows only "Show original": click it, then* **Download Original** *on the page that opens.)*
4. The file lands in your Downloads folder, usually named after the subject

**One rule, and it is the whole corpus rule: never delete an alert email.** Until a parser exists the
mailbox *is* the corpus, and the awkward messages are the valuable ones — the message that reads
strangely is the one that finds the defect.

---

## Part B — scrubbing it

A raw alert contains your email address, often several times and often encoded. It must be scrubbed
before it can be committed as a fixture.

```bash
php tools/scrub-eml.php <in.eml> <out.eml> <your-subscriber-address>
```

The address argument is optional but pass it explicitly — it is what the tool searches for.

**If the tool REFUSES to write, that is it working, not failing.** It decodes every long base64url
run and the quoted-printable form *before* it looks, because *"the address is absent"* is the wrong
test: every Bien'ici link carries a JWT whose payload base64-decodes to your address, and an earlier
version of this tool reported `scrubbed` on a file the address was one `base64 -d` away from. If it
refuses, send me the message it printed — do not edit the file by hand.

---

## Part C — where to put it

Anywhere you like; tell me the path. `var/claude/` inside the repo is gitignored scratch and is the
natural place:

```bash
mkdir -p var/claude/captures
php tools/scrub-eml.php ~/Downloads/whatever.eml var/claude/captures/alcopa-01.eml
```

I will place the scrubbed file into `tests/fixtures/rent/<source>/` under the existing convention —
`YYYY-MM-DD-NNN-<short-slug>.eml`, e.g. `2026-08-26-002-meulan-en-yvelines.eml`. **Captures are
appended, never renumbered**: the number is an identity, and a renumbered fixture silently
invalidates every assertion that names it.

**Send these three things with it**, because two of them are gone once the file is scrubbed:

1. The **`From:` address**, exactly as shown
2. The **subject line**, exactly as shown
3. Roughly **how many listings you can see** in the message when you read it

The third is ground truth. Every fixture in this repo is asserted against a hand-read count, which
is what turns "the parser ran" into "the parser is right" — leboncoin's one-card bug produced a
perfectly plausible listing and only a hand-read count exposed it.

---

## Part D — alerts that do not exist yet

Five rules, each of which silently breaks the pipeline if missed. Rules 1, 2 and 5 fail *invisibly*:
nothing errors, the source simply looks like a quiet market.

1. **Create it on the WEB, never in the portal's mobile app.** An app alert delivers a push
   notification — it reaches your phone and never reaches the mailbox. The watcher then reports a
   calm market for ever. If the app is the only route to the setting, verify the email-delivery box
   is ticked.
2. **Set the frequency to the HIGHEST the portal offers** (AutoScout24 offers 1 h / 12 h / 24 h /
   7 d — take 1 h). A daily digest costs a full day of latency on a market where an underpriced car
   or a good flat is gone in hours. This project's premise is minutes.
3. **Give the search a DISTINCTIVE NAME** — `car-watch-lc`, `rent-watch-pv-idf`. If the portal puts
   the name in the subject, the Gmail filter can match a discriminator **you** control instead of a
   French phrase the portal may stop using — and a narrow filter means anything else from that
   sender lands in your inbox instead of being silently mis-routed. This started as a fallback for
   portals refusing `+tag` addresses; it is now the preferred mechanism.
4. **Never delete an alert email.** See Part A.
5. **Set the portal's filters WIDER than the criteria**, never tighter. The scorer can only rank what
   the portal already accepted; anything the portal rejects is invisible and nothing reports it.

| | Portal search | Our criteria |
|---|---|---|
| **Cars** — price | ≤ 30 000 € | ceiling 30 000, score rewards lower |
| **Cars** — age | ≤ **7** years | score *peaks* at ≤ 5 |
| **Cars** — mileage | ≤ **100 000** km | score *peaks* at ≤ 80 000 |
| **Cars** — fuel / gearbox / body | **leave unset** | score components (decision 11) |
| **Rent** — rooms / surface / rent | 3 / 45 m² / 1 300 € | 3 / 50 m² / 1 200 € CC |
| **Both** — geography | Île-de-France | 8 departement prefixes |

---

## Checklist

- [x] **Alcopa** — captured and read 2026-08-28. **It does NOT carry a closing time**, nor a price, nor a per-lot link, and it shows 3 of 108 results. **Email route REFUSED** under rule 2 of the auction ruling. ~~Polling route still UNRESOLVED~~ **Polling route REFUSED too, 2026-08-29** — the sale pages and the calendar are JavaScript shells; nothing server-rendered carries a price or a closing time (`docs/plans/scout-rename-and-car-domain.plan.md`, 22:00 entry)
- [x] **Alcopa** — calendar reminder ~24/09 to renew the alert before it expires on 27/09 — **set 2026-08-28**
- [ ] **leboncoin `Voitures`** — capture; how many cards for a subject counting 58?
- [ ] **leboncoin `vous propose`** — `From:` header first, then capture
- [x] **ParuVendu** — read 2026-08-28. **The search name is NOT in the subject** — it carries the CRITERIA instead (`🚗 25 nouvelles annonces - Voiture d'occasion / Jusqu'à 30 000 € / A partir de 2019 / Jusqu'à 100 000 km`), so the filter falls back to the sender `info@paruvendu.fr` — which serves the RENT alert too, and the rent alert is currently landing in the car label because of it
- [x] **Agorastore** — captured and read 2026-08-28. **Vehicles only** (`Votre recherche : Voiture`), so no scoping work is needed. But zero prices and zero closing times — same rule-2 refusal as Alcopa — but for the EMAIL ROUTE ONLY: its API host api.auctelia.com is open, so a hydration route could still supply the closing time. It does carry real lot references
- [x] **Autohero** — captured 2026-08-29 (n=2): one car per message, subject names it. Raw in `var/claude/captures/car-watch-portails/`, not yet scrubbed into `tests/fixtures/`
- [ ] **ParuVendu RENT → car label** — still misrouted on 2026-08-29 (07:30 on the 28th AND the 29th, n=3). The mechanism is ruled (filter on the saved-search NAME, which the subject does not carry — so on the RENT subject `🏠 Nouvelles annonces location` instead); the Gmail filter is an operator action and is not written
- [ ] **AutoScout24** — no alert has fired as of 2026-08-29; only the confirmation (27 Aug) and a newsletter from a different sender (`mails.autoscout24.fr`). Worth one look at the saved search's frequency setting (Part D rule 2)
- [ ] **CapCar** — is the make selector multi-select? (browser only; robots disallows the path)
- [x] **La Centrale** — read 2026-08-28. Richest fields of any car alert (price, mileage, seller type, departement) but **3 cards for a stated 904**, one opaque link host shared with the furniture, an ellipsised title, and no commune. Polling is refused by ruling, so there is no second route
- [ ] Confirm the two leboncoin filters exist as **filters**, not just labels — **PARTLY ANSWERED 2026-08-28: no leboncoin car alert is in `car-watch/portails` over 30 days, so the `Voitures` filter is not routing there.** Remaining candidates are the INBOX and two personal folders, which were not read
- [ ] Verify every alert against Part D rules 1, 2 and 4
