# `scout` rename + the car domain

> **Written 2026-08-26 as a RECOVERY.** The session that asked these two questions and received
> both answers stalled immediately after printing `── Phase 5: PERSIST THE RULINGS ──`, so the
> rulings existed only inside a dead transcript. The global session-remember pipeline failed the
> same minute (`Haiku call failed after 3 attempts`, exit 141, 22:41), leaving `buffer.md`,
> `today.md` and `handoff.md` all **empty** — so the usual handoff carried nothing either. This
> file is the durable record the stalled session was one tool call away from writing.
>
> **Provenance, because this repo has been bitten by an unratified `AGREED` before**
> ([[resuming-a-stalled-session-check-agreed-timestamps]]): both entries below are quoted verbatim
> from `AskUserQuestion` **tool results** in transcript `53d224a9-5c89-493f-90c3-98daee5a7e60`,
> at 20:35:50 and 20:40:34 respectively. They are developer answers, not a stuck session logging
> its own preference as a ruling.

## Scope — what is ruled, and what is explicitly NOT

The developer's own words, in the free-text half of the first answer:

> *"Okay for option 1 ! i agree now what should we rename the repo ? **and don't implement yet !**
> just give me everything you need from me to build everything ? and are there other good sources
> to buy used cars ?"*

So: **the DIRECTION is ruled and the NAME is ruled. Execution is not.** Nothing is renamed,
nothing is refactored, no `Vehicle*` module is created, and no `RENT_WATCH_*` variable is touched
until a separate go. A ruling on what a thing will be called is not a ruling that it be done now.

**`STATUS: Designed — not yet implemented.`**

## Decisions Log

