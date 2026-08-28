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
- [2026-08-26 23:55] AGREED: the car §1 fail-closed set is confirmed — VEI, VGE/procédure VE, gagé/opposition, pour pièces, sans carte grise, CT non fourni/non roulant — and `accidenté réparé` is EXCLUDED with it. That last one is a risk-appetite ruling rather than a safety fact, and is the first line to relax if the yield disappoints.
- [2026-08-26 23:55] AGREED: auctions are OUT of scope for now. Stated cost: most of the measured-OPEN hosts are auction sites, while the retail portals are the ones refusing a polling client.
- [2026-08-27 00:15] AGREED: decision 11 — automatic gearbox is PREFERRED, petrol/hybrid is PREFERRED over diesel, and body type is a RANKED preference `suv > break > berline`. All three are SCORE components, never portal filters and never disqualifiers: the two-layer rule from decision 7, and hard rule 8. The three fields are left UNSET on the portal saved search, so the searches specified for item 3 are unchanged and do not need re-creating.
- [2026-08-27 00:15] NOTED: the body preference is a RANK, not a set — the exact shape `commune_rank` already has on the rent side. An unranked body (citadine, monospace, coupé, cabriolet) scores 0 on that component and is still notified. Do not reimplement ranking; reuse it.
- [2026-08-27 00:15] OWED, and deliberately not answered from memory: the diesel penalty's SIZE depends on the Grand Paris ZFE / Crit'Air rules in force, which have changed repeatedly and are [Unverified] here. Hard rule 1's spirit — verify against a live authoritative source before the number is written. Until then the penalty is a plain preference, not a regulatory one.
- [2026-08-27 01:05] AGREED (developer, verbatim: *"i think i want to add auctions now"*): **decision 10 is REVERSED — auctions are IN.** Decision 10 said *revisit once the email-alert sources are proven*; this arrives earlier, which is the developer's call and is recorded rather than re-litigated. It is also the decision that makes the car domain's FIRST polling adapter possible: three of the four hosts measured genuinely OPEN are auction sites, while every retail portal recommended so far is email-only.
- [2026-08-27 01:05] AGREED: a LOT is not a LISTING, and four rules travel with it — the price is a MOVING BID so the ceiling applies to the estimate/reserve when published and to the current bid otherwise, and a lot that later crosses the ceiling is RECORDED but never re-notified (a rising bid is the price-RISE fact, not a drop); a CLOSING TIME is mandatory in the notification and a source publishing none is refused fail-closed; the §1 vehicle set will reject a large share of auction stock and that is CORRECT; deposit and viewing requirements are STATED in the notification, never used to filter.
- [2026-08-27 01:05] NOTED: **pro-only auction houses stay OUT, and that is a fact rather than a preference** — Ayvens Carmarket, BCAuto Enchères, Openlane, Autorola and Auto1.com sell to registered traders only, so a private buyer cannot bid at all. Same class as the existing Ayvens row.
- [2026-08-27 01:05] AGREED: **Autohero is IN as tier 2**, and is the source that proves the HTTP path — the best polling candidate measured (`sitemap_search.xml`, robots open). Stated cost: it is a RESELLER, so no private sellers, prices above private sale, and because it delivers nationally at a fixed price the decision-6 geography filter is INERT on it — do not read a region match as evidence the filter works.
- [2026-08-27 01:05] OWED (new input, ~2 minutes): **does CapCar offer an email alert on a saved search?** Its polling route is refused (`Disallow: /trouver-une-voiture/*` is the search itself), so an alert is the only way in. It is C2C with a professional intermediary — inspected, paperwork handled, private seller — which is inventory the pro portals do not carry.
- [2026-08-27 01:40] AGREED: the rename is **HELD, and done in ONE piece** when there is budget to verify it. It is purely cosmetic, nothing depends on it, and splitting it is what makes it dangerous — see the two hazards below.
- [2026-08-27 01:40] NOTED, and this one was missed until now: **renaming the LOCAL DIRECTORY silently orphans the Claude memory.** `/stack/projects/rent-watch` → `/stack/projects/scout` changes the derived project slug, so `~/.claude/projects/-stack-projects-rent-watch/` (holding `MEMORY.md` and every session handoff) stops matching and the next session reads as though it had none. `~/.claude/projects/<slug>/` must be moved in the SAME change as the directory, and the move verified before the session ends. Nothing warns about this: the failure is an empty memory, which looks exactly like a fresh project.
- [2026-08-27 01:40] NOTED: the ENV prefix is the other piece that must not travel alone — the deployed `.env` on the HOST is not in git, so `RENT_WATCH_DB` → `SCOUT_DB` must be applied there in the same breath or the live watcher refuses to notify (Q36's flood guard catches it as a loud refusal rather than 200 pushes, which is the safe failure — but it is still a stopped watcher).
- [2026-08-27 01:40] AGREED (developer, `AskUserQuestion`): the remaining weekly budget is **HELD for the first real car alert email**, rather than spent on the rename or on config written blind. Rationale accepted: a real payload is the documented gate here — the email parser was written blind once and cost four defects the day a real message reached it, behind 1 886 green tests.
- [2026-08-27 02:10] AGREED — **THE `+tag` SCHEME OF 2026-08-27 00:20 IS REVERSED. Routing is on the SENDER.** Developer's argument, and it beats the one it replaces: *"the only collision is leboncoin ... all others have no collisions"*. Measured true — La Centrale and AutoScout24 never send rent alerts, SeLoger/Bien'ici/PAP never send car alerts, so a `From:` filter separates every source but one at zero cost and with no new accounts. The tag scheme imposed a per-portal signup cost on 100% of sources to solve a problem that exists on one.
- [2026-08-27 02:10] AGREED: **a generalist marketplace is the EXCEPTION and gets an explicit split.** leboncoin is ambiguous because `link_host: leboncoin.fr/vi/` and `card_separator: "Voir l'annonce"` are both category-agnostic [Verified 2026-08-26], so its car alert would be ingested by the rent source. It is split on the SUBJECT — and the subject is read from a REAL message before the filter is written, never guessed. The rent subject is known (`3 nouveaux biens à louer à Ile-de-France`); the car one is unseen, so the leboncoin car search is created LAST and the filter written against the first real alert.
- [2026-08-27 02:10] NOTED: the `watch/UNROUTED` tripwire SURVIVES the reversal and is what keeps a subject filter honest — scoped to the ambiguous sender rather than globally. A leboncoin message matching NEITHER subject pattern lands in the inbox, so a changed template is LOUD instead of silently zeroing a source (the SeLoger 9 → 0 shape). Gmail applies every matching filter rather than stopping at the first, so the tripwire is expressed as a negated search in the *Has the words* box, not as filter ordering.
- [2026-08-27 02:10] NOTED, stated cost: between the leboncoin car alert being enabled and its split filter existing, the RENT source may ingest one car ad and push a bogus notification. One message, recoverable; stopping the watcher for ten minutes avoids it. Accepted rather than designed around — architecting for a single known message is the over-correction.
- [2026-08-27 02:35] MEASURED (developer, from a real message): the leboncoin CAR alert subject is `Voitures : 58 nouveaux résultats`. The rent one is `3 nouveaux biens à louer à Ile-de-France`. So the split is TWO POSITIVE subject matches — `Voitures` and `à louer` — not a negation. A negation would also have caught account notices and marketing, which carry no `/vi/` links and would have read as a quiet source.
- [2026-08-27 02:35] NOTED — **the UNROUTED tripwire needs no filter at all, and that is better than the one it replaces.** Both leboncoin filters skip the inbox, so any message from that sender matching NEITHER subject lands in the inbox BY DEFAULT. A changed template therefore surfaces where the developer will see it, rather than vanishing into a folder nobody reads — the SeLoger 9 → 0 shape, made loud for free. The negated-search filter of 02:10 is withdrawn as unnecessary.
- [2026-08-27 02:35] OPEN, for the first `.eml`: the subject counts **58** results. Whether the message CARRIES 58 cards or only the first few is unknown, and it decides how much `card_separator` work leboncoin's car alert needs. Do not assume either — read it off the capture.
- [2026-08-27 19:46] MEASURED (developer, operator report): alerts **CREATED** on Autohero, Alcopa Auction, Agorastore and leboncoin (cars). **NOT created** on Interencheres (its car filter is too coarse to be worth one), CapCar (the form requires selecting a MAKE) and Carizy (no alert facility exists at all).
- [2026-08-27 19:46] MEASURED: **the Alcopa alert EXPIRES — valid to 27/09/2026, one month.** That is hard rule 2's shape introduced by the PORTAL rather than by the code: the source falls silent and the silence is indistinguishable from a quiet saleroom. Renewal is an operator task with a dated reminder (~24/09, three days of slack); `SourceHealth` is a lagging backstop at best — it needs a non-zero baseline and three empty passes — and the car domain is not built, so today there is **no backstop at all**.
- [2026-08-27 19:46] PROPOSED, not ruled: a source whose alert expires carries that date in **its own config block**, and `doctor` prints it. It would be the first `expires` field in this project. Written down now because the alternative is a date that lives only in a calendar the code cannot read.
- [2026-08-27 19:46] MEASURED, and it is the day's most important row: **leboncoin sends a THIRD subject template** — `TRANSAKAUTO MEAUX vous propose HYUNDAI i20 ACTIVE 1.0 T-GDi 100 DCT-7 Active à 11 490 € à Villenoy (77124)` — matching neither `Voitures` nor `à louer`. It is therefore routed by nothing and lands in the inbox. **The sender is [Unverified] and no filter is written until it is read** (02:35's own rule: the filter is written against a real message, never guessed). IF the sender is `no.reply@leboncoin.fr`, this is the 02:35 tripwire's **first live confirmation**, behaving exactly as designed — an unknown template surfacing where the developer sees it instead of vanishing into a folder nobody reads.
- [2026-08-27 19:46] NOTED: the routing fix is CONDITIONAL and the condition is one header. **A different sender → a `From:` filter**, which is the 02:10 sender-routing ruling with nothing added, and the better outcome. **The same sender → a third POSITIVE subject match** (`vous propose`), never a negation — 02:35's reason holds: a negation also catches account notices and marketing, which carry no listing links and would read as a quiet source.
- [2026-08-27 19:46] NOTED, and it is the standing weakness of subject routing: **a subject filter IS a vocabulary**, the exact shape *"a title is a position, never a vocabulary"* warns about, and it will need extending every time the portal invents a template. That is tolerable ONLY because the default is the INBOX. If a FOURTH template appears, stop extending the list and move leboncoin to a structural discriminator (a dedicated `+car` alias for that portal alone, or a body-level match) — three misses is a pattern, not an accident.
- [2026-08-27 19:46] NOTED: the `vous propose` message is a **ONE-AD-PER-MESSAGE** shape whose subject carries the title, the price and `commune (NNNNN)` — structurally **PAP**, not leboncoin's own digest. So it probably needs its own source block (no `card_separator`, positional anchors keyed on the postcode parenthesis, `title_pattern` consulted per the PAP fix) rather than a parameter on the leboncoin car source. [Inferred from the subject line alone; the `.eml` decides, and one of the two shapes is wrong.]
- [2026-08-27 19:46] MEASURED (one fetch, honest UA): **Carizy is POLLABLE through its sitemap**, despite offering no email alert and disallowing its search. Wildcard group disallows `/voiture-occasion/recherche*`, `/voiture-occasion?q=*`, `/achat/*`; `sitemap.xml` → `/voiture-occasion/sitemap.xml` carries **1 341 URLs**, the ad pages being `/voiture-occasion/annonce/<MARQUE>/<MODELE>/<ANNEE>/<id>`, and **zero of them match any disallow**. The path itself carries make, model and year, so a pre-filter costs no request. Same shape as Autohero: the search is refused, the sitemap is the way in. A dead row became a live one **because the alert question was asked**.
- [2026-08-27 19:46] MEASURED: **ParuVendu's immo paths are partly disallowed** — `/immobilier/annonceimmofo/`, `/immobilier/annoncefo/`, `/immobilier/listecommunfo/`, `/immobilier/structureimmofo/`, `/immobilier/geo/*`, `/immobilier/immoneuffo/`, `/immobilier/bloc/*`, plus the generic `/*/listeAnnonces*`. The two `annonce*` names read as the ad-DETAIL route. The current search route is not named and is UNMEASURED. Largely moot: the route in is the email alert (hard rule 4), as it is for every private portal here.
- [2026-08-27 19:46] PROPOSED (answering the developer's question): **ParuVendu's rent section is worth adding, and the alert is worth creating today even though the build waits.** Its value is direct-from-owner stock — the gap PAP fills and the agency portals do not [Inferred, not measured]; its cost is one saved search plus one `.eml`; and cross-portal dedup (schema v4 `group_key`) already merges whatever it duplicates, so a generalist added late is cheap. **It is the SECOND generalist and therefore the second collision**: give the saved search a distinctive NAME so its subject carries a discriminator by construction, instead of discovering the collision the way leboncoin's was discovered.
- [2026-08-27 19:46] NOTED: **CapCar is refused for now on a ground decision 7 already rules.** Its alert form requires selecting a MAKE, which is a hard brand filter AT THE PORTAL — tighter than our criteria, which decision 7 forbids: what the portal rejects the scorer can never rank, and a make left unpicked is invisible for ever with nothing saying so. **If the form accepts SEVERAL makes at once, picking them all lifts the refusal** — one check, unmeasured.
- [2026-08-27 19:46] NOTED: **Interencheres needed no alert and never did.** Its coarse car filter is irrelevant — auctions are IN as of 01:05, `/recherche/*` is disallowed but the *ventes* pages sit outside it, so its route is POLLING. Nothing is owed by the developer on that row; it is an engineering task.
- [2026-08-27 20:20] MEASURED, and it **CORRECTS the Carizy row written 34 minutes earlier in this same file** (`25d245b`): one fetch of `/voiture-occasion/annonce/DACIA/SANDERO/2014/84324` returns **4 707 bytes of Nuxt SPA shell** carrying no price, no mileage, no make, no model and no real `<title>`. `window.__NUXT__` holds app config only. So Carizy is **REFUSED**, not open: neither `html` nor `embedded_json` can read that page, and robots separately disallows `/contentAjax/*` and `/listMake`, which is a stated position on the data endpoints the bundle would call.
- [2026-08-27 21:55] NOTED — **the readable check REORDERS the `.eml` queue, in both directions.** Autohero's capture drops down it: its polling payload is now confirmed complete (schema.org JSON-LD), so its alert is a convenience rather than the route. **Alcopa's capture rises to the top of the car alerts**, because it may answer the question the lot page left open — if the alert email carries a CLOSING TIME, the email route satisfies rule 2 of the auction ruling even if the lot page never does. A capture that answers an open question outranks one that confirms a working path.
- [2026-08-27 23:30] AGREED (developer, `AskUserQuestion`) and **DONE in-repo** — the rename landed ALONE on a clean tree (`f810274`, `34c65f0`), NOT folded into the car-domain refactor as the 01:40 note recommended. That note's argument was one test run instead of two; the counter-argument that won is that the rename's one good property is being BINARY — it compiles and the suite is green, or it does not — and that property does not survive being entangled with the largest refactor this project will have. A red suite afterwards would have been unattributable.
- [2026-08-27 23:30] NOTED — **the rule that decided every one of the 199 prose literals: repo and TOOL identity becomes `scout`; DOMAIN identity stays `rent-watch`.** The second domain is `car-watch`, so `state/rent-watch.sqlite3`, the `[rent-watch]` notification prefix, `rent-watch@localhost`, the heartbeat title and the `rent-watch/portails` Gmail label all name the DOMAIN and are correct unchanged. Renamed: the namespace, the env prefix, the titles, the `doctor` banner, the image tag `rent-watch:local` → `scout:local`, and the outward-facing User-Agent plus the robots token that must match it. **Most of the 199 were never supposed to change**, which the 20:40 estimate did not distinguish.
- [2026-08-27 23:30] NOTED: **`Config\LegacyEnv` makes the env half impossible to half-apply** — a `RENT_WATCH_*` name present at all is a loud startup refusal naming both spellings, never a fallback. It refuses on PRESENCE rather than on absence of the successor, because a `.env` carrying BOTH lines is the shadowing shape that cost the IDFM key its first live hour. It caught this machine's own `.env` within a minute of being wired, which is the only reason that file was migrated in the same change.
- [2026-08-27 23:30] NOTED, and it cost a repair: **the namespace `sed` rewrote HISTORY in this file** — the 20:40 entry recording `RentWatch\` → `Scout\` became `Scout\` → `Scout\`. A code identifier and a historical statement are the same string, and a blanket rename cannot tell them apart. Repaired in the same commit; the rule is that plan text describing a PAST state keeps the old spelling, and only text naming CURRENT code is renamed.
- [2026-08-27 23:30] OWED — the out-of-repo half is scripted at `/tmp/rename-scout-20260827.sh` and **must be run with no Claude session open on the repo**, because it moves the directory a session stands in: stop the watcher, move `~/.claude/projects/-stack-projects-rent-watch/` (the memory — nothing warns, and the failure looks exactly like a fresh project), move `/stack/projects/rent-watch`, rebuild as `scout:local`, verify the DEPLOYED image with `doctor`. Then the GitHub rename by hand (`gh` is not installed) and `test-notify`, which `doctor` does not cover.
- [2026-08-27 21:40] MEASURED, the readable check on all three OPEN hosts (§ "The readable check"): **Autohero is READABLE and carries a schema.org `Vehicle` JSON-LD block** with price, mileage, first registration, fuel, transmission, body type and previous owners — every ruled criterion including all three of decision 11's, in a standard shape, readable through the existing `embedded_json_selector`. **No new adapter is needed**, and it MEASURES the inference that Autohero's geography filter is inert: the payload has no location field at all.
- [2026-08-27 21:40] MEASURED: **Agorastore is a Nuxt SPA like Carizy, and unlike Carizy it is not dead** — its `<head>` names `api.auctelia.com`, whose `robots.txt` is `User-agent: *` / `Disallow: /sentry`. The documented follow-the-bundle route needed no bundle. What separates the two rows is precisely that: a discovered, permitting API host versus an undiscovered one behind a `Disallow: /contentAjax/*`.
- [2026-08-27 21:40] MEASURED, and it is deliberately NOT a verdict: **one Alcopa lot page carries no price and no closing time.** Rule 2 of the auction ruling refuses such a source fail-closed — but three explanations fit (client-side auction state, login-gated bidding, or a lot that is simply not in an active sale), and they have opposite consequences. `sitemap.sales.xml` is the way to find a live one. **Alcopa is recorded as UNRESOLVED, not refused**: n=1 has already cost this project a row today, and writing the verdict now would be the same mistake with the sign flipped.
- [2026-08-27 21:40] NOTED: **`Disallow: /calendrier/` does not cover `/calendrier-des-ventes`** — literal prefix matching, the same rule that let Logirep's `/recherche` through a `Disallow: /search/`. Relevant because the calendar is where a closing time would live.
- [2026-08-27 21:40] NOTED: **Alcopa segregates `/vehicules-hs`** (hors service) from `/vehicules-de-tourisme`, so the §1 vehicle set's largest auction class is separable by URL before any classifier runs. A second line of defence, not a replacement for the first.
- [2026-08-27 21:10] AGREED (developer, `AskUserQuestion`), and it generalises: **NO ParuVendu Gmail filter is written until its first message has been read.** A split is needed only for a sender that carries TWO domains, and ParuVendu carries one today — leboncoin is the only actual collision in the tree. The first alert lands in the inbox, costing one message, and the filter is then written against a real subject rather than a guessed one (02:35's rule).
- [2026-08-27 21:10] NOTED — **the best filter is on the SAVED-SEARCH NAME, not on the portal's vocabulary, and it is self-tripwiring.** If the alert's subject carries the name the developer chose (`rent-watch-pv-idf`), the filter matches a discriminator WE control instead of a French word the portal may stop using — and because it is narrow, anything else from that sender (a future car alert, a changed template) lands in the INBOX instead of being swallowed into the rent label and parsed as a flat. leboncoin's `Voitures` / `à louer` split is the weaker fallback, needed only because those searches were named before this was understood. **Name every future saved search distinctively for this reason**, on both domains — rule 3 of § "Creating the alerts" was written as a fallback for portals refusing `+tags`, and it turns out to be the PREFERRED mechanism.
- [2026-08-27 20:55] AGREED (developer, `AskUserQuestion`): **the ParuVendu RENT alert is created now, built later.** Distinctive saved-search name, portal settings a notch wider than the criteria (Île-de-France, 3 pièces min, 45 m² min, 1 300 € max) per the two-layer rule.
- [2026-08-27 20:55] MEASURED (two fetches, honest UA), and it CORRECTS the 19:46 row that called ParuVendu's current immo search route unmeasured: the live route is **`/immobilier/recherche/location/appartement/`**, reached from the homepage, and it matches **none** of the disallowed `*fo` paths. It returns **456 KB of SERVER-RENDERED HTML** — 237 × `m²`, 141 × `pièces`, 30 × `CC`, prices as `795 €` — and carries a **`Créer une alerte`** button (`onclick="creationAlerte('I','ILHAP000',…)"`). So on this source the alert facility is CONFIRMED to exist, and unlike Carizy the page is READABLE: ParuVendu is a polling candidate for rent as well as an email one. Email still first, per hard rule 4.
- [2026-08-27 20:35] NOTED: **Carizy's death raises the cost of refusing CapCar, and the decision is deferred on one unmeasured detail.** The two were the catalogue's ONLY C2C-with-intermediary sources; with Carizy refused, dropping CapCar costs that entire inventory class rather than one of two routes to it. The refusal still stands on decision 7's ground — a mandatory make is a portal-side hard filter tighter than our criteria, on a field decision 11 does not rank at all — but **if the selector takes several makes at once, the objection evaporates and there is no trade-off left**. Developer to check in a browser; robots disallows the path an automated check would use.
- [2026-08-27 20:20] NOTED, the lesson under it: **a sitemap proves that URLs EXIST, never that a page is READABLE.** The catalogue already distinguishes *pollable* from *useful* (Erilia: 49 clean listings, zero in Île-de-France); this adds a third column between them, **readable**. `robots.txt` plus a sitemap answer *may I fetch it*. Only fetching ONE PAGE answers *is there anything in it*, and it costs one request — the same request that would otherwise be spent discovering it after an adapter was designed.
- [2026-08-28 00:20] MEASURED (developer, from a real message): **the `vous propose` sender is `no.reply@leboncoin.fr`** — BYTE-IDENTICAL to the `params.from` the live rent source already matches on [Verified: config/sources.json:177]. This RESOLVES the conditional left open at 2026-08-27 19:46 on the branch that ruling called the worse one: **a third POSITIVE subject match, `vous propose`**, never a `From:` filter and never a negation. Sender routing cannot help on this portal at all — `from` is identical and `link_host: leboncoin.fr/vi/` is category-agnostic, so NEITHER of `EmailAlertSource`'s two scoping params can distinguish a car from a flat here. The subject is the only discriminator that exists.
- [2026-08-28 00:20] CONFIRMED LIVE: the 02:35 tripwire behaved exactly as designed. Both leboncoin filters skip the inbox, so the unknown third template landed in the INBOX by default and the developer saw it — no filter, no negated search, no maintenance. This is the SeLoger 9 → 0 silent-zeroing shape made loud for free, on its first real test.
- [2026-08-28 00:20] MEASURED, second sample of the third template: `Flip my car vous propose VOLKSWAGEN TIGUAN II (2) 1.5 TSI 150 LIFE BUSINESS BVM6 - Garantie 12 mois à 22 990 € à Chambourcy (78240)`, 27 Aug 12:33. With TRANSAKAUTO MEAUX this makes n=2 and fixes the shape: `<seller> vous propose <title> à <price> € à <commune> (<CP>)`, ONE AD PER MESSAGE, all four fields in the SUBJECT. Structurally PAP, not leboncoin's own digest — so 19:46's inference that it needs its OWN source block (positional anchors on the postcode parenthesis, no `card_separator`) is strengthened, though still the `.eml`'s call.
- [2026-08-28 00:20] NOTED: **this is the THIRD subject template, and 19:46 set the ceiling at three.** `Voitures` + `à louer` + `vous propose` exhausts the tolerance for a vocabulary-shaped filter. A FOURTH template does not get added to the list — at that point leboncoin moves to a structural discriminator (a dedicated alias for that portal alone, or a body-level match). Three misses is a pattern, not an accident.
- [2026-08-28 00:20] AGREED: the `vous propose` Gmail filter is STILL not written, and the reason is unchanged by knowing the sender — filtering it now moves it out of the inbox into a folder nothing reads, BEFORE a capture exists. Filter after the `.eml`, not before.
- [2026-08-27 19:46] NOTED: **Agorastore's price-only alert is tolerable and should be category-scoped if the form allows it.** The site disposes of every category of public-sector asset, so a price-only alert is mostly non-vehicles, and noise costs `SourceHealth` its credibility — that baseline is measured on listings FETCHED, not on matches. Its robots is `Allow: *`, so polling is the real route either way and the alert is a bonus.
- [2026-08-28 16:05] DONE (developer): **the Alcopa renewal reminder is set** for ~24/09/2026, three days before the 27/09/2026 expiry. This closes the only dated item in this file. The reminder is the ONLY mechanism that exists — verified the same minute, `grep -niE "alcopa|expires|expiry|valid_until" config/sources.json` returns **nothing**: there is no Alcopa source block, no car source block at all, and no expiry key anywhere in the schema.
- [2026-08-28 16:05] AGREED: the proposal to print the expiry from the config block via `scout doctor` is **DEFERRED to the first car source block**, not built now. Two reasons, and the second is the stronger: it has **no consumer today** (designing a config field for a source that does not exist is the blind-config class this repo has a measured price for), and **Alcopa may never be enabled** — it is recorded UNRESOLVED, its one fetched lot page carried no price and no closing time, rule 2 of the auction ruling refuses such a source fail-closed, and decision 10's default is *auctions out, for now*. When a car source block does land, `alert_expires` is nearly free and is GENERAL — any portal alert can expire, so it belongs on the `email_alert` schema rather than on this one row.
- [2026-08-28 16:40] MEASURED, and it CLOSES the question the `[2026-08-27 21:55]` ruling promoted Alcopa's capture to the top of the queue to answer: **the Alcopa alert email carries NO closing time, and its EMAIL ROUTE IS REFUSED.** Read from the real message (`Contact Alcopa <contact@alcopa-auction.fr>`, 28 Aug 04:02 UTC, subject `[Alcopa-Auction] Voici les résultats de votre recherche <100000km <30000€ >2020 BEAUVAISIDF WEBPARIS SUD`, 42 363 bytes). Both MIME parts were checked, not just the plain one: the `text/html` alternative (31 828 chars) carries the SAME three cards and nothing more — **zero** occurrences of `clôture`, `enchère`, `fin de vente`, `se termine`, `date` or `heure`, and its single `€` is inside the SEARCH NAME (`<30000€`), not a price. Rule 2 of the auction ruling — *a closing time is mandatory in the notification, and a source that publishes none is refused fail-closed* — therefore refuses it on its own payload.
- [2026-08-28 16:40] MEASURED, and either half would refuse it alone: **the alert publishes no PER-LOT LINK and truncates 108 results to 3.** Its only six links are the generic search URL (twice), `search-saved`, and four social accounts — so there is no link identity, and content identity would have to be built from title + `Énergie : ES` + mileage, with no price and no location. And the body says `remonte *108* résultat(s)` while showing three cards: **97% truncation**, the leboncoin-58 question asked and answered in the worst direction.
- [2026-08-28 16:40] SCOPED, because generalising it would be the error this file keeps paying for: **the EMAIL route is refused; the POLLING route stays UNRESOLVED.** The `[2026-08-27 21:40]` entry left three explanations open for the lot page's missing closing time (client-side auction state, a login gate, or a lot not in an active sale), and an alert email omitting a field is not evidence about what a lot page renders. `sitemap.sales.xml` is still the way to settle that, and it is still unmeasured. Two surfaces missing the same field is suggestive, not conclusive.
- [2026-08-28 16:40] NOTED: the ~24/09 renewal reminder is now **optional rather than load-bearing** — it protects an email route that is refused. It costs nothing to keep (the mailbox is the corpus, and a lapsed alert is one fewer signal if the polling route ever revives), so it is left standing rather than cancelled. Revisit when `sitemap.sales.xml` is measured.
- [2026-08-28 16:40] NOTED, the mechanism: the mailbox was read with the project's OWN `Adapters/Mail/ImapMailbox` and the `.env` credentials, via a throwaway script in gitignored `var/claude/`. **The folder was overridden in the CONSTRUCTOR, never by editing `.env`** — the deployed watcher reads `IMAP_MAILBOX` from that file, so repointing it would have silently repointed the live rent source. `ImapMailbox` uses `EXAMINE`, so the read is read-only at the PROTOCOL level and no bug on this side could have cost the developer their mail.
- [2026-08-28 16:40] MEASURED — **the `car-watch/portails` label holds 41 messages over 30 days, and reading it CORRECTS this file in four places.** Raw inventory in `var/claude/captures/` (gitignored). Each correction is verified from the message headers:
  1. **La Centrale's alert EXISTS and is FIRING.** This file lists it under *"not yet created, and deliberately"*. `"La Centrale" <info@mail-alerte.lacentrale.fr>` sent `Création de votre alerte` on 27 Aug 09:50 and `904 nouveaux véhicules correspondent à votre recherche` on 28 Aug 06:21, whose HTML carries real prices (`39 880 €`, `44 900 €`, `19 990 €`). It is the richest car alert in the folder.
  2. **AutoScout24's saved search EXISTS too** — `savedsearches@notifications.autoscout24.com`, `Jusqu'à 30 000 € : ta recherche a bien été enregistrée`, 27 Aug 09:53. That is the CONFIRMATION, not an alert; its single `€` is the ceiling, not a listing price. No alert has fired yet.
  3. **ParuVendu's car alert exists and fires roughly every TWO HOURS**, with counts of 25, 34, 37, 39, 51, 65, 93, 331, 366 and **1339** in this window. Real prices in the HTML (`16 500€`, `12 900€`, `20 450€`). By far the highest-volume source in either domain; the cadence needs a decision of its own.
  4. **The ParuVendu RENT alert is landing in the CAR label** — `🏠 Nouvelles annonces location`, 28 Aug 07:30, prices `1200€ / 518€ / 660€ / 725€`. The filter evidently routes on the SENDER `info@paruvendu.fr`, which serves both domains — so the mailbox-routing ruling's own hazard has reappeared on a new portal: one sender, two domains, one label.
- [2026-08-28 16:40] MEASURED, answering the `[2026-08-27 19:46]` Agorastore question without the developer needing to check the form: **the Agorastore alert IS category-scoped.** Its body reads `NOUVEAUX ARTICLES CORRESPONDANT À VOTRE ALERTE - VOITURE` / `Votre recherche : Voiture`, and every item is a car. So the `SourceHealth` concern — a 97%-furniture feed staying green the day vehicle stock hits zero — does not apply, and no ingest-side category discriminator is needed. **But its ALERT PAYLOAD fails the auction ruling for the same reason Alcopa's does:** zero prices and zero closing times in its HTML. **Scoped exactly as Alcopa's verdict is scoped, and it matters more here:** this refuses the EMAIL route only. Agorastore's non-email route is MORE alive than Alcopa's — `api.auctelia.com` is open and named in the page (measured 2026-08-27) — so an API route that hydrated a lot reference could still supply the closing time the alert omits. Reading *"Agorastore refused"* as total would be the generalisation this file keeps cataloguing. It does carry real lot references (`013985-138`, `014733-010`, `014586-084`) in the text, which is more identity than Alcopa offers — its links are all opaque `email.alerts.agorastore.fr/c/<token>` redirects, the SeLoger shape, so link identity is impossible and content identity would have to key on that reference.
- [2026-08-28 16:40] NOTED — **the two Alcopa senders are different and only one is the alert.** `contact@alcopa-auction.fr` sends the saved-search results; `newsletter@alcopa-auction.fr` sends `🔔 Enchères de la semaine du …`, a weekly marketing digest (2 in this window). A `params.from` on the domain alone would ingest the newsletter as listings — the same shape as SeLoger's twelve contact receipts from `seloger@s.seloger.com`.
- [2026-08-28 17:10] MEASURED, and it is the most operationally serious thing this mailbox read turned up: **`leboncoin` has been reporting a healthy, steady `items=3` for 263 consecutive passes off ONE message from 26 August, and there is nothing behind it.** Queried the way the source itself queries — `FROM no.reply@leboncoin.fr` over 14 days against `rent-watch/portails` — the folder holds exactly TWO leboncoin messages: a `Nouvel appareil détecté` account notice (25 Aug) and `3 nouveaux biens à louer à Ile-de-France` (26 Aug 07:33), the single alert this repo documents as source #7's first. No alert has arrived since. `source_runs` confirms `item_count = 3` on every pass from then to `2026-08-28T22:03:58`.
- [2026-08-28 17:10] NOTED — **this is a NEW shape of hard rule 2, and `SourceHealth` cannot see it.** The baseline is 3, the current count is 3, so there is no drop to warn about and no empty run to count toward `SOURCE_BROKEN`: a source re-reading one frozen message is indistinguishable from a source receiving a steady trickle. It self-corrects in the worst possible way — on or about **2 September**, when the 26 Aug message falls out of the `SEARCH SINCE 7 days` window, the count goes 3 → 0 with no change in the world, and the alert that finally fires will name the wrong day and the wrong cause. **A count that never varies is itself a signal**, and nothing in the health model reads it. [Whether leboncoin alerts are simply infrequent is UNMEASURED — two days of silence is not proof of a broken alert. The defect being recorded is that the tool cannot tell the two apart, not that the alert is known dead.]
- [2026-08-28 17:10] MEASURED, answering item 6 (*do the two leboncoin filters exist as FILTERS?*) in the negative: **no leboncoin car alert is in either watched folder.** `car-watch/portails` holds 41 messages over 30 days and not one is from leboncoin; `rent-watch/portails` holds two over 14 days, both accounted for above. So the `Voitures` subject filter recorded in this file is NOT routing to `car-watch/portails`. The IMAP folder list is `car-watch`, `car-watch/portails`, `rent-watch`, `rent-watch/portails`, `INBOX`, two folders named with a tick (`&JxQ-`, `&JxQnFA-` in modified UTF-7), `Mailspring`, `AssuranceMaladie` and the `[Gmail]` specials — so the alert is in the INBOX, in a tick folder, or has not fired. **Not investigated further: the remaining candidates are the developer's personal mail, and the authorisation given was for the car label.**
- [2026-08-28 17:10] NOTED: **Jinka is delivering into `rent-watch/portails`** (`contact@jinka.fr`, 1 message in 7 days, `Comment éviter les arnaques à la location` — a newsletter). Harmless today because `params.from` scopes every source, but it confirms the label is not a curated set: anything the filter admits lands in the source's shared window, and Jinka is explicitly NOT a candidate (§ CLAUDE.md — its `text/plain` part is 78 bytes and it is an aggregator needing its own §1 evaluation).
- [2026-08-28 17:10] VERIFIED, since a mailbox read is the moment to check it: the production store is at **schema v10** and `Store::SCHEMA_VERSION` is **10** — in sync, no migration owed. The deployed image (`2026-08-27T23:42:10Z`) is also NEWER than the last commit touching `src/` (`4ce6ebc`, 2026-08-27 21:43 UTC), so the watcher is not stale. Both are the checks § Gotchas prescribes after the seventeen-hour incident of 2026-08-23.
- [2026-08-28 17:35] MEASURED — **La Centrale's alert is RICH IN FIELDS and POOR IN EVERYTHING ELSE, and it truncates 904 results to 3.** Read from the real message (`info@mail-alerte.lacentrale.fr`, 28 Aug 06:21, `904 nouveaux véhicules correspondent à votre recherche`, 134 447 bytes; `text/plain` 16 656 chars, `text/html` 78 937). Card shape, three of them, each: `VOLVO XC90 II phase 2` / `99 180 km` / `39 880 €` / `Détails` / `Vendeur professionnel` / `91`. So price, mileage and seller type are all present and clean — better than any other car alert measured. Four defects sit on top of that:
  1. **99.7% truncation.** 3 cards for a stated 904. leboncoin's `58 nouveaux résultats` was the question this whole capture queue was built to answer; La Centrale answers a worse version of it first. And its polling route is refused BY RULING (403/DataDome, hard rule 5 posture), so unlike Autohero there is no second route to fall back on.
  2. **No listing id and ONE opaque link host.** Every URL is `clicks.mail-alerte.lacentrale.fr/f/a/<token>` — the SeLoger shape, so identity must be content-addressed. Worse than SeLoger: the unsubscribe, privacy, Trustpilot and social links share that same host, so `link_host` cannot discriminate a listing link from furniture at all. The PAP phantom-listing hazard with the PAP fix unavailable.
  3. **The title is ELLIPSISED IN THE EMAIL** — `LAND ROVER RANGE ROVER SPOR...`. A content key built on the title is keyed on a truncation whose cut point is the portal's, not ours. [Whether that cut point is stable is UNMEASURED, n=1.]
  4. **No commune and no postcode — only a bare departement number** (`91`, `75`, `77`), and it arrives glued to an image ALT that is the literal string `La Centrale` on every image, so the text reads `La Centrale 91`. A positional anchor is possible; a vocabulary scan is not.
- [2026-08-28 17:35] NOTED — **the `&nbsp;` entity defect is present and already fixed.** La Centrale's `text/plain` carries `99&nbsp;180 km` and `39&nbsp;880 €` undecoded, exactly the shape that made SeLoger's `logement conventionn&eacute;` fold to `logement conventionn` and destroy a tenure label. `Text::fold()`'s refusal and the decode that answers it were built on 2026-08-25 for SeLoger and cover this unchanged — **a fix built for one portal, load-bearing on the second before it was ever enabled**. Without it every price and every mileage on this source would have assembled across the entity.
- [2026-08-28 18:20] BUILT — **`SourceStatus::FEED_SILENT`, the verdict for a source that keeps REPORTING while its feed has stopped DELIVERING.** Schema **v11** adds `source_runs.feed_newest_at`; `Adapters/FeedFreshness` is the opt-in contract, implemented by `EmailAlertSource` (delegating to the mailbox) and forwarded by `PacedSource`; `Mailbox::newestMessageAt()` is where the fact comes from. Closes the leboncoin hole measured three hours earlier: 263 consecutive passes reporting a healthy `item_count = 3` off ONE message dated 26 August.
- [2026-08-28 18:20] NOTED — **the OBVIOUS signal was refused, and the reason is the whole design.** *"Warn when no NEW listing has arrived for N days"* is exactly what a quiet rental market looks like, so it restates hard rule 2's ambiguity instead of resolving it — Logirep returns the same 113 listings on every pass by design. The age of the newest MESSAGE is different in kind: it is a statement about what the portal SENT, never an inference about the market. *"leboncoin has not sent anything in three days"* is checkable; *"no new listings"* is not.
- [2026-08-28 18:20] MEASURED, and it is why the default is 3 rather than a guess. Firing cadences over 14 days on the live mailbox: **Bien'ici ~30/day** (longest gap ~4.5 h), **PAP ~8/day**, **SeLoger 160 in seven days** — none has ever been quiet for a full day — against **leboncoin, which has sent exactly ONE alert since it was created**. Three days catches leboncoin and cannot false-fire on the other three. **Stated cost:** a source firing thirty times a day is only noticed after three days of silence, ≈90 missed alerts, which is the argument for a per-source override and is why this is not written as a constant.
- [2026-08-28 18:20] NOTED — **`FEED_SILENT_DAYS` must be STRICTLY under `IMAP_SINCE_DAYS`, refused at startup, and that is a REACHABILITY constraint rather than a preference.** The newest message `SEARCH SINCE` can match is by definition at most `IMAP_SINCE_DAYS` old, so while the count is non-zero the feed's age is BOUNDED by the window: at or above it the count collapses to zero — and the existing empty-streak rule takes the verdict — before the age can ever reach the threshold. The status would be unreachable **by construction**, which is precisely how `high_priority_score: 70` sat dead for weeks while looking configured. The observable band is `(threshold, window)`; on 3 and 7 that is about four days of early warning.
- [2026-08-28 18:20] NOTED, three hard-rule-9 rules that travel with it, each with its own sabotage case:
  1. **Unknown is UNKNOWN, never old.** A null feed date yields no verdict. That covers every html/json source, every row written before v11, and `FileMailbox` — which reports `null` DELIBERATELY, because a directory of frozen fixtures is not a feed. Had it reported its files' dates, the documented `MAILBOX_DIR=… scout doctor` workflow would drift into `FEED_SILENT` as the calendar advanced: a gate that goes red on a future date with no code change.
  2. **A FUTURE-dated message is filtered, not clamped.** The verdict reduces reported dates to their maximum, so one portal with a fast clock wins that maximum and reports the feed fresh for ever. A first implementation clamped the date to `now` — which is *worse*, since it makes the bogus stamp read as fresher still. Filtering mirrors what the `STALE` branch already does with forward-skewed run timestamps. When nothing reported is credible, that is itself reported rather than swallowed.
  3. **A failed run records no feed date.** Reading it only on the success path stops the previous pass's freshness vouching for a pass that fetched nothing.
- [2026-08-28 18:20] NOTED — **`BROKEN` now names the last message it saw, and that is the half that survives the threshold being wrong.** When an email source's count finally collapses because its window expired, the empty streak dates from the EXPIRY and the silence dates from days earlier. Naming only the streak is exactly how the leboncoin case would have been reported on the wrong day and blamed on the wrong cause — a week late.
- [2026-08-28 18:20] NOTED, a near-miss worth keeping: **the `recordRun` call in `Pipeline` is deliberately kept on ONE line.** `tests/sabotage-check.sh` matches it by its literal prefix up to `true`, so the multi-line reformat the new argument invited would have silently rotted that expression into matching nothing — the same way a `markNotified()` signature change rotted one on 2026-08-24, leaving the ledger reporting coverage it no longer had. `tests/test-sabotage-applies.sh` is what makes that catchable at all.
- [2026-08-28 19:05] STATED COST, recorded rather than left to be discovered: **the freshness signal says the SENDER sent something, not that the ALERT fired.** `params.from` scopes a source to one address and a portal uses that address for more than alerts — `leboncoin`'s only OTHER message in fourteen days is a `Nouvel appareil détecté` account notice, which matches the filter and would have refreshed the date. So an account notice or a marketing mail can mask alert silence for as long as it keeps arriving. Tightening it to *"the newest message that actually YIELDED a listing"* is the obvious next step and is **deliberately not taken yet**: it would make the signal depend on the parser, so a parser regression would then present as a silent FEED rather than as a broken SOURCE — and those two need different fixes.
- [2026-08-28 19:05] NOTED — **the ledger found THREE pieces of dead safety code inside the change built to remove dead safety code**, and none was visible to review. `PacedSource`'s forwarding could be replaced with `return null;` with the suite green; the unreachable-threshold refusal could be deleted with the suite green; and — the worst of the three — `$feedSilentDays ??= $this->feedSilentDays;` could be dropped from `Store::health()` with the suite green, which made `FEED_SILENT` unreachable in production **under default config** while every test passed, because the CLI tests only pin that the resolver is CALLED and every store test passes the threshold EXPLICITLY. The counterweight test that was supposed to catch that last one asserted an ABSENCE (*"no error is printed"*), which stays true however thoroughly the feature is disconnected. It was replaced with an end-to-end one that sets no environment at all and requires `doctor` to actually print the verdict.
- [2026-08-28 19:05] NOTED, found by that same test: **it must be anchored on the harness's FIXED clock, not on wall time.** A first draft seeded `now − 4 days`, which is four days AHEAD of `ScoutTest`'s pinned `2026-08-07T12:00+02:00`, so the run reported *"toutes les dates de message sont dans le futur"*. Wrong branch, right machinery — it proved the future-date guard end to end by accident.
- [2026-08-28 19:05] OWED — **`FEED_SILENT` cannot fire in production until the image is rebuilt**, and the redeploy has a trap worth knowing before it is run. `src/` is baked into `scout:local`, so the deployed 27 August watcher records no feed dates at all. Worse, **running the new CLI against the production database migrates it v10 → v11, and the deployed v10 code then REFUSES to open it** (a newer schema is refused, by design). So: no local acceptance against the real `SCOUT_DB` until after the redeploy — use a throwaway one — and rebuild promptly. The payoff is worth the care: deployed before ~29 August, `leboncoin` flips to `feed_silent` several days ahead of the 2 September silent collapse, which is the live proof of the whole feature.

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

> **ALL SIX DECISIONS (5–10) ARE RULED as of 2026-08-26** — see § "Car criteria" below. The table
> above is left intact as the record of what was proposed and what each default would have been.

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
| `www.autohero.com` | 200 | disallows only `/myhero/`, `/inspection/`, `/checkout/`, `/identify`, `/center`, `/unsubscribe/` | **OPEN AND READABLE — confirmed 2026-08-27, the only one of the three that is.** `/fr/sitemap_search.xml` = **3 453 vehicles**; a lot page is 640 KB server-rendered carrying a schema.org `Vehicle` JSON-LD block with price, mileage, registration date, fuel, transmission and body type. Reads through the existing `embedded_json_selector`. Fixed price, delivered, national — and the payload has **no location field**, so decision 6's geography filter is measurably inert here |
| `www.agorastore.fr` | 200 | `Allow: *` | **WIDE OPEN — but the site itself is a Nuxt SPA** (measured 2026-08-27: zero `€` on the homepage). Its `<head>` names `api.auctelia.com`, whose robots is `User-agent: *` / `Disallow: /sentry` — so the data host is open and discovered. Public-sector and fleet disposals, open to individuals |
| `www.alcopa-auction.fr` | 200 | `Disallow: /*.pdf$`, `/calendrier/` | **OPEN, lot pages READABLE, verdict UNRESOLVED** (measured 2026-08-27). `sitemap.items-fr.xml` = **5 839 lots**; one lot page is 84 KB server-rendered with make/model/saleroom/`38 656 KM` — and **no price and no closing time**, which rule 2 of the auction ruling refuses fail-closed. Reason unmeasured: client-side auction state, login gate, or a lot not in an active sale. Check one from `sitemap.sales.xml` before ruling |
| `www.leparking.fr` | 200 | only `/tools/`, `/extlink/`, `/tag/` | **WIDE OPEN**, and a **meta-search** across many portals — breadth in one adapter. ⚠️ It is an AGGREGATOR, so the Jinka caveat applies in full: a truncated description can lose a `VEI` the original ad carried. Needs its own §1 evaluation before it is trusted |

### Refused, with the reason

| Host | HTTP | Verdict |
|---|---|---|
| `www.lacentrale.fr` | **403** | **REFUSED BY RULING.** Body is a **DataDome CAPTCHA challenge** (`captcha-delivery.com`) — hard rule 5 refuses solving it, same class as A15 Val d'Oise Habitat. Also fail-closed under this repo's posture (403 = blocked). Email-alert route only |
| `www.spoticar.fr` | **403** | Akamai-style `Access Denied` + reference id. **Fails closed.** Stellantis' 80 000-car network — alert route only, if any |
| `www.encheres-domaine.gouv.fr` | 200 | `robots.txt` is a **JS redirect requiring cookies**. The 2026-08-25 rule already refuses this: a 2xx whose body starts `<` is not a robots file |
| `www.capcar.fr` | 200 | `Disallow: /trouver-une-voiture/*` — the search itself. **And its email alert requires selecting a MAKE** [Verified by the developer, 2026-08-27], a portal-side hard filter that decision 7 refuses — so CapCar is OUT for now, unless the form takes several makes at once (unmeasured) |
| `www.carizy.com` | 200 | **REFUSED — measured 2026-08-27 in TWO steps, and the second refuted the first.** robots opens the sitemap (`/voiture-occasion/sitemap.xml`, 1 341 URLs, **1 000 of them ad pages**, none matching a disallow), so the row was first written *OPEN VIA SITEMAP*. Then one ad page was fetched: **4 707 bytes of Nuxt SPA shell** — `window.__NUXT__` carries app config ONLY, `<title>` is `carizy.com`, and the body contains **zero** occurrences of `€`, `km`, the make or the model. Neither `html` nor `embedded_json` can read it. The data arrives by XHR from an unmeasured host, and robots separately disallows `/contentAjax/*` and `/listMake` — a stated position on data endpoints. **No email alert either** [Verified by the developer]. Dead unless the bundle → API route is chased |
| `www.leboncoin.fr` | 200 | **No `User-agent: *` group at all** (verified across the whole file). Moot — already email-only here (403s a plain client). Named AI groups carry `Disallow: /recherche`, `/ad/` |
| `www.autoscout24.fr` | 200 | `Disallow: /lst?`, `/lst/?`, `/listing-search-api/graphql` — **search-with-query refused**. Separately: `ClaudeBot`, `GPTBot`, `CCBot`, `Google-Extended` get `Disallow: /`, a stated position on automated agents |

### Partly refused — the listing path is out, another may not be. UNMEASURED

| Host | Disallowed | What is left |
|---|---|---|
| `www.interencheres.com` | `/recherche/*` | The category *ventes* pages sit outside it. #1 French auction portal. Sitemap published |
| `www.autosphere.fr` | `/occasions`, `*recherchecourte*` | `/recherche` is not disallowed. Emil Frey France network |
| `www.paruvendu.fr` | `/auto-moto/rechercheautofo/`, `/auto-moto/listefo/`, `/auto-moto/annonceautofo/` — **and, measured 2026-08-27, on the immo side `/immobilier/annonceimmofo/`, `/immobilier/annoncefo/`, `/immobilier/listecommunfo/`, `/immobilier/structureimmofo/`, `/immobilier/geo/*`, `/immobilier/immoneuffo/`, `/immobilier/bloc/*`, plus the generic `/*/listeAnnonces*`** | Those are NAMED legacy front-office paths; whether the CURRENT search route falls under them is unmeasured, on either side. The two immo `annonce*` names read as the ad-DETAIL route. **Largely moot — the route in is the email alert (hard rule 4), and ParuVendu has a RENT section, which is why it is now recommended on the housing side too** |
| `www.vpauto.fr` | long named-bot blocklist | Wildcard group not isolated; needs a second read |

### The readable check, run on all three open hosts — 2026-08-27 21:40

Prompted by Carizy: three rows rated OPEN on `robots.txt` plus a sitemap, the same evidence that was
refuted three hours earlier. One page fetched per host, honest UA, spaced.

**AUTOHERO — READABLE, and it carries the best payload in this project.** `/fr/sitemap_search.xml`
lists **3 453 vehicles** as `/fr/<make>-<model>/id/<uuid>/`; one fetched page is **640 KB of
server-rendered HTML** containing a single `application/ld+json` block of `@type: Vehicle`:

```json
{"name":"Peugeot 3008 1.6 Blue-HDi Allure","offers":{"price":13690,"priceCurrency":"EUR"},
 "brand":"Peugeot","model":"3008","bodyType":"SUV","dateVehiclefirstregistered":"2017-02",
 "vehicleEngine":{"fuelType":"Diesel","enginePower":"120 CV / 88 kW"},
 "mileageFromOdometer":"107 087 KMT","vehicleTransmission":"Boite de vitesse manuelle",
 "numberOfPreviousOwners":"4","numberOfDoors":5,"sku":"HK87832"}
```

Every field the ruled criteria need, in a **standard schema.org shape**: price (decision 5), mileage
and first registration (decision 7), and **all three of decision 11's score components at once** —
`fuelType`, `vehicleTransmission`, `bodyType`. It reads through `embedded_json_selector`, the
mechanism Logirep already forced us to build, so this needs **no new adapter**.

Three details that will bite whoever writes the field map:

- **`mileageFromOdometer` is `"107 087 KMT"`** — a narrow no-break space as thousands separator and
  the UN/CEFACT unit code `KMT`, not `km`. `\h` for the separator (the SeLoger rent fix) and a unit
  suffix the integer reader must not swallow into the number.
- **`vehicleTransmission` is `"Boite de vitesse manuelle"` — no circumflex.** A matcher written for
  *boîte automatique* misses it; `Text::fold()` is what makes that safe, and it must be used here.
- **There is NO location field at all.** That MEASURES what the 01:05 ruling inferred: the
  decision-6 geography filter is **inert on this source**. Not "probably inert" — the payload has
  nowhere to put a commune.

**AGORASTORE — SPA, like Carizy, but NOT dead: it names its own API in the first line of HTML.**
The page is a Nuxt shell (`#__nuxt`, `window.__NUXT__`, **zero** `€` on the homepage). Its `<head>`
opens with `<link rel="preconnect" href="https://api.auctelia.com">` — the platform behind
Agorastore — so the documented *follow the bundle to its API host* route needs no bundle-digging at
all. **`api.auctelia.com/robots.txt` is `User-agent: *` / `Disallow: /sentry`** [Verified
2026-08-27]: everything but error reporting is permitted. That is the difference between this row
and Carizy's, where the API host is undiscovered AND `/contentAjax/*` is disallowed.

**ALCOPA — lot pages are readable, but the ONE FIELD THE AUCTION RULING MAKES MANDATORY WAS NOT ON
THE PAGE.** `sitemap.items-fr.xml` lists **5 839 lots** as `/voiture-occasion/<make>/<version>-<id>`;
one fetched lot is 84 KB of server-rendered HTML with the make, model, version, saleroom and
`38 656 KM`. It carries **no `€` at all and no closing time** — zero matches for `clôtur`,
*se termine*, *fin des enchères*; the only `h`-times on the page are the saleroom's opening hours.

> **That is a refusal trigger, not a defect — and the REASON is unmeasured, which is the part that
> matters.** Rule 2 of the auction ruling: *a closing time is mandatory in the notification, and a
> source that publishes none is refused fail-closed.* But three explanations fit the same evidence,
> and they have opposite consequences: the live auction state is fetched client-side or behind
> login; or it is shown only to a signed-in bidder; or **this particular lot is not in an active
> sale** — 5 839 items in a sitemap is a catalogue, not a saleroom floor. `sitemap.sales.xml` exists
> and is the way to find a lot in a live sale. **Do not record Alcopa as refused until that is
> checked**, and do not record it as open either. n=1, on a source where n=1 has already cost this
> project a row today.

Two smaller things worth keeping:

- **Alcopa segregates `/vehicules-hs`** — *hors service* — as its own category, alongside
  `/vehicules-de-tourisme`, `/vehicules-utilitaires` and `/voitures-de-collection`. So the §1
  vehicle set's largest auction-house class is **structurally separable by URL**, before any
  classifier runs. That does not replace the classifier; it means the classifier is not the only
  line of defence on this source.
- **`Disallow: /calendrier/` does NOT cover `/calendrier-des-ventes`.** Robots matching is literal
  prefix matching — the same rule that let Logirep's `/recherche` through a `Disallow: /search/`.
  Worth stating because the calendar page is exactly where a closing time would live.

### Email alerts — the preferred route (hard rule 4)

- **leboncoin** — saved searches with email alerts, up to 50 [Verified: portal help centre]
- **La Centrale** — *"Créer une alerte nouvelles annonces"* on a saved search [Verified: FAQ]
- **AutoScout24** — saved search + alert at chosen intervals (1 h / 12 h / 24 h / 7 d), **including price drops** [Verified: FAQ] — price drops land straight in the existing `price_history` machinery
- **Alcopa Auction** — *"être alerté"* on matching vehicles. **CONFIRMED and CREATED 2026-08-27**, and it carries an EXPIRY: valid to **27/09/2026**. The only alert in this file with a deadline — see § "Operator report"
- **Autohero** — alert **CREATED 2026-08-27**
- **Agorastore** — alert **CREATED 2026-08-27**, but the form offered a PRICE filter only; scope it to the vehicles category if it can be
- **CapCar** — an alert exists but demands a MAKE; refused for now, see the table above
- **Carizy** — **no alert facility found** [Verified by the developer, 2026-08-27]. It does not need one: sitemap-pollable
- Aramisauto / VPauto / BCAuto — UNMEASURED

### Creating the alerts — the operational half, and it is where this goes wrong

**Recommended starting set: leboncoin, La Centrale, AutoScout24 — in that order, all three
email-only.** Together they cover the overwhelming majority of the French used-car market, private
and professional, with **zero anti-bot exposure**. Two of the three refuse a polling client outright
(La Centrale is DataDome, leboncoin 403s), so email is not a fallback here — it is the only route,
which is hard rule 4 arriving at the same answer from the other side. **ParuVendu** and **Autohero**
are the tier-2 additions once the shape is proven; do not start with five.

Five rules for creating them, each of which silently breaks the pipeline if missed:

1. **Create every alert on the WEB, never in the portal's mobile app.** An app alert delivers a PUSH
   notification, which reaches the phone and never reaches the mailbox — so the watcher sees a quiet
   market for ever while the developer sees alerts. Hard rule 2's shape, introduced by the operator
   rather than by the code. If the app is the only way to reach the setting, verify the email
   delivery box is ticked.
2. **Set the alert frequency to the HIGHEST the portal offers** (AutoScout24 offers 1 h / 12 h /
   24 h / 7 d — take 1 h). A daily digest costs a full day of latency on a market where an
   underpriced car is gone in hours, and this project's whole premise is minutes.
3. **Verify the `+car` address is ACCEPTED before relying on it** — see the routing section. If a
   portal refuses `+`, the fallback is a Gmail filter on the SAVED SEARCH NAME in the subject; give
   each search a distinctive name (`car-watch-lbc`, `car-watch-lc`, `car-watch-as24`) so that
   fallback exists before it is needed. Do NOT fall back on the link: leboncoin's `/vi/<id>.htm` is
   category-agnostic and cannot tell a car from a flat [Verified 2026-08-26].
4. **Never delete an alert email.** Until the parser is built the mailbox IS the corpus, and the
   awkward messages are the valuable ones — this repo's email parser cost four defects on the day a
   real message first reached it, behind 1 886 green tests.
5. **Take AutoScout24's price-drop alerts if offered separately.** A price drop is already a
   first-class event here (`price_history`), and it is the single cheapest signal of a motivated
   seller.

### Dead rows — dated, so nobody re-proposes them

- **Reezocar** — ceased sales **4 November 2024**. Was the German/Belgian import route with the paperwork handled [Verified: multiple 2026 write-ups]
- **Ayvens Carmarket** — **professionals only**, out of scope for a private buyer

### Alive, deliberately unmeasured

Renault/Dacia Occasions, Toyota Occasions, Das WeltAuto, VPauto,
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

### Decisions 9 and 10 — RULED 2026-08-26

**9 — the car §1 analog is CONFIRMED, and `accidenté réparé` is EXCLUDED with it.** Fail-closed set:
`VEI` (économiquement irréparable), `VGE` / "procédure VE", `gagé` / `opposition`, `pour pièces`,
`sans carte grise`, `CT non fourni` / non roulant — plus `accidenté réparé`. The last one is legal to
sell and often good value, so its exclusion is a **risk-appetite ruling, not a safety fact**, and it
is the one line to relax first if the yield disappoints. Bias toward not notifying: a wasted trip to
see a repaired write-off costs more than a missed bargain.

**HistoVec is CONTACT-TIME, never ingest-time.** `histovec.interieur.gouv.fr` (Ministère de
l'Intérieur, free) is the authoritative record of exactly these facts — successive owners,
gage/opposition/vol, CT dates with recorded mileage since Jan 2021, VEI/VGE procedures — but **the
report is generated by the OWNER** from the plate plus the registration document and shared as a
link. A listing does not carry it. So ingest must classify on TEXT signals, exactly as the tenure
classifier does, and HistoVec is a step the human takes before viewing. Do not design an adapter
around it.

**10 — auctions are OUT for now.** ⚠️ **REVERSED 2026-08-27 — see below.** Kept as written because the cost it names turned out to be the reason for the reversal. A lot needs a deposit and usually a physical viewing, so a
notification about one asks something very different of the reader than a listing does. Note the
cost of this ruling: **most of the measured-OPEN hosts are auction sites** (Alcopa, Agorastore,
Interencheres), while the ordinary retail portals are the ones refusing a polling client. Revisit
once the email-alert sources are proven.

### Decision 11 — RULED 2026-08-27

The three fields decision 7 named and never answered — fuel, gearbox, body type. **All three are
SCORE components. None is a portal filter and none is a disqualifier**, which is decision 7's
two-layer rule applied unchanged and hard rule 8 independently.

| Field | Ruling |
|---|---|
| Gearbox | **Automatic preferred.** Manual still arrives, scores lower |
| Fuel | **Petrol / hybrid preferred over diesel.** Diesel still arrives, scores lower |
| Body | **RANKED: `suv > break > berline`.** Highest rank scores most |

**The body preference is a RANK, not a set — and that shape already exists here.** It is
`commune_rank` on the rent side: a ranked member scores, an UNRANKED one scores zero on that
component and is *still notified*. So a citadine or a monospace is never rejected for being absent
from the list. Reuse `commune_rank`'s mechanism rather than writing a second one, exactly as
decision 6 rules for geography.

**Item 3 is UNCHANGED by this.** The three fields are left unset on the portal form, so the saved
searches already specified — ≤ 30 000 €, ≤ 7 years, ≤ 100 000 km, Île-de-France, both private and
professional — stand as written. Anything set at the portal is invisible to the scorer: a car that
scores 90 on every other axis but has the wrong gearbox would never arrive, and nothing would say it
had existed.

> **ONE FIGURE IS OWED AND IS DELIBERATELY NOT WRITTEN FROM MEMORY.** How hard diesel should be
> penalised depends on the Grand Paris **ZFE / Crit'Air** rules actually in force — an older diesel
> that cannot legally enter the zone is a different fact from one that merely burns diesel. Those
> rules have been revised and partly reversed several times and are **[Unverified]** here. Hard rule
> 1's spirit applies: verify against a live authoritative source (`service-public.fr`,
> `certificat-air.gouv.fr`) at build time. **Until then the diesel penalty is a plain preference and
> must not be presented in a notification as a regulatory restriction** — a wrong claim that a car
> is banned from Paris is the car-domain twin of a §1 false positive: it makes the tool
> untrustworthy in the one direction the reader cannot check for themselves.

### Decision 10 REVERSED — auctions are IN (2026-08-27)

Developer ruling, verbatim: *"i think i want to add auctions now"*. Decision 10's own text said
*revisit once the email-alert sources are proven*; this arrives earlier than that, which is the
developer's call to make.

**The cost decision 10 stated is the reason it was reversed.** That ruling noted that most of the
measured-OPEN hosts are auction sites while the retail portals refuse a polling client. So excluding
auctions did not merely drop some inventory — it left the car domain with **no polling source at
all**, and therefore no way to exercise the HTTP adapter the rent side already has. Auctions bring
that back.

**A LOT IS NOT A LISTING.** Four rules, each closing something the others open:

1. **The price is a MOVING BID.** The 30 000 € ceiling applies to the **estimate or reserve where
   the house publishes one**, and to the current bid otherwise. A lot that later crosses the ceiling
   is **recorded and never re-notified** — a rising bid is the price-RISE fact this store already
   distinguishes from a drop, and re-announcing it would train the reader to ignore the channel.
2. **A CLOSING TIME is mandatory in the notification, and a source that publishes none is refused
   fail-closed.** An alert about a lot whose deadline the reader cannot see is an alert they cannot
   act on — hard rule 2's shape at the display layer, and the exact reason the notification already
   carries `reasons[]` rather than a bare score.
3. **The §1 vehicle set will reject a large share of auction stock, and that is CORRECT.** `VEI`,
   `pour pièces`, `sans carte grise` and `CT non fourni` are not edge cases at a saleroom — they are
   ordinary inventory. Expect the yield to look poor and do not read that as a broken source; the
   `SourceHealth` baseline is measured on listings FETCHED, not on matches. **`accidenté réparé` is
   the first line to relax if the yield disappoints, and auctions are where that pressure will come
   from** — relax it deliberately, in a commit, not because a number looked low.
4. **Deposit and physical-viewing requirements are STATED, never used to filter.** Decision 10's
   original objection stands as a fact — a lot asks something different of the reader than a listing
   does — and the fix is to say so in the notification. Same shape as *"the rent ceiling is not
   checkable for this source"*, which is already how Logirep and leboncoin are handled.

**`family: auction`** joins `institutional | private` — the enum already exists and already carries
a source-family fact into the score and the display. Do not invent a second mechanism.

**Pro-only houses stay OUT, and that is a fact, not a preference.** Ayvens Carmarket, BCAuto
Enchères, Openlane, Autorola and Auto1.com sell to registered traders; a private buyer cannot bid.

### Autohero and CapCar — asked 2026-08-27

**Autohero — IN, tier 2, and it is the one that proves the HTTP path.** The best polling candidate
in the whole catalogue: robots open, and it publishes `sitemap_search.xml`. **Stated costs, all
three:** it is a RESELLER, so there are no private sellers on it; its prices sit above private-sale
because reconditioning and warranty are priced in; and because it sells at a fixed price and
delivers nationally, **the decision-6 geography filter is INERT on this source** — every lot
"matches" Île-de-France in the sense that it can be delivered there. Do not read a region match on
Autohero as evidence the geography filter works.

**CapCar — polling REFUSED, and one input is owed.** `Disallow: /trouver-une-voiture/*` is the
search itself, so the only way in is an email alert, and **whether CapCar offers one is UNMEASURED**
— a two-minute check on the site. Worth doing: CapCar is C2C with a professional intermediary
(inspection, paperwork, warranty on a private-seller car), which is inventory neither the pro
portals nor the pure-C2C ones carry. **Carizy** is the same shape, Renault-backed, and equally
unmeasured.

## Operator report — 2026-08-27 19:46, and what is left for the developer

The alerts of § "Creating the alerts" were created. Six of the eight rows below changed a catalogue
verdict, which is why the report is kept rather than summarised.

| Portal | Alert | What it changed |
|---|---|---|
| **Autohero** | created | Also the best polling candidate measured. The alert is a bonus, not the route |
| **Alcopa Auction** | created | **EMAIL ROUTE REFUSED 2026-08-28** — the alert carries no closing time. Expiry 27/09/2026 and the ~24/09 reminder are now OPTIONAL, not load-bearing; see the 16:40 entries |
| **Agorastore** | created | Price filter only; the site sells every category, so expect non-vehicles |
| **leboncoin** (cars) | created | And it produced a THIRD subject template that nothing routes — below |
| **Interencheres** | not created | Car filter too coarse. **Correct call** — its route is polling, not email |
| **CapCar** | not created | The form demands a MAKE. Refused for now, on decision 7's own ground |
| **Carizy** | not created | No alert facility exists — **and it turns out not to need one** |
| **ParuVendu** | — | Has a RENT section too. Answered below |

### The Alcopa expiry — SUPERSEDED 2026-08-28, kept as the record of what was decided

> **Read the `[2026-08-28 16:40]` entries first.** The alert's payload was captured and it publishes no
> closing time, so its email route is refused under rule 2 of the auction ruling and this deadline no
> longer protects anything load-bearing. The reminder is set and is left standing rather than
> cancelled, because the POLLING route is still UNRESOLVED. Everything below stands as written.

An alert that expires is hard rule 2's failure introduced by the portal instead of by the code: on
28/09 the source falls silent, and silence is exactly what a quiet saleroom looks like. Three
consequences, in the order they bite:

1. **A dated reminder is the primary control** — ~24/09, three days of slack. Not the code: the car
   domain is not built, so today there is no backstop at all.
2. **`SourceHealth` is a lagging backstop even once it exists.** `SOURCE_BROKEN` needs three
   consecutive empty passes against a non-zero baseline, so a source that expires quietly is
   detected days late — which is fine for a slow market and useless for an auction closing date.
3. **The date belongs in the config block**, printed by `doctor`. Proposed above, not ruled. A
   deadline that lives only in a calendar is one the tool cannot warn about.

### The leboncoin third template — do NOT write the filter yet

`TRANSAKAUTO MEAUX vous propose HYUNDAI i20 … à 11 490 € à Villenoy (77124)` matches neither
positive subject pattern, so it is routed by nothing and sits in the inbox. **That is not
necessarily a fault** — if the sender is `no.reply@leboncoin.fr` it is the 02:35 tripwire working,
first live confirmation, and the design's whole claim was that an unknown template would surface
where it is seen.

**Two things are needed before a single filter is written**, and the second is the gate this repo
already paid for once:

- **The `From:` header of one such message.** Different sender → a `From:` filter and nothing else
  changes (the 02:10 sender-routing ruling, unamended). Same sender → a third positive subject
  match on `vous propose`, never a negation.
- **One full `.eml` through `php tools/scrub-eml.php`.** The subject reads like a PAP message — one
  ad, title + price + `commune (NNNNN)` — not like a leboncoin digest. If that holds it is its own
  source block, not a parameter on the car source. Guessing which shape it is has a 50% failure rate
  and the failure is silent.

> **The standing weakness of subject routing, stated once so it is not rediscovered:** a subject
> filter IS a vocabulary, the very shape *"a title is a position, never a vocabulary"* warns about,
> and it needs extending every time the portal invents a template. It is tolerable ONLY because the
> default is the inbox. **A fourth template means stop extending the list** and give leboncoin a
> structural discriminator of its own — a `+car` alias for that portal alone, or a body-level match.
> Three misses is a pattern.

### Carizy: the dead row that came back, and then died again

Asking the alert question measured it, and the measurement took two steps that disagree — which is
the part worth keeping. Its search is disallowed and it has no alert, but its sitemap publishes
**1 341 URLs** (1 000 of them ad pages), none matching a disallow, with make, model and year in the
path. On that evidence the row was written **OPEN VIA SITEMAP** and committed in `25d245b`.

Then one ad page was fetched. It is **4 707 bytes of Nuxt SPA shell**: `window.__NUXT__` holds app
config only, the `<title>` is `carizy.com`, and the body contains **zero** occurrences of `€`, `km`,
`DACIA` or `SANDERO` — for a page whose URL names that exact car. Neither adapter in this project
can read it, and the sitemap's 1 000 ad URLs yield make/model/year **and nothing else**, which is
not a listing.

> **A sitemap proves that URLs EXIST. It never proves a page is READABLE.** The catalogue already
> separates *pollable* from *useful* — the Erilia row, 49 clean listings and zero in Île-de-France.
> This adds a third column between them: **readable**. `robots.txt` and a sitemap answer *may I
> fetch it*; only fetching one page answers *is there anything in it*. The row was OPEN on the
> strength of the first two and was already wrong when it was committed.

### What is left for you, in order

1. **The `From:` header of one `vous propose` message** — the only blocker on routing it. Ten seconds.
2. **One real `.eml` from each live car alert**, through `tools/scrub-eml.php` — **the step-by-step
   runbook is [`docs/ALERT-CAPTURE.md`](../ALERT-CAPTURE.md)**, which carries the Gmail export steps,
   the scrub command, what each capture must ANSWER, and the five rules for alerts not yet created.
   This is THE gate:
   the email parser was written blind once and cost four defects the day a real message reached it,
   behind 1 886 green tests. **The priority order lives in that runbook's own table and is NOT
   restated here** — an order written twice is an order that drifts, and this one did: the list that
   stood here until 2026-08-28 put Autohero third and Alcopa fourth, contradicting the
   `[2026-08-27 21:55]` ruling two screens above it. Alcopa is FIRST (its capture decides whether the
   email route is admissible at all, which the lot page left open) and Autohero is LAST (its polling
   payload is already confirmed complete, so its alert is a convenience rather than the route).
3. ~~**A calendar reminder for ~24/09** — renew the Alcopa alert.~~ **DONE 2026-08-28** (developer set it). The reminder is the only mechanism: there is no Alcopa source block and no expiry key in the schema, so `doctor` cannot warn. Deferred to the first car source block.
4. **Confirm the two leboncoin filters actually exist as FILTERS** (`Voitures` → car label,
   `à louer` → rent label), not merely as labels. The one-message ingestion cost accepted at 02:10
   lives in exactly that gap.
5. **Verify the alerts just created against rules 1, 2 and 4** of § "Creating the alerts": created
   on the WEB not in an app (an app alert delivers a push and never reaches the mailbox — the
   watcher then sees a quiet market for ever); frequency set to the HIGHEST offered; and **never
   delete an alert email** — until the parser exists the mailbox IS the corpus.
6. **ParuVendu rent — create the saved search now** with a distinctive NAME, even though the build
   waits. It costs three minutes and starts collecting the corpus. Route measured 2026-08-27:
   `https://www.paruvendu.fr/immobilier/recherche/location/appartement/` — allowed by robots,
   server-rendered, and the `Créer une alerte` button is on the results page itself.
7. **CapCar — one look at whether the make selector is multi-select** (or offers *toutes les
   marques*). If it is, tick everything and the refusal lifts entirely. **This got more important
   the same afternoon**: CapCar and Carizy were the catalogue's only two C2C-with-intermediary
   sources — a private seller's car, inspected, paperwork handled — and Carizy died on an
   unreadable page. Refusing CapCar now costs the whole class, not half of it. Must be checked in a
   browser: the alert form sits behind `/trouver-une-voiture/*`, which robots disallows.
8. **Agorastore — scope the alert to the vehicles CATEGORY if the form allows it.** Not tidiness:
   `SourceHealth`'s baseline counts listings FETCHED, so a feed that is 97% furniture stays green on
   the day the vehicle stock goes to zero — hard rule 2's exact failure. It is also the first source
   here that is not category-scoped by construction, and the alternative (a text discriminator for
   *is this even a vehicle*) is the vocabulary-guess class that has cost this repo three fixes.
9. Unchanged and parked: **AL'in** (item 1), still blocked on the NUR question, which is itself a §1
   question before it is an input.

### Other sources — the honest read, 2026-08-27

**Rent is near saturation, and that is the useful answer.** Eight sources are live; Track 1
(institutional) is measured out with every row dated; the four private portals plus the two HTML
landlords already cover the agency stock, and cross-portal dedup merges what they duplicate. The
remaining upside is narrow:

| Candidate | Read |
|---|---|
| **ParuVendu** | The one worth doing. Direct-from-owner, the PAP gap. Recommended above |
| Logic-Immo, Avendrealouer, Figaro Immobilier | Same agency stock, syndicated. Dedup would merge most of it — **low marginal value** [Inferred, not measured] |
| Locservice, Gens de Confiance | Subscription/matching services, not alert feeds. Out on shape, not on quality |
| Facebook Marketplace | No email alerts, and automated access is ToS-hostile. Out under hard rule 5's posture |
| `demande-logement-social.gouv.fr`, Bienvéo, Loc'Annonces | **Out of scope entirely by §1** — social-housing channels. Do not re-propose |

**Cars still have real headroom, and it is all in the polling column** — every retail portal worth
having refuses a client, and every open host is an auction house, a reseller or an aggregator:

| Candidate | Read |
|---|---|
| **leparking.fr** | The biggest single breadth win: WIDE OPEN, and a meta-search across many portals. ⚠️ Aggregator — the Jinka caveat in full, a truncated description can lose a `VEI` the original ad carried. Needs its own §1 evaluation first |
| ~~Carizy~~ | **Refused, measured today.** Sitemap-open, ad page unreadable (SPA). Dead unless someone chases the bundle → API |
| **Interencheres** | Auctions are IN; the *ventes* pages sit outside the disallowed `/recherche/*`. Unmeasured, worth one fetch |
| **Autohero** | **Measured readable 2026-08-27** and carrying schema.org JSON-LD. This is the source the first HTTP adapter should be built against — no new adapter code needed |
| **Agorastore** | SPA, but its API host `api.auctelia.com` is open and named in the page. Alive, one step further out |
| **Alcopa** | Readable lots, 5 839 of them — but the mandatory closing time was not on the one page fetched. **Unresolved**, check a lot in a live sale |
| Aramisauto, Autosphere, VPauto, Das WeltAuto, Renault/Dacia Occasions, Toyota Occasions, encheres-vo.com, Ouest-France Auto, Caradisiac, L'Argus | Alive, **unmeasured**. One `robots.txt` fetch each settles them; do not add them to the plan as candidates until one has |
| mobile.de / AutoScout24.de | German import route. Real inventory, but paperwork and travel change what a notification is asking of the reader — the Reezocar niche, and Reezocar is dead |
| Spoticar, La Centrale | 403 / DataDome. **Refused by ruling**, not by capability. Email-alert route only |
| Ayvens Carmarket, BCAuto, Openlane, Autorola, Auto1 | **Trade-only.** A private buyer cannot bid. A fact, not a preference |

**The recommendation is not to add more sources yet.** Four car alerts now exist and none of their
payloads has been read. Every source added before the first `.eml` is config written blind, which is
the one thing this repo has a measured price for.
