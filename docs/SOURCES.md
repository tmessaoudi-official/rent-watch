# Source catalogue — verified 2026-08-06; A2/A3 re-measured 2026-08-20; A5–A13 re-measured 2026-08-20

> **NINE rows changed on 2026-08-20, and they all changed for one reason: a `200` was read as
> a feed.** The second pass (A5–A13) is why the Tier A count is now much smaller than it looks:
> Seqens and 3F publish no vacancies at all on their own domains, and the reason turned out to be
> structural rather than per-site — see § "The Action Logement ESH do not publish their own
> vacancies". One genuinely new feed came out of it, **A6b Cityloger**, found from 3F's own page.
>
> **Two rows changed earlier that day and both changed for the same reason: a `200` was read as
> a feed.** A2 answers 200 and publishes no vacancies at all; A3 answers 200 and disallows far
> more than this table said, while advertising a different route in its own sitemap. Neither
> was knowable without fetching, which is what the preamble below already says — it just had
> not been done for those two.

> **Every status in this file was measured, not recalled.** `CLAUDE.md` hard rule 1 forbids writing an
> endpoint from memory, so each domain below was fetched once with an honest User-Agent
> (`rent-watch/0.1 (personal housing search; +<repo url>)`), ~1.2 s apart, and each Tier A site's
> `robots.txt` was read before it was listed as pollable.
>
> **A `200` here means "the site exists and answered".** It does **not** mean an endpoint has been
> found. No search/XHR endpoint has been reverse-engineered yet — that is per-source work, done with
> `/add-source`, and every `url:` in `config/sources.json` stays `REMPLACER` until it is confirmed live.
>
> Scope, settled 2026-08-06 (`docs/OPEN-QUESTIONS.md` Q4): **LLI + LIBRE in scope. PLAI, PLUS, PLS,
> ANRU, ANAH out — no social housing.** Every mixed-tenure source therefore carries a real risk of
> surfacing something out of scope, which is what the classifier and `mixed_tenure: true` exist for.

---

## The single most useful thing this round found

**HTTP 403 to an honest non-browser User-Agent tracks the tier almost perfectly.**

| | 403 (blocks a plain client) | 200 (answers) |
|---|---|---|
| **Tier A** institutional | 1 of 15 (Vilogia) | 14 of 15 |
| **Tier B** private portals | 5 of 12 | 7 of 12 |

Every 403 in Tier B is a portal the brief already said to reach by **email alert** rather than HTTP —
Leboncoin, PAP, Logic-Immo, A Vendre A Louer, Gens de Confiance. So the architecture's central bet is
now backed by measurement rather than assumption: the sites that resist automation are exactly the ones
where a native alert subscription is the correct route, and the sites worth polling mostly welcome it.

This is not a reason to work around a 403. It is the reason not to have to.

**Read this claim narrowly.** It says a 403 predicts *which route to use*, and it holds. It does NOT
say a 200 predicts a pollable feed — five Tier A rows answered 200 and turned out to publish no
availability this project can poll (A2, A5, A6, A7, A10). "The sites worth polling mostly welcome it"
was the optimistic reading, and the tally that survives measurement is **3 of 15 built** (In'li, CDC
Habitat, Cityloger).

---

## TWO TRACKS, kept separate end to end (ruled 2026-08-06)

*"both! but have them separate! with two emails and two notification!"*

| | **Track 1 — INTERMEDIATE** | **Track 2 — PRIVATE** |
|---|---|---|
| Tenure | LLI / logement intermédiaire | LIBRE / loyer libre |
| Sources | Tier A below | Tier B below |
| Access | HTTP JSON endpoints (mostly welcome it) | Email alert over IMAP (mostly 403 a plain client) |
| Mailbox | its own | its own |
| Notification | its own | its own |
| Seen-set / dedup | **per track** — a flat listed by In'li AND on SeLoger is two findings, not a duplicate, because the application route differs | |

The split is a first-class concept, not a filter flag: two mailboxes, two notification targets, two
digests, and `--track intermediate|private|both` on every command.

### The Action Logement clarification — this shrinks Track 1 a lot