- [2026-08-26 20:35] AGREED (developer, `AskUserQuestion` result, recovered from transcript `53d224a9`): the car watcher is built as a **second domain inside this repo** — `Vehicle*` modules beside the housing ones, its own SQLite file and its own config, with the generic machinery shared **byte-identical**. The recommendation given was that a separate repo duplicates ~6 000 lines against the developer's own no-duplication ruling, and that a shared Composer package is hostile in this environment specifically: the container 403s on `codeload.github.com`, which is why this project has zero runtime dependencies at all.
- [2026-08-26 20:35] NOTED, with the measurement that made the recommendation honest: the tree splits **5 967 lines of generic machinery** (`Adapters/Http`, `Adapters/Mail`, `Adapters/Html`, `PacedSource`, `Source`, `SourceError`, `FixtureSource`, `Core/Pacer`, …) against **5 082 domain-bound** and **9 166 mixed** (`Store`, `Cli`, `ConfigLoader`, `HtmlSource`, `HttpJsonSource`, `EmailAlertSource`, `Formatter`). The mixed column is the real cost of a second domain, and it is larger than the generic one — sizing that assumed "share the generic half" would understate the work by roughly half.
- [2026-08-26 20:40] AGREED (developer, `AskUserQuestion` result, same transcript): the repo is renamed to **`scout`** — GitHub name, local directory, PHP namespace `RentWatch\` → `Scout\`, env prefix `RENT_WATCH_*` → `SCOUT_*`. The recommendation was `scout` because **the name already exists in this codebase and is already domain-neutral**: the binary is `bin/scout`, the class is `Cli\Scout`, every verb is `scout run` / `scout doctor` / `scout digest`. Every alternative (`vigie`, `annonces`) would have introduced a *third* identity on top of repo ≠ CLI ≠ namespace; this one collapses them to a single word.
- [2026-08-26 20:40] NOTED: the rename splits into three separable pieces, measured rather than estimated — **199 `rent-watch` literals across 51 files** (mostly prose; GitHub redirects the old URL so nothing breaks), **717 `RentWatch\` occurrences in 140 PHP files** (mechanical `sed` + `composer dump-autoload --dev` + full suite — it either compiles or it does not), and **101 `RENT_WATCH_*` occurrences that are only 4 distinct names**: `_DB`, `_OFFLINE`, `_MAX_PASSES`, `_BACKUP_KEEP`.
- [2026-08-26 20:40] NOTED — **the env rename is the only piece that can hurt, and the guard already exists.** If `RENT_WATCH_DB` is renamed and the *deployed* `.env` on the host is not updated in the same breath, the default takes over, a brand-new empty database is created, and the watcher re-notifies the entire market. That is exactly what **Q36's flood guard** prevents: `scout run` refuses to notify while `isSeenSetEmpty()` is true, and since 2026-08-19 it reads the ROWS rather than the file, so an earlier `doctor` cannot disarm it. The failure mode is therefore a loud refusal, not 200 pushes. Recommended sequencing: the prose half whenever; **the namespace and env rename in the same change as the car-domain refactor**, which touches the tree anyway — one test run instead of two.
- [2026-08-26 23:05] NOTED: item 1 (AL'in) parked — the developer has an `al-in.fr` account but no NUR/NUD, and obtaining one runs through `demande-logement-social.gouv.fr`, which hard rule 5 puts out of scope. Raises an UNMEASURED §1 question: a NUR requirement is the tier-3 procedural tell for social housing, so AL'in's gated stock may be out of scope rather than merely unreachable.
- [2026-08-26 23:20] AGREED: every domain's alerts are tagged on the RECIPIENT — `<you>+<label-root>@gmail.com`, so `+car` / `+rent` — and a Gmail filter on `To:` routes each to its own label. NO domain is left untagged: a catch-all working domain must be edited every time a domain is added, and the catch-all must be the ERROR bucket instead. Developer's reasoning, and it overrode the "don't touch five live sources" objection.
- [2026-08-26 23:20] AGREED: the `watch/UNROUTED` tripwire is added LAST, after every rent saved search is re-tagged and verified. Added first it matches every still-untagged rent alert and takes the live rent sources to zero.
- [2026-08-26 23:20] AGREED: the car domain runs as its OWN DEPLOYMENT — own `.env`, `IMAP_MAILBOX`, SQLite and ntfy topic, plus a `[car-watch]` notification prefix. `IMAP_MAILBOX` is global per deployment, so two deployments buy folder isolation with zero code change.
- [2026-08-26 23:40] AGREED: car budget 0–30 000 € on the DISPLAYED price; the ceiling is wide on purpose and the score does the discriminating (*"i won't really pay 30 000 now ! but i want to be informed"*).
- [2026-08-26 23:40] AGREED: car geography is Île-de-France for now and MUST be settable to any set — national, one departement, several. Reuses the rent side's `postcode_prefixes` region mode verbatim; no second geography mechanism.
- [2026-08-26 23:40] AGREED: age ≤ 5 years and mileage ≤ 80 000 km are a SCORE PEAK, not disqualifiers, and the PORTAL saved search is set WIDER (≤ 7 y, ≤ 100 000 km) so near-misses reach the scorer. Hard rule 8, and the Q5 precedent that removed `max_floor`.
- [2026-08-26 23:40] AGREED: both private and professional sellers, as a displayed fact and a score component — the analog of `family: institutional | private`.

## What is still owed BY the developer

Recorded here rather than re-asked, because the ask was already made once and answering it is not
this file's job. Nothing in the car domain can start without items 2–4.

### Inputs — no default can invent these

| # | Input | Why it cannot be defaulted |
|---|---|---|
| 1 | **The AL'in DevTools cURL capture** (authenticated) | Unchanged, and unrelated to cars. Hard rule 1 forbids writing an endpoint from memory; AL'in is the ONLY route to the Action Logement ESH stock (A5, A6, A8 and A14 all dead-end there) |
| 2 | **A separate mailbox — or alias — for car alerts** | See § "The immediate hazard" below. This is not tidiness; without it a car alert is ingested by the *rent* source. Credentials go straight into `.env`, never pasted into chat |
| 3 | **Saved searches created** on leboncoin, La Centrale and AutoScout24 with the real criteria | The alert cannot arrive until the search exists |
| 4 | **One real alert email from each**, run through `php tools/scrub-eml.php` | **This is the gate.** The email parser was written blind here and cost four defects the day a real message first reached it, behind 1 886 green tests. Not repeating that |

> **Item 1 is PARKED as of 2026-08-26, and the reason is itself a §1 question.** The developer has
> an `al-in.fr` account but **no NUR/NUD** — the *numéro unique d'enregistrement* — and obtaining one
> runs through `demande-logement-social.gouv.fr`, which hard rule 5 places **out of scope entirely**.
> They will do it, but not now.
>
> **That gate is not incidental.** This repo's own glossary lists `numéro unique d'enregistrement`,
> `SNE` and `commission d'attribution` as the **tier-3 procedural tell for SOCIAL housing**, and says
> LLI is allocated *"directly by the landlord — no commission, no SNE number"*. So if a NUR is what
> unlocks AL'in's listings, what sits behind it is precisely the commission-allocated stock §1
> excludes, and the capture would buy little.
>
> **[Inferred, not measured.]** Nobody has checked whether AL'in shows anything without a NUR, or
> whether its intermediate stock sits on a separate unauthenticated surface. **Measure that before
> spending the procedure on it** — one logged-in look at what the existing account can already see,
> and whether any of it is labelled *intermédiaire* / LLI. If the answer is "nothing without a NUR",
> then A4 joins A5/A6/A8/A14 as a §1 dead end rather than a blocked input, and Track 1 closes.

