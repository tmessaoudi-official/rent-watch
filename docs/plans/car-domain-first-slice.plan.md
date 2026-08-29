# Car domain — first slice Plan

> The developer's go (2026-08-29 22:20, `AskUserQuestion`, recorded in
> `scout-rename-and-car-domain.plan.md`): **build the Vehicle domain as ruled, starting with the two
> sources that have BOTH a real payload and a readable route** — ParuVendu car (email) and Autohero
> (polling). Every ruling this plan implements is in that file (decisions 5–11, auctions IN with four
> rules, mailbox routing on the sender); this file is the BUILD record and owns nothing that was ruled
> there.

## Decisions Log

- [2026-08-29 22:30] MEASURED, the two inputs the slice is built against, both read through the
  project's own parser rather than eyeballed:
  - **ParuVendu car alert** (`info@paruvendu.fr`, ~every 2 h): `text/plain`, **3 cards for a
    subject stating 42** — the La Centrale truncation shape, 93 % — each card a fixed layout: title
    line → `21 000€` → `4x4 - SUV - Essence - Année 2023 - 26 000 km` → `Voir l'annonce`, one real
    ad link `/a/voiture-occasion/<make>/<model>/<id>` (identity = the link). **No location and no
    seller type on the card.** The card is preceded by the alert's own criteria line
    (`Jusqu'à 30 000 € … A partir de 2019 … Jusqu'à 100 000 km`) — the PAP trap: a first-match
    reader for price/year/km would read the SEARCH FLOOR. Positional anchors per card, never a
    body-wide scan.
  - **Autohero lot page**: one `application/ld+json` block of `@type: Vehicle` — `offers.price`
    (int, EUR), `brand`, `model`, `bodyType`, `dateVehiclefirstregistered` (`YYYY-MM`),
    `vehicleEngine.fuelType`, `vehicleTransmission` (`Boite de vitesse automatique`, no circumflex),
    `mileageFromOdometer` (`136 278 KMT` — narrow no-break space, UN/CEFACT unit), `sku`. **No
    location.** `sitemap_search.xml` lists **3 387** lots as `/fr/<make>-<model>/id/<uuid>/`.
