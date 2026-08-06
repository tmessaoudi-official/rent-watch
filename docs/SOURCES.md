# Source catalogue — verified 2026-08-06

> **Every status in this file was measured, not recalled.** `CLAUDE.md` hard rule 1 forbids writing an
> endpoint from memory, so each domain below was fetched once with an honest User-Agent
> (`rent-watch/0.1 (personal housing search; +<repo url>)`), ~1.2 s apart, and each Tier A site's
> `robots.txt` was read before it was listed as pollable.
>
> **A `200` here means "the site exists and answered".** It does **not** mean an endpoint has been
> found. No search/XHR endpoint has been reverse-engineered yet — that is per-source work, done with
> `/add-source`, and every `url:` in `config/sources.yaml` stays `REMPLACER` until it is confirmed live.
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

---

## Tier A — institutional, intermediate / LLI. **Where this project earns its keep**

Nothing on the market aggregates these. Status column = HTTP response to a single polite GET.

| # | Source | Domain | HTTP | Tenure mix | Notes |
|---|---|---|---|---|---|
| **A1** | **In'li** | `www.inli.fr` | **200** | **Pure LLI** | ⭐ The flagship. ~60 000 units across IDF, **covers both 78 and 95**, rents ~20–30 % below market. `robots.txt` disallows only `/espace-membre/`. `mixed_tenure: false`. **Build first.** |
| **A2** | **ICF Habitat Novedis** | `www.icfhabitat.fr/patrimoine/icf-novedis` | **200** | **Intermediate + loyer libre only** | ⭐ **Found this round; nearly missed it.** ICF Habitat's *non-social* arm — 10 000 units aimed explicitly at *"personnes dont les revenus dépassent les plafonds sociaux"*. SNCF group, so stock clusters on rail corridors, which fits the boucle de Seine. Second-highest value after In'li. |
| **A3** | **CDC Habitat** | `www.cdc-habitat.fr` | **200** | Mixed | ⚠️ **`robots.txt` disallows `/Recherche/show/`** — very likely the search-results path. Must be respected: if the listing endpoint lives under it, this source becomes email-alert or manual, not polled. Resolve before enabling. |
| **A4** | **AL'in** (Action Logement) | `www.al-in.fr` | **200** | Mixed | Employer-reserved stock. Likely needs an authenticated session. High value (less competition), hardest to build. |
| **A5** | **Seqens** | `www.seqens.fr` | **200** | Mixed | Action Logement group. Strong 78/95 footprint. `robots.txt` clean (`/wp-admin/` only). |
| **A6** | **Immobilière 3F** | `www.groupe3f.fr` | **200** | Mixed | Action Logement group. Large IDF portfolio. |
| **A7** | **1001 Vies Habitat** | `www.1001vieshabitat.fr` | **200** | Mixed | **Domain corrected** — not `1001vies-habitat.fr`. `robots.txt` sets `Content-Signal: use=reference`, blocks named AI crawlers, `Allow: /` for a generic client. |
| **A8** | **Antin Résidences** | `www.antin-residences.fr` | **200** | Mixed | Action Logement group. |
| **A9** | **Vilogia** | `www.vilogia.fr` | **403** | Mixed, has an intermediate line | Blocks a plain client. Treat as Tier B in practice: email alert, or skip. |
| **A10** | **Batigère IDF** | `www.batigere.fr` | **200** | Mixed | Second tier. |
| **A11** | **Toit et Joie** | `www.toitetjoie.com` | **200** | Mixed | Second tier. |
| **A12** | **Logirep / Polylogis** | `www.logirep.fr` | **200** | Mixed | Second tier. |
| **A13** | **Erilia** | `www.erilia.fr` | **200** | Mixed | Second tier. |
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