### Open decisions — each has a proposed default, per the repo's open-questions convention

| # | Question | Default if unanswered |
|---|---|---|
| 5 | Budget ceiling — and is it the **displayed price** or price + delivery/fees? | Displayed price; a delivered-seller uplift is unverifiable per-source, same reasoning as Logirep's `charges_included: false` |
| 6 | Geography — radius from where, or **national**? | National. It becomes the reasonable default the moment delivered sellers (Autohero, Carizy, CapCar) are in scope, and geography stops discriminating |
| 7 | Hard filters — max mileage, min year, fuel, gearbox, body type, brand allow/deny | Mileage and year as **score components**, not disqualifiers (hard rule 8, and the Q5 precedent that killed `max_floor`) |
| 8 | Private sellers, professionals, or **both**? | Both. It is the exact analog of `family: institutional \| private`, which already exists and already carries both |
| 9 | **The car §1 exclusion set** — confirm it | Proposed fail-closed set below. **The one genuinely open call is `accidenté réparé`** — legal to sell, often good value, but a different risk appetite. Defaulting it **OUT** (fail-closed) until ruled |
| 10 | Are **auctions** in scope at all? | Out, for now. An Alcopa or Interencheres lot needs a deposit and often a physical viewing — a notification about a lot asks something very different of the reader than a listing does |

> **Decisions 5–8 are RULED as of 2026-08-26** — see § "Car criteria" below. Rows 9 and 10 remain
> open and keep their defaults. The table above is left intact as the record of what was proposed.

## THE IMMEDIATE HAZARD — before any car alert is created

`EmailAlertSource` scopes on **`params.from` + `params.link_host` only**. There is no subject
filter and no category filter [Verified: grep of `EmailAlertSource.php`, 2026-08-26].

The live **rent** source `leboncoin` carries:

- `from: no.reply@leboncoin.fr`
- `link_host: leboncoin.fr/vi/` ← **category-agnostic**; `/vi/<id>.htm` is leboncoin's universal ad path
- `card_separator: "Voir l'annonce"` ← also category-agnostic

So a leboncoin **car** saved-search alert would very likely be ingested by the **rent** source:
parsed into listings, counted in its `SourceHealth`, and eating the shared `IMAP_MAX_MESSAGES`
window — the exact shared-budget failure that took SeLoger from 9 listings to 0 on 2026-08-25.
The link-host and separator collisions are [Verified]; whether the sender address is identical is
[Unverified]. **A separate mailbox or alias closes it at the source; a `from` filter alone does
not.**

## Mailbox routing — RULED 2026-08-26, and it resolves the hazard above

