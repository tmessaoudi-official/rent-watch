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

- [2026-08-29 22:35] REFUTED ON THE FIRST DEPLOY, and repaired: `docker compose run --rm car-scout
  doctor` REPLACES the service's `command`, so the `--domain=car` flag I had put there was dropped
  and the RENT doctor, then the RENT `--seed`, ran instead — 404 rent rows that had never been
  notified (rejects and the 11 *à vérifier* entries) were stamped as notified at 22:32:01, which
  would have hidden the digest backlog for good. Measured first (0 MATCH rows carried the stamp),
  backed up, then reset exactly those rows to un-notified. `car-scout` meanwhile restart-looped on
  the Q36 refusal — the guard working, loudly, on an unseeded car store. The flag now lives in the
  service's `entrypoint`, so every verb after the service name is a car verb.

- [2026-08-29 23:10] AGREED (developer, verbatim: *"adapt the env vars … to be really split into
  rent watch and car watch … like only rent watch exists in this app … cover everything"*): **every
  domain-bound key is prefixed.** `SCOUT_DB` → `RENT_SCOUT_DB`, `IMAP_MAILBOX` → `RENT_IMAP_MAILBOX`,
  `NTFY_TOPIC` → `RENT_NTFY_TOPIC`, `HEARTBEAT_HOURS` → `RENT_HEARTBEAT_HOURS`, `FEED_SILENT_DAYS` →
  `RENT_FEED_SILENT_DAYS`, beside `CAR_SCOUT_DB`, `CAR_IMAP_MAILBOX`, `CAR_NTFY_TOPIC`,
  `CAR_HEARTBEAT_HOURS`, `CAR_FEED_SILENT_DAYS`. Account-level (`IMAP_HOST/USER/PASSWORD`,
  `IMAP_SINCE_DAYS`, `IMAP_MAX_MESSAGES`, `SMTP_*`, `NTFY_SERVER`, `TZ`) and tool-level
  (`SCOUT_OFFLINE`, `SCOUT_MAX_PASSES`, `SCOUT_BACKUP_KEEP`) keys stay shared; `MAILBOX_DIR` stays
  the shared OFFLINE seam (a test sets it per run). The old rent names are refused at startup by
  `LegacyEnv`, the RENT_WATCH_* rule applied again. Applied as one word-anchored rewrite over 36
  files, then by hand where a shared message would otherwise name the wrong domain's key
  (`Heartbeat`, `NtfyChannel`), and in this machine's `.env` in place. Historical prose keeps the
  old spelling (`HEARTBEAT_HOURS` "sat in .env.example read by no code" is a 2026-08-22 fact).
  `tools/backup-state.sh` backs up BOTH stores when called without an argument.

- [2026-08-29 23:40] AGREED (developer, verbatim: *"Cover everything. every word every namespace,
  file… it must be generic scout with namespaces prefixed env vars for each domain! we will
  probably add more domains so it must be clean and future proof"*) — **THE GENERIC-SCOUT
  RESTRUCTURING**, designed here before a file moves:
  - **Namespaces per domain over a generic core.** `Scout\Core` keeps only what no domain owns
    (`Text`, `Redact`, `Pacer`, `Heartbeat`, `SourceHealth`, `SourceStatus`, `MalformedText`,
    `MutableByDesign`, `Offline`, `Notify\*` channels/transports/`Notifier`/`Notification`);
    `Scout\Adapters` keeps `Http\*`, `Mail\*`, `SourceError`, `FeedFreshness`; `Scout\Config` keeps
    `Reader`, `ConfigError`, `DotEnv`, `LegacyEnv`; `Scout\Cli` keeps `WatchLoop`, `ChannelFactory`
    and gains the GENERIC dispatcher `Scout\Cli\Scout` + `Scout\Cli\Domains` (the registry: a new
    domain is one entry). Everything housing-bound moves to **`Scout\Rent\`** — the tenure
    classifier and corpus reader, criteria/engine/verdict, dedup, `RawListing`/snapshot,
    `Department`, `PlafondBands`, `Prose`, `DigestSchedule`, `Outcome`, `SourceProfile`, the
    `Formatter`, `Store\*`, `Config\{Criteria,FieldMap,NotifyPolicy,Weights,SourceDefinition,
    ConfigLoader}`, `Adapters\{Source,HtmlSource,Html\*,HttpJsonSource,EmailAlertSource,
    FixtureSource,ListingMapper,Payload,PacedSource,FeedDate}`, `Enrich\*`, and the rent CLI
    (`Scout\Rent\Cli\RentScout`, `Pipeline`, `RunResult`, `DigestBatch`). The car domain becomes
    **`Scout\Car\`** (class names keep their `Vehicle` noun; the CLI is `Scout\Car\Cli\CarScout`).
    Tests follow the same shape: `tests/php/{Core,Rent,Car,Adapters,Config,Cli}`.
  - **`bin/scout <verb>` needs a domain**: `--domain=rent` / `--domain=car`; without one it lists
    the registry and exits 2. Compose services carry the flag in their ENTRYPOINT.
  - **Config per domain**: `config/rent/{criteria,sources}.json` (+ `criteria.local.json`) and
    `config/car/…`. **Fixtures per domain**: `tests/fixtures/rent/<source>` and
    `tests/fixtures/car/<source>`, the tenure corpus under `tests/fixtures/rent/tenure/`.
  - **State markers per domain**: `state/rent-heartbeat.txt`, `state/rent-digest.txt`,
    `state/rent-last-refusal.txt` (the car side already has `car-heartbeat.txt`), with a one-time
    rename of the old unprefixed files at startup so a redeploy does not beat or re-digest.
  - **Method**: one scripted move (`git mv`) with an explicit old→new map of every class and
    path; the FQCNs, `use` lines, `::class` strings, quoted `Scout\\Core\\X` test strings, file
    paths in the ledger / hooks / agents / skills / docs are rewritten from that map; the suite
    (2 287), the ledger apply-sweep (559 expressions), the drift gate and a filtered ledger are
    the certification, and both watchers are redeployed after. Historical plan text keeps its
    old spellings and paths.

- [2026-08-30 00:40] AGREED (executed, with the deltas that execution forced): the restructuring
  above landed as ONE scripted move (`var/claude/restructure-generic-scout.php`, gitignored,
  refuses to run twice) plus a second boundary-corrected pass — the first pass's lookbehinds
  rejected a preceding `\` and `/`, so every `\Scout\…::class` and every `tests/fixtures/…` path
  survived it; 2 296 tests green after, the apply-sweep clean at 566 expressions. Deltas:
  `Scout\Store` moved under `Scout\Rent\Store` after all — `VehicleStore` COMPOSES it for the run
  log/health/alerts, so the car domain depends on the rent store's generic half (**owed**: extract
  that half into a generic store; recorded, not done). The dispatcher is `Scout\Cli\Scout` +
  `Scout\Cli\Domains`, and it NEVER defaults: a missing or unknown `--domain=` exits 2 listing the
  registry, `help` alone is usage. Compose services are `rent-scout` / `car-scout` with the flag in
  each ENTRYPOINT (`docker compose up -d --remove-orphans` on redeploy, or the old `scout`
  container keeps running beside the new one). `RW_UID`/`RW_GID` → `SCOUT_UID`/`SCOUT_GID`
  (compose, `.env`, template, README); Dockerfile user `scout`, `CMD ["help"]`. Repo skills
  `rw-*` → `scout-*` (next session to appear). Shared transports default to `scout@localhost` /
  `[scout]` / `EHLO scout`, each domain passes its own label — which exposed that the SMTP envelope
  sender follows the transport, not the header, masked while both defaults coincided. State markers
  are `state/rent-{heartbeat,digest,last-refusal}.txt`, renamed ONCE from the unprefixed files at
  the rent CLI's first start (only when the new name is absent), pinned by two tests and two ledger
  cases; two more ledger cases pin the non-defaulting dispatcher. The ntfy topics follow one
  scheme, `<initial>w-<32 hex>` (developer ruling, verbatim: *"keep the same format… unify
  everything"*): the car topic was regenerated as `cw-…` and the test push on it delivered.
  Historical plan text keeps its old spellings, except three citations of a path meant to be
  EXECUTED (`drift-scan.sh`), which the drift gate rightly refused.

- [2026-08-30 02:30] AGREED (milestone panel, round 1 against `dc71e18` — THREE lenses, 18
  findings, 0 clean): every P0–P2 fixed in the follow-up commit, P3s where cheap. **P0
  (correctness)**: the cross-track twin link named a direct route REJECTED as `PLS` — or
  UNDETERMINED — as the *voie directe* in its agency copy's match push; it now feeds the twin's
  judged tenure through the same funnel as the persisted group veto (`Pipeline::twinClassification()`:
  excluded twin → REJECT, undetermined twin → DIGEST), pinned by two `PipelineRunTest` cases and a
  ledger case. **P1 (resilience)**: the car heartbeat read `health()` without the clock, so
  `FEED_SILENT`/`STALE` never reached the beat — fixed and pinned through `STALE` (the offline
  `FileMailbox` reports no message date by design, so the fixture route cannot show `FEED_SILENT`;
  production IMAP does, as the rent `leboncoin` verdict proves); and `FixtureSecretsTest`'s JWT
  pattern was blind to quoted-printable (`\beyJ` after `=3D`), so it had never matched the committed
  Bien'ici tokens — it decodes QP before looking now, with a self-test and a ledger case; CLAUDE.md's
  claim corrected. **P2**: the room pattern rejected `3 belles chambres` / `chambre de service` /
  `chambre d'amis` (hard-rule-8 silent over-rejection) — adjective-tolerant lookbehinds and a
  room-type lookahead, re-measured over 1 107 stored titles (same 40 caught, zero flats), four new
  `ConfigTest` cases; `.dockerignore` aligned on `config/*/*.local.json`; generic-layer messages
  name the key the caller read (`Heartbeat::fromEnv(…, $key)`, `NtfyChannel(…, topicKey:)`) instead
  of enumerating domains; every rent-CLI advice string carries `--domain=rent`; `SCOUT_DB`
  instructions → `RENT_SCOUT_DB`; `RentScout::…` symbol citations; `tests/fixtures/rent/<source>/`
  in current-tense instructions; reviewer trigger globs on real paths; `SMTP_FROM` shipped EMPTY so
  each domain sends as its own label; sources.json comment counts; composer name `tmessaoudi/scout`.
  **P3 pinned too**: the digest and refusal marker migrations (deleting either left the suite green).
  **Recorded, not fixed**: the local tracing JIT crashes the ledger nondeterministically
  (CLAUDE.md gotcha, with the `PHP_INI_SCAN_DIR` mitigation); `rentwatch-*` temp prefixes and the
  charters' "rent-watch" prose (cosmetic). Round 2 runs against the fix commit.

- [2026-08-30 09:10] AGREED (developer): certification stays MAXIMAL as ruled — two CONSECUTIVE
  fully-clean panel rounds against a frozen commit; round 3 runs after the round-2 fixes.
- [2026-08-30 09:10] AGREED (developer): the cross-track twin fact is DURABLE, the group veto's
  rule read across the track boundary — once the other route was judged excluded for a flat, the
  agency copy is rejected for the row's life (stated cost: an over-merged twin rejects a real flat
  permanently; repair = unpick the row, never weaken the rule); a doubt (twin UNKNOWN) clears only
  when both routes are judged together again (stated cost: a twin resolved while the copy was
  absent cannot reach the copy's row until they are seen together).

- [2026-08-30 09:20] AGREED (developer): scope of the goal — certify THIS milestone (MAXIMAL), then
  build `tests/test-vehicle-guard.sh` and the generic store split as the NEXT milestone with its
  own panel; the five input-gated rows (ParuVendu *Voir tous les résultats* page, cross-portal car
  twins, diesel/ZFE penalty size, CapCar alert, real SMTP) stay owed until the developer provides
  the capture or ruling each needs.

- [2026-08-30 10:30] AGREED (milestone panel, round 2 against `30225fb` — resilience 3 findings,
  completeness 6, the correctness lens LOST to an API error after a 5.5 h stall and stopped;
  round 3 re-runs it): **P1 (§1)** the cross-track veto only bound while the twin was FETCHED — a
  pass seeing the agency copy alone pushed the PLS flat; fixed by persisting the twin fact
  (schema v12 `twin_tenure`/`twin_source`, `Store::recordTwin()`/`twinTenure()`, precedence per
  the 09:10 ruling), read by `Pipeline::twinClassification()` when the twin is absent and by
  `reclassify` beside the group veto — four `PipelineRunTest` cases, a new `StoreTwinTest`
  category (six cases incl. the v11→v12 migration), two reclassify cases, three ledger cases.
  **P1** the scrubber and `FixtureSecretsTest` were blind to a base64 BODY — both decode base64
  blocks now; the scrubber REFUSES such a file (`tests/test-scrub-eml.sh` base64 case), the guard
  has a self-test and a ledger case. **P2/P3**: the *Two tracks, ONE push* gotcha now carries the
  veto half; `NotificationKind::RENT_DROP` → `PRICE_DROP` (the generic layer's last domain word);
  `Heartbeat::fromEnv(…, key)` pinned on the car side — which exposed that `CAR_HEARTBEAT_HOURS=0`
  ESCAPED as an uncaught exception instead of a refusal (fixed, `CarScout::watch()`); the
  `HeartbeatTest` key case; `sources.json` REMPLACER sentence and verbs; `Dockerfile:74`; corpus
  provenance paths; `victim@example.test` in the guard's own payload. **Stated, not durable**: a
  listing's OWN tenure history — a direct route that stops printing yesterday's PLS is pushed on
  its own reading (pre-existing; only the TWIN fact is durable).

- [2026-08-30 11:30] AGREED (milestone panel, round 3 against `cf468e3` — correctness 4, resilience
  6, completeness 7; all fixed in the follow-up commit): **P0 (§1)** the twin fact was written on the
  SURVIVOR's row only, and judgement is per cluster while survivorship follows the harvest — a second
  private-portal copy absorbed on one pass was pushed alone on the next and again when it survived
  the seloger row whose PLS sat on disk beside it; now `Store::recordTwin()` writes EVERY member of
  the cluster and `twinClassification()` reads the most restrictive fact across the cluster's rows.
  **P1 (§1)** a row's OWN excluded reading was not durable — a hydration fingerprint mismatch served
  the card alone and a PLS row was re-judged LIBRE and pushed; now `durableOwnReading()` keeps a
  stored excluded tenure until an explicit command (stated cost: a genuine portal re-labelling is
  not honoured automatically — the safe direction), and the row records the JUDGED verdict so
  `scout digest` announces the doubt's cause. **P1** the base64-body decode dropped a short LAST
  line (the footer carrying the address) and any fold under 40 columns, in the scrubber AND the
  guard; both now take lines of 20+ with an optional short tail and decode UTF-16 bodies. **P2**
  a car startup refusal now writes `state/car-last-refusal.txt` and the next beat reports it
  (`démarrage précédent refusé : …`); a store a NEWER image migrated is a RECORDED refusal on both
  CLIs, not a trace; `recordTwin()` on an unknown row throws. **P3** schema-v12 status lines, the
  **twin** store category in CLAUDE.md, SOURCES.md, the reclassify skip message, scrubber docblock
  and ALERT-CAPTURE, a test comment, the ugrep `SABOTAGE_FILTER` note, the uncounted-single-room
  shape recorded beside `_exclude_title_patterns` (zero real occurrences). Twelve new tests, five
  ledger cases. **STOP POINT (developer instruction 11:20, "finish and stop at a working tested
  state"):** committed, pushed and redeployed with the suite (2 332), scrubber (33), shell battery,
  drift gate and apply-sweep green. **NOT run, owed:** the filtered ledger over the five round-3
  cases, and panel round 4 — the MAXIMAL two-consecutive-clean requirement is therefore NOT met at
  this stop point; the next session resumes at round 4 against the frozen commit.

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