*"enumerate all action logement in ile de france"* — measured answer: **Action Logement Immobilier holds
42 ESH plus exactly ONE logement-intermédiaire subsidiary.** That one is **In'li**.
[Source: groupe.actionlogement.fr/nos-filiales-immobilieres, via search 2026-08-06 — the page itself
403s a plain client.]

So "all Action Logement" resolves to a much cleaner answer than a 42-entity list:

- **In'li IS the Action Logement intermediate arm.** One entity, and it is the flagship of Track 1.
  ~60 000 units across IDF, covering both 78 and 95.
- The other **42 are ESH — Entreprises Sociales pour l'Habitat, i.e. social landlords.** They are out of
  scope by the no-social-housing ruling. They appear in Tier A below **only** because several
  (Seqens, Immobilière 3F, 1001 Vies, Antin) publish a *minority* of intermediate stock alongside their
  social stock. That is precisely the `mixed_tenure: true` case, and it is why the classifier exists.
- **For other regions** ("eventually any region"): In'li has regional arms — **In'li Aura** is confirmed
  (Auvergne-Rhône-Alpes, Annecy). *[Unverified: the full list of In'li regional entities.]* The design
  consequence is the useful part — region must be **config, not code**, so a new region is a
  `config/sources.json` block plus a commune list, never a code change.

### The Action Logement ESH do not publish their own vacancies — they delegate to AL'in (measured 2026-08-20)

Seqens (A5) and Immobilière 3F (A6) were probed independently and both ended at the same place: their
own sites carry no vacancy feed, and their "I'm looking for a home" route links out to
**`https://al-in.fr/`**. Antin (A8) is the same group and the same shape as far as one fetch shows.

Three consequences, and the first is the one that changes plans:

- **A4 AL'in is not "one more source" — it is the ONLY route to the Action Logement ESH stock.** It was
  ranked fourth on the strength of being employer-reserved and hard; it should be read as the gateway to
  A5, A6 and A8 combined. Its authenticated session stops being an obstacle to one source and becomes
  the price of an entire group.
- **In'li is the exception that explains the rule.** It publishes its own feed because it is the group's
  *intermediate* arm and allocates directly — no commission, no SNE number. The ESH delegate because
  their stock is allocated by commission, through the social channel.
- **A `200` still is not a feed.** Five rows in this table (A2, A5, A6, A7, A10) were ranked on
  portfolio size and each turned out to publish no availability this project can poll. The cheap
  pre-check that would have caught them, before any deep crawl: on WordPress, ask the REST API
  directly — `wp-json/wp/v2/types` enumerates every post type including custom ones, so a site with
  only core types has **no listings to poll and no JS-rendered search hiding one** (1001 Vies, settled
  2026-08-21); `sitemap_index.xml` is the weaker version of the same question (Seqens); and on any
  site, scan the candidate index page for `€`, `m²` and `disponib` — a directory of buildings has none
  of the three.

### A client-rendered search is not a dead end — follow the widget to its API, then check THAT host (2026-08-21)

Batigère (A10) is the shape worth knowing, because the first three signals all said "keep going" and
the fourth stopped it dead. Its offers subdomain answers 200 with ~197 KB and **zero** `€`/`m²`, which
means client-rendered rather than absent — so the question becomes *which* backend renders it. The
route to that answer is three greps and costs two fetches:

1. Grep the page for third-party `script src` hosts. Batigère's search is a vendor widget:
   `static.app.quadral-eservices.fr/static/quadral-map-search-engine`.
2. Fetch the widget bundle and grep it for absolute URLs and quoted paths. It names its own backend —
   **`https://api.app.quadral-eservices.fr/api`**, with `/offers/offers`, `/offers/programs`,
   `/offers/references`, `/offers/leads`.
3. Check **that** host's `robots.txt`, and probe one path unauthenticated. Both answers were stops.

Two rules fell out of it, and they generalise past Batigère:

- **The robots status code is not one verdict, it is two.** RFC 9309 §2.3.1.4: an *unreachable* robots
  (5xx) means a crawler **MUST assume complete disallow** — the standard is stricter than this project
  is, and `api.app.quadral-eservices.fr` answers **500**. §2.3.1.3: an *unavailable* robots (4xx) means
  a crawler MAY access, so the **403** on `offres.batigere.fr` is blocked by this repo's own posture
  rather than by the standard. Record which one applies; a row that blurs them overclaims.
- **Quadral is multi-tenant, so this is a lead and not only a dead end.** `api.app.quadral-eservices.fr`
  serves Batigère through a `/offers/leads/batigere` path, which implies sibling landlords behind the
  same API. Any future candidate whose search is a `quadral-` widget is the same 401 and the same 500 —
  check for it early. And a 500 can be transient: re-check before treating A10 as permanent.

## Tier A — Track 1: institutional, intermediate / LLI. **Where this project earns its keep**

Nothing on the market aggregates these. Status column = HTTP response to a single polite GET.

| # | Source | Domain | HTTP | Tenure mix | Notes |
|---|---|---|---|---|---|
| **A1** | **In'li** | `www.inli.fr` | **200** | **Pure LLI** | ⭐ The flagship. ~60 000 units across IDF, **covers both 78 and 95**, rents ~20–30 % below market. `robots.txt` disallows only `/espace-membre/`. `mixed_tenure: false`. **Build first.** |
| **A2** | **ICF Habitat Novedis** | `www.icfhabitat.fr/patrimoine/icf-novedis` | **200, but NO VACANCY FEED** | **Intermediate + loyer libre only** | ⛔ **NOT POLLABLE — measured 2026-08-20, three levels deep.** ICF Habitat's *non-social* arm (10 000 units aimed at *"personnes dont les revenus dépassent les plafonds sociaux"*, SNCF group, rail-corridor stock) was ranked second on PORTFOLIO value. Its site publishes a **patrimoine directory, not availability**: `/patrimoine/icf-novedis` → `/patrimoine/filiale/icf-novedis/78-yvelines` → `/patrimoine/localites/icf-novedis/78500-sartrouville` all list *résidences* ("Il y a 8 résidence(s)"), with **zero rents, zero surfaces and zero occurrences of `disponib`** on any of the three. `robots.txt` is stock Drupal and irrelevant here — there is nothing to poll. Remaining routes, in order: an email alert if ICF offers one, or the portals. `novedispm.fr` is the third-party property-management arm, a separate site — *[Unverified: not fetched; capped deliberately rather than crawled]*. |
| **A3** | **CDC Habitat** | `www.cdc-habitat.fr` | **200** | Mixed — **and genuinely so** | ✅ **LIVE since 2026-08-20 — the second verified source, and the first mixed-tenure one.** robots is **stricter than this table recorded**: it disallows `/Recherche/show/`, `/Recherche/search` **and seven search QUERY PARAMETERS by name** (`?cdTypeBien`, `?nbSurfaceMin`, `?nbSurfaceMax`, `?cdCategorieLogement`, `?nbPiece`, `?nbLoyerMin`, `?nbLoyerMax`), so the parameterised search is off limits entirely. What is pollable is the route the site **advertises in its own `sitemap.xml`**: the lowercase, query-free `/recherche/location/<region>` tree — robots path matching is case-sensitive, so `/Recherche/…` does not cover `/recherche/…`. Server-rendered, `134 logements disponibles` for Île-de-France at 16/page, paginated by **path** (`/page-2/`, never a query string). Frozen at `tests/fixtures/cdc_habitat/search.html`, with `robots.txt` frozen beside it and asserted per page by test. |
| **A4** | **AL'in** (Action Logement) | `www.al-in.fr` | **200** | Mixed | Employer-reserved stock. Likely needs an authenticated session. High value (less competition), hardest to build. |
| **A5** | **Seqens** | `www.seqens.fr` | **200, but NO VACANCY FEED** | n/a | ⛔ **NOT POLLABLE — measured 2026-08-20.** `robots.txt` is clean (`/wp-admin/` only), and there is nothing behind it to poll. Its Yoast `sitemap_index.xml` enumerates every public post type — `post`, `page`, `beetween`, `evenement`, `job`, `metiers`, `publication`, `question_faq`, `realisation`, `theme_faq`, `profil_ideal` — and **none of them is a listings type**. The whole `/louer/` section offers exactly three routes: a *local commercial*, a *parking*, and *un logement social* — and that last page's own outbound link is **`https://al-in.fr/`**. `patrimoine/nos-residences-hlm/` is a résidence directory with **zero** `disponib`, `€` or `m²`, i.e. A2's shape exactly. Seqens does not publish its vacancies; it delegates them to AL'in (A4). |
| **A6** | **Immobilière 3F** | `www.groupe3f.fr` | **200, but NO VACANCY FEED ON THIS DOMAIN** | Mixed | ⛔ **The corporate site is not pollable — measured 2026-08-20.** Drupal, stock robots (`/search/` and `/search?` disallowed, `/location` allowed). `/location` is editorial: zero `€`, `m²` or `pièce`. `/je-cherche-un-logement` carries no listings either and its outbound routes are **`al-in.fr`**, `logement-actionlogement.fr` and **`cityloger.fr`**. The stock is real; it is published elsewhere — see A6b. |
| **A6b** | **Cityloger** (the 3F group's own lettings platform) | `www.cityloger.fr` | **200 — REAL FEED, verified 2026-08-20** | Mixed, national | ✅ Pollable today, and the shape is good: `robots.txt` disallows only `/composants/ /classes/ /include/ /dpe/ /newdpe/` — nothing near the search. Results are **server-rendered** and paginated by PATH through an infinite-scroll partial, `resultats-location-{page}-defaut-`, which is **stateless GET** (pages 2 and 3 return disjoint sets with no cookie or token) and terminates cleanly on an empty page. Cards carry filiale, type, rooms, postcode, commune, address, rent **cc**, and floor. **The catch is volume and tenure placement:** the whole national inventory is **51 rentals**, of which **3 are IDF** (2×92, 1×77) — and the tenure lives on the DETAIL page, never on the card. Detail pages do carry the tier-1 field (`Financement`: `LI15P` on the Antony T4, `PEXNC` on an Occitanie social one). ⚠️ Those detail pages also carry generic boilerplate — *"Numéro de demande de logement social"*, *"Commission d'attribution"*, *"catégories de logements sociaux"* — sitting on a listing whose own financement code is **intermediate**. That is the CDC `au plus près` failure class again, on a new surface. |
| **A7** | **1001 Vies Habitat** | `www.1001vieshabitat.fr` | **200** | Mixed | ⛔ **NOT POLLABLE — settled 2026-08-21, and it is the A5/A6 delegation pattern a third time.** The 2026-08-20 row left this open because "a JS-rendered search would not show in either signal"; the WordPress REST API answers that directly. `wp-json/wp/v2/types` enumerates **only core types** — `post`, `page`, `attachment`, `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`, `wp_font_family`, `wp_font_face` — so there is no listings post type to poll, and no custom one hiding behind the sitemap. Nor is the search rendered client-side by somebody else's widget: `/devenir-locataire/` and `/nos-residences/` carry **zero** `€`, `louer` or `annonce`, and their only third-party script hosts are a cookie banner, YouTube, LinkedIn and Twitter — no offers widget of the Quadral shape found at A10. What `/devenir-locataire/` links out to is **`www.demande-logement-social.gouv.fr`**, which is out of scope entirely (§1, hard rule 5): 1001 Vies routes its lettings through the SNE, exactly as Seqens and 3F route theirs through AL'in. **Domain corrected** — not `1001vies-habitat.fr`. `robots.txt` sets `Content-Signal: use=reference` and blocks named AI crawlers; `rent-watch/1.0` is a generic client and unaffected — moot now. |
| **A8** | **Antin Résidences** | `www.antin-residences.fr` | **200** | Mixed | ⛔ **The one lettings route this table recorded does not exist — measured 2026-08-21: `/louer-acheter` answers 404** (a 109 KB styled error page, which is why a size-only check would have passed it). Zero `€`, zero `disponib`, and no outbound `al-in.fr` link on that page to confirm the delegation prediction either way. Action Logement group, so the A5/A6 finding still predicts AL'in delegation — but that is now a prediction with one refuted route behind it, not a measurement. Do not rank it as pollable. *[Unverified: the real lettings route, if any, was not searched for — capped deliberately rather than crawled.]* |
| **A9** | **Vilogia** | `www.vilogia.fr` | **403** | Mixed, has an intermediate line | Blocks a plain client. Treat as Tier B in practice: email alert, or skip. |
| **A10** | **Batigère IDF** | `www.batigere.fr` | **200** | Mixed | ⛔ **NOT POLLABLE — measured 2026-08-21. It was ranked ⭐ best-remaining on a 200 and a page size; the feed behind it is authenticated and its host forbids crawling.** `offres.batigere.fr` is **Liferay**, and the search is a third-party widget — `static.app.quadral-eservices.fr/static/quadral-map-search-engine` — whose bundle names its own backend: **`https://api.app.quadral-eservices.fr/api`**, paths `/offers/offers`, `/offers/programs`, `/offers/references`, `/offers/leads`. Two independent stops, either alone sufficient: (1) **`api.app.quadral-eservices.fr/robots.txt` answers 500**, and RFC 9309 §2.3.1.4 is explicit that an *unreachable* (5xx) robots means a crawler **MUST assume complete disallow** — this is the standard's own conservative branch, not merely this project's posture; (2) a plain `GET /api/offers/offers` answers **401**, so the feed is not public and the only way in would be replaying the widget's credential, which hard rule 5 refuses outright. The **403** on `offres.batigere.fr/robots.txt` (Microsoft-Azure-Application-Gateway) is a weaker stop and should be read as such: RFC 9309 §2.3.1.3 treats 4xx as *unavailable*, under which a crawler MAY access — it is this repo's stricter posture that blocks it, not the standard. No email-alert fallback either: `alerte` appears **zero** times on the offers page. A 500 can be transient, so this row invites a re-check on another day rather than closing the source forever. |
| **A11** | **Toit et Joie** | `www.toitetjoie.com` | **200** | Mixed | Second tier. Homepage marker scan 2026-08-20: zero `€`, zero `m²`; the four `disponib` hits are on `/Nos-offres-d-emploi` (jobs). No lettings route found from the homepage. |
| **A12** | **Logirep / Polylogis** | `www.logirep.fr` | **200** | Mixed | Second tier. Homepage marker scan 2026-08-20: two `€` and two `m²` hits, no lettings route in the markup. Weakest of the "some markers" group. It is now the LAST unmeasured Tier A candidate rather than a deferred one — A10 is dead, so "check only after A10" no longer orders anything. Settle it with the `wp-json/wp/v2/types` / widget-follow pre-check above before ranking it. |
| **A13** | **Erilia** | `www.erilia.fr` | **200** | Mixed | Second tier. Homepage marker scan 2026-08-20: zero `€`, `m²`, `disponib`; `/decouvrir-erilia/nos-offres` is corporate, not lettings. |
| **A14** | **RIVP** | `www.rivp.fr` | **200** | Mixed | ⚠️ Paris-focused — **outside the commune filter**. Listed for completeness; do not enable unless the commune set expands into Paris. |
| **A15** | **Val d'Oise Habitat** | `www.valdoisehabitat.fr` | **200** | Predominantly **social** | ⚠️ A departmental office. Mostly out of scope by Q4. Low expected yield; enable last, if ever. |

### Merged away — do NOT add these as sources

Verified this round; adding them would produce duplicate or dead sources:

- **Coopération et Famille**, **Logement Français**, **Logement Francilien** → all three merged into
  **1001 Vies Habitat** in 2018. They are A7, not separate sources.
- **Efidis**, **Osica** → absorbed into **CDC Habitat** (A3).
- **France Habitation** → absorbed into **Seqens** (A5).
- **Ampere Gestion** is a CDC Habitat *asset manager* for LLI portfolios, not a letting platform. It has
  no listings to poll. Not a source.

---

## Tier B — private portals (LIBRE). In scope since Q4

**Email-alert ingestion is the primary path** — within ToS, no bot to detect, *faster* than polling
because alerts fire on publication, and immune to markup churn. You subscribe once to each portal's own
alert, pointed at a dedicated mailbox; `email_alert` ingests over IMAP.

| # | Portal | Domain | HTTP | Notes |
|---|---|---|---|---|
| **B1** | **SeLoger** | `www.seloger.com` | **200** | Largest IDF inventory. AVIV group. |
| **B2** | **Leboncoin** | `www.leboncoin.fr` | **403** | Where non-agency private landlords actually post. Email alert only. |
| **B3** | **PAP** | `www.pap.fr` | **403** | *Particulier à particulier* — no agency fees. Email alert only. |
| **B4** | **Bien'ici** | `www.bienici.com` | **200** | Map-first, agency consortium. |
| **B5** | **Logic-Immo** | `www.logic-immo.com` | **403** | ⚠️ **Same group as SeLoger (AVIV)** — expect heavy duplicate overlap with B1. The cross-portal dedup case in the brief is mostly this pair. |
| **B6** | **A Vendre A Louer** | `www.avendrealouer.fr` | **403** | Email alert only. |
| **B7** | **Figaro Immo** | `immobilier.lefigaro.fr` | **200** | Smaller IDF rental inventory. |
| **B8** | **ParuVendu** | `www.paruvendu.fr` | **200** | Long tail. |
| **B9** | **Locservice** | `www.locservice.fr` | **200** | Tenant-posts-criteria model rather than listing search — a different shape; may not fit the adapter contract cleanly. |
| **B10** | **Flatlooker** | `www.flatlooker.com` | **200** | Online agency, remote-viewing. Small inventory, low duplication with the majors. |
| **B11** | **Gens de Confiance** | `www.gensdeconfiance.com` | **403** | Closed community, needs an account. Email alert only. |

### Agency networks — deliberately NOT separate sources

Foncia, Orpi, Century 21, Laforêt, Guy Hoquet, Nexity, Citya, Stéphane Plaza. Each has its own site, but
they **feed the portals above**, so adding them multiplies duplicates for very little unique stock. Add
one only if a specific agency is found to withhold listings from the portals.

### Out of scope entirely

| Source | Why |
|---|---|
| `demande-logement-social.gouv.fr` | Social-housing channel. Violates the Q4 ruling and `CLAUDE.md` §1. |
| **Bienvéo** | Social-housing channel (AORIF). Same reason. |
| Studapart, Lodgis, Morning Croissant | Student / furnished / short-stay — excluded by filter F9 (`meubl`, `residence etudiante`). |
| Facebook Marketplace & groups | No usable API, and automated access is against ToS. |
| **Jinka** | See § "Honest note" — it is a *competitor*, not a source. Scraping an aggregator would also be a ToS problem. |

---

## Honest note: for Tier B, a product already exists

**Jinka** (`www.jinka.fr`, HTTP 200) aggregates SeLoger / Leboncoin / PAP / Bien'ici and alerts on new
matches — roughly the Tier B half of this project. *[Unverified: its current feature set, filter
granularity and pricing were not checked.]*

What **nothing** does is Tier A. In'li, ICF Novedis, CDC Habitat, AL'in and the Action Logement group
landlords each publish only to their own site, and no aggregator covers them.

So the defensible framing is:

- **Tier A is the differentiated half.** Build it regardless.
- **Tier B is the "already solved, but not the way I want it" half.** Worth building for one unified
  filtered list with your exact criteria and dedup across both families — but that is a convenience
  argument, not a capability one, and it should be a deliberate choice rather than a default.

---

## What is NOT decided by this file

- No endpoint has been reverse-engineered. Every source starts `enabled: false` with `url: REMPLACER`.
- Build order, which Tier B portals to subscribe to, and the mailbox — see `docs/OPEN-QUESTIONS.md`.
- Whether CDC Habitat's search path is inside its `robots.txt` `Disallow` — **must be resolved before
  A3 is enabled**, and if it is, A3 moves to the email-alert route.