**Every domain is tagged on the RECIPIENT**, none is left as a catch-all. The address is
`<you>+<label-root>@gmail.com` — `+car`, `+rent`, later `+job` — which is **not a new account**:
Gmail delivers `you+anything@gmail.com` to `you@gmail.com` and preserves the tag in the `To:` header,
so a filter can match it. Each tag routes to its own label, and Gmail labels are IMAP folders:

| Filter | Match | Action |
|---|---|---|
| 1 | `To: <you>+car@…` | → `car-watch/portails`, skip inbox |
| 2 | `To: <you>+rent@…` | → `rent-watch/portails`, skip inbox |
| 3 | `From:` any known portal **AND NOT** any `+tag` | → `watch/UNROUTED`, **keep in inbox** |

**Why the recipient and not the sender** — the sender cannot discriminate. leboncoin sends the rent
alert and the car alert from the same `no.reply@leboncoin.fr` through the same saved-search system
[Inferred: same system; no leboncoin car alert has been seen here], and `From:` is exactly what the
live rent sources already match on. The recipient tag is the only difference the developer controls.

**Why NO domain is untagged** — developer's argument, and it beats the "don't touch five live
sources" one: a catch-all working domain must be EDITED every time a domain is added
(`AND NOT +car AND NOT +job …`), and the day that edit is forgotten the new domain's alerts land
silently in the old one. Adding a domain must be purely additive. **The catch-all is the ERROR
bucket, never a working domain.**

**THE MIGRATION ORDER IS THE WHOLE SAFETY.** Filter 3 goes LAST. Added while the rent searches are
still untagged it matches every rent alert, and the live rent sources go to zero — silently, the
SeLoger 9 → 0 shape of 2026-08-25.

1. Add filter 1. Harmless today; nothing sends there yet.
2. Add filter 2. Also harmless; nothing carries that tag yet.
3. **Leave the existing catch-all rent filter in place.** Both filters point at the same label, so a
   portal is covered whether tagged or not, for the whole migration.
4. Re-register the rent saved searches as `+rent`, **one portal at a time**, running
   `scout doctor --source=<name>` after each and confirming the count has not dropped.
5. Only once all are tagged and verified: retire the catch-all, then add filter 3.

Steps 1–2 unblock item 3. Steps 4–5 can wait indefinitely; nothing in the car domain depends on them.

### The car domain runs as its OWN DEPLOYMENT

Own `.env`, own `IMAP_MAILBOX=car-watch/portails`, own SQLite, own ntfy topic, and a `[car-watch]`
prefix on every notification. **`IMAP_MAILBOX` is global per deployment** — one key, no per-source
override [Verified 2026-08-26: `.env.example:73`, and no `mailbox` param in `EmailAlertSource`] — so
two deployments give folder isolation with **zero code change**, where one deployment would require
a new per-source `params.mailbox`. This is also what the ruled architecture already says: second
domain, own SQLite + config.

**The separate ntfy topic is not cosmetic.** Both watchers emit the Q27 daily heartbeat; into one
topic you cannot tell WHICH one died, and distinguishing a dead watcher from a quiet market is that
signal's entire job. The `[car-watch]` prefix is then mildly redundant with a separate topic, and is
kept anyway because two topics interleave in one phone notification shade.

⚠️ **On ntfy.sh the topic name IS the access control.** Anyone who guesses it can read the listings
and publish to the device. Long and random, in `.env`, never in a commit and never in chat.

## The car-domain §1 analog — proposed, fail-closed