- [2026-08-29 22:30] AGREED (design, under the rulings): **decision 6's geography is INERT on both
  first-slice sources** — neither payload carries a postcode — and hard rule 9 applies: an unknown
  location never rejects. The `postcode_prefixes` mechanism is reused verbatim so a source that
  does state one (La Centrale's bare departement) is filtered the day it lands.
- [2026-08-29 22:30] AGREED (design): **a second domain is a second set of classes, not a
  parameterisation of the certified rent path.** `src/php/Vehicle/` carries `VehicleListing`,
  `VehicleSnapshot`, `VehicleClassifier` (the §1 vehicle set, non-overridable, same shape as
  `TenureClassifier`), `VehicleCriteria` + `VehicleScorer`, `VehicleStore`, `VehicleSource`, the two
  adapters, `VehiclePipeline`, `VehicleFormatter`; `Cli/VehicleScout` behind `scout --domain=car`.
  Shared BYTE-IDENTICAL: `Adapters/Mail/*` (mailboxes, `EmailMessage`, feed freshness),
  `Adapters/Http/*` (client, robots), `Core/Notify/*` (channels, notifier), `Core/Text`, `Core/Redact`,
  `Core/Pacer`, `Core/Heartbeat`, `Cli/WatchLoop`, and the run-log / health / alert half of `Store`,
  which `VehicleStore` COMPOSES on the car database rather than re-implementing. The only edit to
  the rent side is the one-line dispatch in `Scout::run()` and extracting the channel builder into
  `Cli/ChannelFactory` so both CLIs construct channels from one place.
- [2026-08-29 22:30] AGREED (design): **own database, own config, own heartbeat marker.**
  `CAR_SCOUT_DB` (default `state/car-watch.sqlite3`), `config/car/criteria.json` +
  `config/car/sources.json`, `state/car-heartbeat.txt`, `[car-watch]` subject prefix, the same
  IMAP account with `CAR_IMAP_MAILBOX` (default `car-watch/portails`) — the folder the sender
  routing already fills. A second compose service `car-scout` runs the same image with
  `--domain=car run --watch`.
- [2026-08-29 22:30] AGREED (design): **Autohero is a SITEMAP-INDEXED source with a novelty gate**,
  the detail-hydration pattern applied to a whole source: the sitemap is the index (free), a lot
  page is fetched only for a URL not yet in the car seen-set, behind `lot_budget_per_pass`
  (default 50), at `rate_limit_ms` 2000, robots-checked. Cold start is 3 387 novel lots ≈ 68
  passes ≈ a day; steady state is the day's new lots. Rule 1 of the auction ruling does not apply
  (fixed price); the rest of the car criteria do.
- [2026-08-29 22:30] AGREED (design): **the vehicle §1 set is a classifier, not config** — `VEI`,
  `VGE` / `procédure VE`, `gagé` / `opposition`, `pour pièces`, `épave`, `sans carte grise`,
  `CT non fourni` / `non roulant`, `accidenté` (réparé or not, per decision 9). An explicit signal
  anywhere on the listing's text REJECTS; there is no mixed-stock notion, so nothing goes to a
  digest in this slice. `accidenté` is the first line to relax, by ruling, and it is a constant in
  the classifier so relaxing it is a commit.
- [2026-08-29 22:30] AGREED (design): **score components, all clamped, none a disqualifier**:
  price (lower is better, linear from the ceiling), age (full marks ≤ 5 years, decaying to 0 at
  10), mileage (full ≤ 80 000 km, decaying to 0 at 160 000), gearbox (automatic full, manual 0),
  fuel (essence/hybride full, diesel a plain preference penalty — NOT a regulatory claim; ZFE is
  `[Unverified]` and the notification never says "banned"), body (`body_rank`, the `commune_rank`
  mechanism: ranked scores, unranked 0 and still notified), seller (displayed; equal weight 0 until
  a preference is ruled). Weights sum to 100; `high_priority_score` ships at 70 and is calibrated
  after a week of real scores, exactly as the rent side's was.

- [2026-08-29 22:40] AGREED at the 3C gate (advisor, four findings, all folded in):
  1. **The vehicle §1 set arrives NEGATED in ordinary copy** — *jamais accidenté*, *non gagé*,
     *aucun accident*, *CT OK* are the honest listing's own reassurance block, and a bare scan
     rejects the good ads and keeps the silent ones (over-rejection, invisible by definition; the
     lift-negation lesson on a set where EVERY term is negated in practice). The classifier reads
     the negation window first; the corpus carries both forms of every term from day one
     (`jamais accidenté` → MATCH, `accidenté réparé` → REJECT, `non gagé` → MATCH, `gagé` → REJECT).
  2. **`observedAt` is in the slice from step 1** — ParuVendu fires ~12 messages a day and the
     same ad recurs across them under one link identity, so a price change between two messages
     is the Ozoir oscillation with more re-reads per pass. `VehicleListing::observedAt` from
     `sentAt()`, `VehicleStore::record()` orders on it, and a replay test in both message orders.
  3. **The Q36 analog**: `VehicleScout run` REFUSES to notify while the VEHICLE seen-set is empty,
     read from the vehicle tables (the composed housing `Store`'s check reads the wrong table and
     would be empty forever on the car database). Cold-start blast radius is the whole Autohero
     catalogue plus every card in the window, so the seed step is load-bearing.
  4. **`feed_silent_days: 1` on the ParuVendu block** — ruled with the cadence answer, so
     `VehicleEmailSource` implements `FeedFreshness`, the car pipeline threads the date through
     `recordRun`, and the car config loader accepts the key; without the wiring it is a configured
     feature that never runs.
  Two stated costs: **ParuVendu SAMPLES its feed** — ≤ 3 cards per ~2 h message (~36 a day) of
  the hundreds its subjects count; the *Voir tous les résultats* route is unmeasured and NOT in
  this slice. And `ChannelFactory` parametrises the subject prefix AND the `SMTP_FROM` default, and
  `VehicleScout` takes the same injected `Notifier` seam `Scout` has, so its CLI tests can assert
  deliveries.

- [2026-08-29 23:30] BUILT, steps 1–8, test-first per step and every value hand-read: `src/php/Vehicle/`
  (13 classes), `Cli/VehicleScout` + the `--domain=car` dispatch + `Cli/ChannelFactory`, `config/car/`
  (criteria + two sources), fixtures (`tests/fixtures/paruvendu/` — two captures, QP-redacted of the
  subscriber's `ticket`/`idAlerte`/`mailUniqId`; `tests/fixtures/autohero/` — live robots, a 5-lot
  sitemap, the lot page reduced to its JSON-LD), a 24-case vehicle corpus with both forms of every
  negatable term, and 12 ledger cases. Measured on the way: the negation window is load-bearing
  (cutting it turns exactly the four before-negation reassurance cases red); the store keeps a source
  flaky while most of its recent runs failed, so recovery is a window and not a run.
  **Owed and recorded, not built:** `tests/test-vehicle-guard.sh` (the §1 tripwire hook greps the
  housing set only); cross-portal twins across car sources (one source per route today); the
  `Voir tous les résultats` route that would lift ParuVendu's 3-card sampling.

## Formal Plan

1. `Vehicle/VehicleListing` + `VehicleSnapshot` (reflection-covered encoder), TDD.
2. `Vehicle/VehicleClassifier` + a language-neutral corpus `tests/fixtures/vehicle/corpus.json`
   (synthetic, `provenance` declared, captured cases appended as sources go live) — the tripwire
   `.claude/hooks/tenure-guard.sh` does not cover this set; a `tests/test-vehicle-guard.sh` is NOT
   in this slice (recorded as owed).
3. `Vehicle/VehicleCriteria` (+ loader, strict keys) + `VehicleScorer`, table-driven tests.
4. `Vehicle/VehicleStore` on `Store` (runs/health/alerts) + own tables, seen-set / price history /
   evidence categories from the rent store's contract that apply.
5. `Vehicle/VehicleEmailSource` — config-driven card readers; ParuVendu fixtures scrubbed with
   `tools/scrub-eml.php`, hand-counted (3 cards / 42 stated).
6. `Vehicle/SitemapVehicleSource` — Autohero fixtures: the sitemap (trimmed to a few `<loc>`) and
   one lot page reduced to its JSON-LD block; robots frozen.
7. `Vehicle/VehiclePipeline` + `VehicleFormatter` (source leads the title: `paruvendu · 78/100 —
   Renault Austral 2023 · 26 000 km · 21 000 €`), price drops, health alerts, heartbeat.
8. `Cli/VehicleScout` + `--domain=car` dispatch + `Cli/ChannelFactory`; `config/car/*`;
   `.env.example` keys; `compose.yaml` service; README section; ledger cases.
9. Deploy: `scout --domain=car doctor`, `run --once --seed`, `up -d car-scout`; verify the first
   live pass.