Vehicles that cannot legally or safely transfer, using the official vocabulary:
`VEI` (économiquement irréparable), `VGE` (gravement endommagé) / *procédure VE*,
`gagé` / `opposition`, `pour pièces`, `sans carte grise`, `CT non fourni` / non roulant.
[Verified: sécurité routière + Ministère de l'Intérieur descriptions of the VE/VGE procedure]

**HistoVec** (`histovec.interieur.gouv.fr`, Ministère de l'Intérieur, free) is the official record
— successive owners, gage/opposition/vol, CT dates **with recorded mileage since January 2021**,
VEI/VGE procedures. **But the report is generated by the OWNER** from the plate plus the
registration document, neither of which a listing carries. So it is **contact-time enrichment,
never ingest-time classification**: it belongs in the notification as *what to ask the seller for*.
Ingest must classify on text signals, exactly as tenure does — which is why the set above is a
classifier vocabulary and not a lookup.

## Source catalogue — `robots.txt` fetched 2026-08-26, honest UA, wildcard group only

Kept here rather than in `var/claude/` because that path is gitignored scratch. Each row cost one
fetch; do not re-derive them.

### Open to polling

| Host | HTTP | Wildcard group | Verdict |
|---|---|---|---|
| `www.autohero.com` | 200 | disallows only `/myhero/`, `/inspection/`, `/checkout/`, `/identify`, `/center`, `/unsubscribe/` | **OPEN.** Publishes `sitemap.xml` per locale **and `sitemap_search.xml`**. Best polling candidate measured. Fixed price, delivered, national — geography stops mattering |
| `www.agorastore.fr` | 200 | `Allow: *` | **WIDE OPEN.** Public-sector and fleet disposals, open to individuals. Low volume, low competition |
| `www.alcopa-auction.fr` | 200 | `Disallow: /*.pdf$`, `/calendrier/` | **OPEN.** 105 000 vehicles/year, 7 rooms, public auctions |
| `www.leparking.fr` | 200 | only `/tools/`, `/extlink/`, `/tag/` | **WIDE OPEN**, and a **meta-search** across many portals — breadth in one adapter. ⚠️ It is an AGGREGATOR, so the Jinka caveat applies in full: a truncated description can lose a `VEI` the original ad carried. Needs its own §1 evaluation before it is trusted |

### Refused, with the reason

| Host | HTTP | Verdict |
|---|---|---|
| `www.lacentrale.fr` | **403** | **REFUSED BY RULING.** Body is a **DataDome CAPTCHA challenge** (`captcha-delivery.com`) — hard rule 5 refuses solving it, same class as A15 Val d'Oise Habitat. Also fail-closed under this repo's posture (403 = blocked). Email-alert route only |
| `www.spoticar.fr` | **403** | Akamai-style `Access Denied` + reference id. **Fails closed.** Stellantis' 80 000-car network — alert route only, if any |
| `www.encheres-domaine.gouv.fr` | 200 | `robots.txt` is a **JS redirect requiring cookies**. The 2026-08-25 rule already refuses this: a 2xx whose body starts `<` is not a robots file |
| `www.capcar.fr` | 200 | `Disallow: /trouver-une-voiture/*` — the search itself |
| `www.leboncoin.fr` | 200 | **No `User-agent: *` group at all** (verified across the whole file). Moot — already email-only here (403s a plain client). Named AI groups carry `Disallow: /recherche`, `/ad/` |
| `www.autoscout24.fr` | 200 | `Disallow: /lst?`, `/lst/?`, `/listing-search-api/graphql` — **search-with-query refused**. Separately: `ClaudeBot`, `GPTBot`, `CCBot`, `Google-Extended` get `Disallow: /`, a stated position on automated agents |

### Partly refused — the listing path is out, another may not be. UNMEASURED

| Host | Disallowed | What is left |
|---|---|---|
| `www.interencheres.com` | `/recherche/*` | The category *ventes* pages sit outside it. #1 French auction portal. Sitemap published |
| `www.autosphere.fr` | `/occasions`, `*recherchecourte*` | `/recherche` is not disallowed. Emil Frey France network |
| `www.paruvendu.fr` | `/auto-moto/rechercheautofo/`, `/auto-moto/listefo/`, `/auto-moto/annonceautofo/` | Those are NAMED legacy front-office paths; whether the CURRENT search route falls under them is unmeasured |
| `www.vpauto.fr` | long named-bot blocklist | Wildcard group not isolated; needs a second read |

### Email alerts — the preferred route (hard rule 4)

- **leboncoin** — saved searches with email alerts, up to 50 [Verified: portal help centre]
- **La Centrale** — *"Créer une alerte nouvelles annonces"* on a saved search [Verified: FAQ]
- **AutoScout24** — saved search + alert at chosen intervals (1 h / 12 h / 24 h / 7 d), **including price drops** [Verified: FAQ] — price drops land straight in the existing `price_history` machinery
- **Alcopa Auction** — *"être alerté"* on matching vehicles [Inferred: third-party guides, not confirmed on the portal itself]
- Autohero / Aramisauto / VPauto / Agorastore / BCAuto — UNMEASURED

### Dead rows — dated, so nobody re-proposes them

- **Reezocar** — ceased sales **4 November 2024**. Was the German/Belgian import route with the paperwork handled [Verified: multiple 2026 write-ups]
- **Ayvens Carmarket** — **professionals only**, out of scope for a private buyer

### Alive, deliberately unmeasured

Carizy (C2C, Renault-backed), Renault/Dacia Occasions, Toyota Occasions, Das WeltAuto, VPauto,
BCAuto Enchères (pro-oriented), encheres-vo.com, Ouest-France Auto, Caradisiac Occasions, L'Argus,
mobile.de + AutoScout24.de (import), Aramisauto.

## The standing read on where the value is

The **email-alert route** (leboncoin, La Centrale, AutoScout24) covers the overwhelming majority of
the French private market with **zero anti-bot exposure** — which is hard rule 4's whole argument,
arriving intact in a second domain. Polling is worth it for the three genuinely open ones,
especially **Agorastore** and **Alcopa**, because they carry stock the big portals never see.

## Car criteria — RULED 2026-08-26

| # | Question | Ruling |
|---|---|---|
| 5 | Budget | **0 – 30 000 €**, on the DISPLAYED price. Developer, verbatim: *"i won't really pay 30 000 now ! but i want to be informed ! and the criterias can be changed anyway"* — so the ceiling is wide and the SCORE does the discriminating |
| 6 | Geography | **Île-de-France for now**, and it must be **settable to any set** — national, one departement, several. Reuses the rent side's `postcode_prefixes` verbatim (region mode, Q1/Q2); `commune_rank`'s soft-preference shape is available on top. **Do not invent a second geography mechanism** |
| 7 | Age / mileage | **≤ 5 years, ≤ 80 000 km — as a SCORE PEAK, not a disqualifier.** See the two-layer rule below |
| 8 | Seller type | **Both private and professional.** The exact analog of `family: institutional \| private`, which already exists and already carries both. It becomes a displayed fact and a score component, never a filter |

### THE TWO-LAYER FILTER — the portal's is COARSE and deliberately WIDER

A portal's saved-search form can only express hard limits; our criteria are the fine instrument. So
the two layers are set to DIFFERENT values on purpose, and the portal's is the looser one:

| | Portal saved search | Our criteria |
|---|---|---|
| Price | ≤ 30 000 € | ceiling 30 000 €, score rewards lower |
| Age | ≤ **7** years | score **peaks at ≤ 5 years**, decays after |
| Mileage | ≤ **100 000** km | score **peaks at ≤ 80 000**, decays after |

**Why not filter tight at the portal:** our scorer can only ever rank what the portal already
accepted. Tighten the portal to 5 y / 80 000 km and the near-misses never arrive, at which point the
score peak is decorative. **Why not reject hard on our side either:** hard rule 8, and the Q5
precedent that removed `max_floor` from the rent side entirely — a 5-year-3-month car at 62 000 km
inside budget would be discarded with no trace, and silent over-rejection is invisible by
definition, because nothing arrives to notice.

**[Proposal, not a measurement]** The 7 y / 100 000 km widening is a judgement about how far past the
peak a near-miss is still worth reading. Nobody has measured the alert volume it produces. If the
first week is noisy, the portal filter is one form field away from tightening — and tightening it is
reversible in a way that a listing never sent is not.

