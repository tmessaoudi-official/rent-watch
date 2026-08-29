# CLAUDE.md — scout

> This file holds the RULES for how Claude delivers code here — quality, carefulness, gates, and the
> eligibility boundary that governs this whole project. The product itself (spec, decisions, open
> questions) lives in `spec/` and `docs/`. Boundary test before adding anything: *does Claude need this
> to deliver correct code?* If not, it belongs in `docs/`, not here.

scout is a **self-hosted watcher for rental listings in Île-de-France**. It polls institutional
landlords, ingests private-portal alert emails over IMAP, classifies every listing by French housing
**tenure type**, filters and scores it against personal criteria, and pushes a notification within
minutes of publication. Single language, single user, single machine. CLI plus push notifications —
**no web UI**, by design.

The full specification is [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md). It is the source of truth
for the product, and **every constraint in it is a ruling**, not a draft to be improved on. Read it
before touching anything under `src/`.

Status: **milestone 1 is functionally complete against a frozen payload.** The pure core, the store
(schema v11 as of 2026-08-28), the config layer, the adapter contract, the criteria engine, dedup, the notification
layer and the `scout` CLI all exist. What is missing is a NETWORK adapter, and that is blocked on an
input rather than a decision. As of 2026-08-07 there is a PHP 8.5
implementation of `models` + `tenure` under `src/php/Core/`, a 130-case language-neutral classifier
corpus at `tests/fixtures/rent/tenure/corpus.json`, the seen-set / price-history / run-log store under
`src/php/Rent/Store/` with `SourceHealth` + `SourceStatus` in `Core/`, a strict JSON config layer under
`src/php/Config/` with both files committed, the `Source` contract plus `Payload` / `ListingMapper` /
`FixtureSource` under `src/php/Adapters/`, the criteria engine (`CriteriaEngine` + `Verdict`), and a
PHPUnit suite. `scout --domain=rent run --once` is demonstrable end to end today against a frozen payload.

`scout --domain=rent doctor`, `scout --domain=rent dump`, `scout --domain=rent run --once/--seed`, `scout --domain=rent test-notify`, `scout --domain=rent digest` and
`scout --domain=rent reclassify` all work end to end today.

**Q34 IS CLOSED IN ALL THREE PATHS as of 2026-08-26** — the daily floor was the last one, and it had
been ruled, configured and unbuilt: `digest_hour` was parsed into `NotifyPolicy`, printed by
`doctor`, and read by nothing at all. `Core/DigestSchedule` is the policy (pure, clock injected,
mirroring `Core/Heartbeat`) and `state/rent-digest.txt` on the mounted volume is the marker, written only
after the channel confirms. Three rules travel with it. **It is SILENT on a day with nothing
pending, and records no window as served** — the heartbeat already carries daily liveness, so an
unconditional rollup would be a second scheduled push saying nothing new, and leaving the window
open is what makes *"an unsent digest is retried"* work. **It runs under `--watch` only**, so a
cron-driven `--once` deployment has the two event-driven paths and no floor; `doctor` says so.
And **the drain is SHARED with `scout --domain=rent digest`** (`Cli/DigestBatch` + one collector that never throws
and never prints), because two implementations of §1's only landing zone is how one drifts into
announcing what the other would withhold.

> **Its zone justification was WRONG when first written, and the correction is the part worth
> keeping.** It said PHP does not consult `TZ`, so computing the floor from the default zone would
> fire it at 10:00 Paris in summer. The measurement is real — `php -r` in the deployed container
> reports `UTC` with `TZ=Europe/Paris` set — and the conclusion does not follow, because
> `bin/scout:44` already calls `date_default_timezone_set()` from `TZ` before `Scout` is
> constructed. **A true number attached to an invented cause**, produced by measuring the runtime and
> never reading the entrypoint; `compose.yaml`'s TZ comment was accused of the same error, is
> likewise correct, and was left alone. What the explicit zone actually buys, both measured: an
> unusable `TZ` becomes a loud startup refusal (`date_default_timezone_set('Europe/Pariss')` returns
> `false`, emits a *Notice*, and leaves UTC standing), and the schedule stops depending on
> process-wide mutable state.

`digest` and `reclassify` closed 2026-08-23 and both carry a rule
worth knowing before touching either, because they look symmetrical and are not: **`digest`
announces an evidence-less row, `reclassify` skips one.** `digest` reads the store rather than the
pass — the pipeline re-offers an undelivered entry only while the ad is still published, so an entry
delisted in between is lost — and a snapshot-less row in that backlog is a listing whose own payload
could not be encoded, which is a live source fault rather than an old row, so skipping it would skip
exactly what the command exists to rescue. **This sentence used to say those rows "predate schema
v7", and that is impossible**: `pendingDigest()` filters on `outcome`, itself a v7 column that is
not backfilled, so a genuine pre-v7 row has `outcome = NULL` and is never returned at all. The
inverted premise was corrected twice in code and left standing here until a fourth review round;
believing it would lead a future session to widen `pendingDigest()` to reach pre-v7 rows, which is
a §1 risk that was explicitly refused — nothing stored distinguishes a pre-v7 digest from a pre-v7
rejection. `reclassify` FORMS a
verdict instead of announcing one, and re-judging on less evidence than the original saw is a §1
breach rather than a smaller improvement: a card whose field says `PLS` while its title says
*logement intermédiaire* classifies `UNKNOWN` by CONFLICT, and on the title alone it becomes a
MATCH. It also runs on the v7 snapshot ALONE — merging `listing_detail.fields_json` would buy no
evidence (the pipeline already rewrites the snapshot post-merge every pass) while making a stored
verdict depend on mapper code that has since changed. `--since` is refused, not implemented, because
its ruled mechanism is a classifier-version column that does not exist. The network adapters exist too — `HttpJsonSource` + `Robots`, `EmailAlertSource` +
`ImapMailbox`/`FileMailbox`, `SmtpTransport`/`FileTransport` — all tested offline against fakes,
with `.env` swapping the real thing in — and the email half was written BLIND, which cost four
defects the day a real alert first reached it (§ "The email-alert path"). **What is missing is not
code but INPUTS**: a DevTools cURL capture for AL'in (hard rule 1 forbids writing an endpoint from
memory) and IMAP credentials for the alert mailbox. The `plafonds` figures are no longer among them
— fetched, committed and wired on 2026-08-26 (§ "Tier 4"). CI now exists
(`.github/workflows/ci.yml`): the fast job runs the PHPUnit suite, the tenure tripwire,
runner-fetch and ci-workflow self-tests, the drift scan and shell syntax on every push and
PR; the sabotage ledger runs nightly and on demand. **A red nightly opens a GitHub issue, and a
green one closes every open ledger issue again** — it previously notified nobody, and failed 7/7
unnoticed from 2026-08-13 to 2026-08-19 (hard rule 2: an alert computed and never sent is worse than
none). The retraction half landed 2026-08-22 and is the same rule read backwards: nothing ever
closed one, so issues #1 and #2 stood open for days after the regression they reported was fixed and
pushed, and an alert nobody retracts becomes furniture. Both halves are pinned by
`tests/test-ci-workflow.sh` — by step NAME *and* by the API call that does the work, since a name
alone survives the body being gutted. **`scout --domain=rent run --watch` now runs** (2026-08-19):
`Core/Pacer` holds the Q37 cadence (15 min ± 5, 5 s between distinct hosts, 60 s per host, order
shuffled each pass), `Adapters/PacedSource` is the decorator that applies it — so `Pipeline` never
learns that time exists and `--once` stays unpaced — and `Cli/WatchLoop` is the loop, which SURVIVES
a pass that throws (reporting it) and stops on SIGINT/SIGTERM only after the pass in flight
finishes. `Source::host(): ?string` was added to the contract to make host-level pacing possible;
`null` means the source issues no outbound web request and is never delayed.

**THE FIRST REAL SOURCE IS LIVE (2026-08-19).** In'li is `enabled: true` in `config/rent/sources.json`
with a verified endpoint — `robots.txt` read first (`Disallow: /espace-membre/` only), the search
page fetched, the payload frozen and scrubbed into `tests/fixtures/rent/inli/search.html`.
`scout --domain=rent doctor --source=inli` returns **92 annonces, 4 pages, ~12 s, `ok`**. Its search page is
server-rendered, so there is no JSON API to prefer and it uses the new `html` adapter:
`Adapters/HtmlSource` + `Adapters/Html/Selector`, built on PHP 8.5's own `Dom\HTMLDocument` and
`querySelectorAll` — **no hand-written selector engine was needed**, which is why this cost ~300
lines rather than the ~1 000 estimated. Field maps for `type: html` are CSS selectors with an
optional `@attr` and an optional `=> regex` capture; extraction still funnels through
`ListingMapper`, so hard rule 9 has exactly one implementation. Pagination is real, not deferred:
`page_param` walks pages and `total_selector` CHECKS the walk against the count the page states
about itself, because walking until a page comes back empty is a termination rule and not a proof.

**SOURCE #3 IS LIVE, AND IT IS THE FIRST THAT NEEDS A SECOND REQUEST (2026-08-21).** Cityloger —
`www.cityloger.fr`, the Immobilière 3F group's own lettings platform — is `enabled: true`;
`scout --domain=rent doctor --source=cityloger` returns **51 annonces, ~16 s, `ok`**, and the ref is stable across
two runs. Four things about it change how a source is added here:

- **Its search card carries NO tenure at all.** Not a badge, not a code, nothing — asserted by test,
  so the day one appears the assertion fails rather than the second request continuing forever. On a
  mixed source that meant every listing resolved `UNKNOWN` and went to the *à vérifier* digest:
  correct under §1, and useless. So `type: html` gained **`detail_map`** — a second field map,
  resolved against a listing's own detail page.
- **A detail fetch is one request PER LISTING, so it runs behind a gate — and as of 2026-08-23 the
  gate is the CACHE, not a predicate.** It was `Criteria::matchesCommune()`, injected by the CLI,
  and that shape was wrong for a reason worth keeping: a per-pass predicate makes a listing's
  verdict depend on which pass is looking at it, so a listing the title filter REJECTED while
  hydrated returns as a bare card on the next pass and notifies. What replaced it is in
  § "Detail hydration" below. `matchesCommune()` survives as rank 1 of the ORDERING, for the
  original reason — it is the only filter whose inputs the CARD carries in full, so using it cannot
  act on a field the detail page would have filled (hard rule 8).
- **A detail map's selectors must address the LISTING, never the page.** Measured on the frozen
  Antony payload: its own `.description` classifies **LLI 0.90**, and the same listing fed its whole
  detail page classifies **UNKNOWN 0.00** — because *"Commission d'attribution"* and *"demande de
  logement social"* are page furniture present on social and intermediate listings alike, and three
  such signals conflict a correct verdict away. This is the CDC `au plus près` failure class on a
  new surface, and `HtmlSource` enforces it structurally: the detail path deliberately does NOT add
  `_text`, so a detail map contributes only what it selects.
- **`{page}` may now appear in `url` itself**, for a site whose page number sits mid-path
  (`resultats-location-{page}-defaut-`). Page one substitutes like every other page. The rejected
  alternative — point `url` at the site root and let page one be the homepage widget, whose ten
  cards are identical today — fails silently the day that widget becomes *featured* rather than
  ranks 1–10.

The corpus gained its **first captured SOCIAL case** here, and its third instance of one failure
class: *"Logement intermédiaire géré par un **bailleur social**"* — an explicit intermediate label
sharing a sentence with words that describe who manages the flat, not its tenure. `plus`,
`au plus près`, `bailleur social`: the pattern is excluded vocabulary appearing as ordinary French on
an eligible listing, and it has now cost three fixes.

**Cityloger's live yield was 0 matches when it was onboarded, and that was not a defect — but the
sentence stopped being true the same week and is kept here as an example of why a yield claim needs
a date.** As written on 2026-08-20 it read: all 51 listings sit outside the 78/95 filter, the three
Île-de-France ones being 92 and 77, so nothing is hydrated on a real pass. Both of those departments are INSIDE the region as of
2026-08-22, so those three are now gated in and their detail pages ARE fetched — and the yield is
**still 0, for a completely different reason**: all three quote 1221–1520 € CC and the ceiling is now
1200. Measured on a live pass that day, Cityloger contributed 0 of the 92 notified rows while CDC,
In'li and Logirep contributed 33, 54 and 5. So the claim survived a change that invalidated its
every premise, which is the most dangerous thing a documented number can do. The machinery was
always proven by fixtures rather than by yield, which is the part that has not changed. `docs/SOURCES.md` A6b records why the source is worth
having anyway: 3F's *social* stock is allocated through AL'in and the SNE, so what surfaces on
Cityloger skews to the intermediate and libre stock this project is looking for.

> **THE PROJECT-WIDE "0 matches" CLAIM THAT USED TO STAND HERE WAS WRONG, and the way it was wrong
> is the lesson.** It read *"live yield is 0 because everything is outside the commune filter"* —
> true of Cityloger, and false of the tree. Measured 2026-08-22 by running all four sources against
> live payloads: **474 listings, 457 rejected on location — and 13 got past it.** Twelve of those
> were near-misses (9 In'li LLI at 1017–1353 € CC and a CDC 82 m² at 1669 €, all rejected by
> `min_rooms: 4` alone; two CDC 5-pièces at 112 and 117 m² rejected by the rent ceiling). The
> headline number was right and its explanation was invented, which is worse than being wrong twice:
> a true number attached to a false cause stops anyone looking. **Never generalise one source's
> measurement to the tree** — `scout --domain=rent run --seed -v --source=<name>` on a throwaway
> `RENT_SCOUT_DB` prints every rejection with its reason and costs one poll.

**The notification carries the postcode, the departement, the floor and the lift** (phase 1,
2026-08-22). Headline: `82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC`; first reason line:
`Yvelines (78) · 2e étage · avec ascenseur`. `Core/Department` is the lookup, Île-de-France only —
those eight prefixes are what the criteria admit, and an unknown postcode returns `null` so the line
is omitted rather than guessed. Every rule on it is **hard rule 9 at the display layer**, each with
its own sabotage case: `floor === 0` is RDC and REAL (read as falsy it vanishes — the display twin
of rejecting a listing for not stating a floor), and an UNMENTIONED lift is not an absent one, so
`null` says nothing while `false` says *sans ascenseur*.

### Detail hydration — the cache is the gate (2026-08-23)

**Phase 2 is BUILT, and Phase 2b (2026-08-23) added the two facts the description was carrying all
along** — see the floor/lift block above, plus the schema-v6 map fingerprint that stops a widened
`detail_map` from serving rows captured under the old one for ever. In'li has a `detail_map` (`h1` for the title, `.advert-body-description p` for
the description), which matters because an In'li card's ENTIRE text is `1 005 € cc 3 pièces ·
55.32 m² Longjumeau` — four facts, no title, so `exclude_title_patterns` was **structurally dead on
the source producing two thirds of the matches**, and nothing had slipped through only because In'li
lists flats. Luck, not a filter.

Three mechanisms replaced the single gate, and each closes a hole the others open:

- **NOVELTY IS THE GATE, and it lives in the schema-v6 `listing_detail` cache** — keyed on
  `(source, external_id)`, never on `dedup_key`, because normalisation evolves and a row keyed on a
  conclusion silently orphans the whole cache the day it changes. A page already on record costs no
  request ever again, so steady state is ZERO extra requests. **Hydration without persistence would
  be worse than none**: the listing's verdict would depend on which pass looked at it.
- **A per-pass BUDGET** (`detail_budget_per_pass`, default 20) bounds the cold start, which is the
  only expensive moment — In'li's ~174 listings are all novel at once, and at Q37 pacing that is a
  three-hour pass. The backlog drains over several passes. **An explicit `0` is REFUSED at load**,
  because a `detail_map` that can never run is a disabled feature dressed as a configured one; an
  OMITTED budget defaults, because a slow cold start is benign. Same asymmetry as `RENT_HEARTBEAT_HOURS`.
  This refusal is the successor to *"a `detail_map` with no gate REFUSES"*, which retired with the
  gate — replaced, not deleted.
- **PRIORITY decides who gets a short budget, and rank 0 is *not yet in the seen-set*.** Ranked any
  lower, backlog eats the budget while a genuinely new listing is notified unhydrated — and by then
  it is already `notified_at`, so hydrating it later buys nothing. That is the same bypass rebuilt
  out of a budget. Rank 1 is `matchesCommune()`, rank 2 the backlog.

**A per-listing fetch failure no longer voids the pass, and the taxonomy is the rule**: config-shaped
failures still THROW (robots refusing the detail path, a card with no `url` — those are states, not
events, and every hydration would fail for the same reason), while a runtime failure is RECORDED
with its attempt count and redacted message, counted by `Store::detailFailureCount()`, and reported
by `scout --domain=rent doctor`. Throwing was right about silence and wrong about blast radius: it voided the
entire pass, so one permanently-404ing page meant the source returned nothing, marked nothing seen,
never notified a new listing again — and reported `SOURCE_BROKEN` on a diagnosis that was untrue.
Retried past a 6 h backoff, three times, then left alone.

**Stated cost:** a listing whose detail page cannot be read is judged on its card alone, exactly as
every listing on that source is judged today — `exclude_title_patterns` cannot fire on it. In'li's
floor and lift also stay `null`, so the high-floor penalty cannot fire on that source; `null` says
nothing rather than saying no, which is the safe direction (hard rule 9).

> **The reason given for that used to be *"In'li publishes no lift at all"*, and it was measured on
> ONE page.** Live acceptance on 2026-08-23 hydrated 20 real detail pages: **18 of 20 mention
> `ascenseur` and 19 of 20 state a floor.** The frozen fixture contains neither, so the assertion
> pinning it is true of that capture and false of the source — a generalisation from n=1, the same
> error class as the retired *"live yield is 0"* claim two entries down. The fields stay `null`
> because nothing MAPS them, not because the source withholds them.
>
> **Recovered on 2026-08-23 by `Core\Prose`**, an opt-in reader wired through the reserved capture
> prefix `=> prose:floor` / `=> prose:elevator`. Two rules carry it, both hard rule 9 inverted —
> a fact manufactured from its own negation:
>
> - **A floor is a POSITION (`au 3e étage`), never a COUNT (`de 18 étages`).** `Payload::floor()`
>   returned **4 for a flat on the 3rd floor**: In'li's own copy carries the typo `au 3? étage`, the
>   ordinal failed, and `d'un immeuble de 4 étages` answered instead. Fixed there by requiring the
>   singular `etage\b` — deliberately NOT by anchoring on `au|en`, which would regress CDC's
>   preposition-free card. The anchored reader is `Prose`, and it is opt-in per map.
> - **A lift reads its NEGATION first.** 5 of the 18 mentions are negations (`sans`, `Aucun`,
>   `Pas d'`, `ne dispose pas d'`, one with a curly apostrophe). A wrong `true` awards a bonus for a
>   lift that does not exist; a wrong `false` only lowers the score. `Payload::bool()` cannot do this
>   and must not be extended to — it matches the whole trimmed string, so prose returns `null`
>   (safe), while a substring reader would read *"Aucun ascenseur"* as `true`.
>
> Ground truth is `tests/fixtures/rent/inli/descriptions.json` — 20 live captures, each hand-labelled, which
> **live extraction now matches 20/20**. The bare ordinal (`situé au deuxième`) and the site typo are
> deliberately NOT parsed: under-extraction is the safe direction.

> **HYDRATING IN'LI PROVED IT IS NOT PURE LLI, and that is the single most valuable thing Phase 2
> produced.** Two live listings state *"Le logement est soumis au plafond de ressources **PLS**"* —
> which their CARDS never said. Under §1 that is a reject, and only the detail page carries it. Every
> document here calls In'li pure LLI; it is not, and a source's tenure claim is a property of its
> LISTINGS, never of the source.

> **The same hydration also demoted 4 of 40 live matches to the digest, on the word `plus`.**
> `ListingMapper` passes the WHOLE structured surface as `fields`, so a mapped description arrives
> twice — as the property, read with the prose rules, and as a bare `description` key, read with the
> identifier discipline that turns the adverb into the acronym. In'li states no explicit label, so
> that tier-1 doubt was the only tier-1 signal present and it decided the verdict. `title` and
> `description` now re-route to the prose scan, guarded on CONTAINMENT rather than on the name so it
> cannot become a named hole. Third instance of the `au plus près` class, and the first that was
> silently costing real matches.

**Location is REGION MODE as of 2026-08-22 — and by the end of that day it covered ALL of
Île-de-France, at `min_rooms: 3`, `min_surface_m2: 50` and `max_rent_cc: 1200`.** `communes: []`
means the name is not checked and `postcode_prefixes` is the entire location filter; `commune_rank`
still orders results, so the Boucle de Seine is a preference rather than a hard reject. Every change
is ruled (Q1, Q2, Q3 in `docs/OPEN-QUESTIONS.md`, each naming the one line that reverses it) and
every one is measured on a live poll: region mode over 78/95 took the yield from **0 to 8**, and
widening to all eight departements while dropping the surface floor to 50 m² and the ceiling to
1200 € took it to **83 matches out of 478**.

> **That 83 is the number that should be quoted, and predicting it would have got it wrong.** All
> eight of the old matches quoted 1258–1669 € CC, so the ceiling alone kills every one of them — a
> first draft of the Q2 entry reasoned exactly that far and wrote *"the live yield is zero"*. The
> other two changes had opened a pool the old criteria never looked at. **Never predict a yield from
> the previous filter's matches**; `scout --domain=rent run --once --seed` on a throwaway `RENT_SCOUT_DB` costs
> one poll. Two live consequences worth knowing: nearly every match is OUTSIDE the ranked communes
> (91/93/94 — Les Ulis, Aulnay, Pierrefitte, Vitry — with Dourdan and Dammarie-les-Lys scoring
> highest), because there is nothing under 1200 € CC in the Boucle de Seine; and scores ran
> **16–48**, so `high_priority_score: 70` could never fire and the `!!` marker was dead.
>
> > **BOTH HALVES OF THAT LAST CLAUSE WERE REFUTED ON 2026-08-26, and the second one had never been
> > written down at all.** Re-judging all 256 stored v7 snapshots offline — no poll needed, schema
> > v7 keeps the evidence — scores now run **0–70** (median 28, p90 40): commute lifted the ceiling
> > that morning and one listing actually reaches 70, so 16–48 is a pre-commute figure. **And the
> > marker STILL could not fire, for a structural reason nobody had stated:** `!!` needs
> > `score >= high_priority_score` **AND** `confidenceBp >= 80`, and those two are satisfied by
> > **disjoint sets of listings**. The top scorers are all `conf 50` — private-portal cards whose
> > tenure is the source default — while the listings that clear the confidence floor top out at
> > **55**. At any threshold ≥ 60 the marker is unreachable *by construction*, not by luck.
> >
> > So the threshold is **50** (developer ruling, 2026-08-26), marking 3 of the 47 confident
> > listings. That is not the *"tuning the instrument to the reading"* this entry warned against:
> > 70 predates commute and was never derived from anything, so this is the FIRST calibration it has
> > had. **The confidence floor is deliberately untouched** — lowering the score bar while it stands
> > tightens what `!!` means rather than loosening it, and `HighPriorityMarkerTest` plus two ledger
> > cases now pin it, because deleting the floor used to leave the whole suite green.

Three things to know before touching this. **Region mode is the first LOOSENING this config has taken**, so it is guarded from
both sides — the loader REFUSES `communes` and `postcode_prefixes` both being empty (the one shape
that fails open, and over-matching is invisible because it looks like a busy market), and an unknown
postcode is REFUSED in region mode though it is forgiven in list mode. That is not a hard-rule-9
violation but what hard rule 9 actually says: in list mode the name already matched and the postcode
only narrows, while in region mode the postcode is the only evidence there is. **And it caused a
regression no test of region mode itself would have found**: `Criteria::communeLabels` is the
vocabulary `EmailAlertSource` reads a commune out of an alert body with, and building it from
`communes` alone left it empty — every emailed listing would have silently lost its commune (no S1
score, nothing to name in the notification, a weaker dedup key) while still matching on its
postcode, so nothing would have looked broken. Ranked communes now feed that vocabulary too.

> **Two ranked sources were dropped the same day, and the catalogue was wrong in nine rows.** Seqens
> (A5) and Immobilière 3F's own site (A6) publish no vacancies at all — both dead-end at `al-in.fr`,
> because Action Logement's ESH allocate by commission. That makes **A4 AL'in the only route to that
> group's stock**, not one source among many. `docs/SOURCES.md` carries the measurements and the
> cheap pre-check that would have caught A2, A5 and A6 before any deep crawl: on WordPress ask
> `wp-json/wp/v2/types` (it enumerates CUSTOM post types, so it settles the "maybe the search is
> JS-rendered" objection a sitemap scan cannot); on any site scan the index page for `€`, `m²` and
> `disponib`.
>
> **Three more went the same way on 2026-08-21, and Track 1's BUILT stock is now FOUR sources — A12 was measured pollable that day and built on 2026-08-22 (below).** A10 Batigère was
> the catalogue's starred best-remaining candidate; its search is a third-party widget whose bundle
> names its backend, and that host's `robots.txt` answers **500** while the endpoint answers **401**.
> A7 1001 Vies has no listings post type and routes tenants to `demande-logement-social.gouv.fr`
> (out of scope, §1). A8 Antin's one recorded lettings route is a **404**. What remains in Track 1 is
> **A4 AL'in** (authenticated — an INPUT, not a decision) and the Tier B email-alert route.
>
> **A11 and A13 were the last two marker-scan rows, and both were measured on 2026-08-21 — so Track 1
> is now measured out, and this time the word is earned.** ⚠️ **That last clause was FALSE when
> written and is worth keeping as the correction it earned.** A14 and A15 were both `UNMEASURED` at
> the time and still sat in the table; "measured out" described the marker-scan GROUP and was written
> as though it described Track 1. **A14 RIVP was measured 2026-08-26** and is NOT pollable and out of
> scope by §1 — no lettings post type at all (`wp-json/wp/v2/types` settles it in one request), a
> `residence` type that is the heritage map, and a *"Je cherche un logement **social**"* route to
> `demande-logement-social.gouv.fr` stating `numéro unique` twice. **A15 Val d'Oise Habitat was
> measured 2026-08-26 and Track 1 is now genuinely measured out** — every catalogued row has a
> verdict with a date. A15 is the FIRST row blocked by an active ANTI-BOT CHALLENGE rather than by
> having no feed: every HTML request 302s to `/shield?u=…`, whose page solves a challenge in
> JavaScript and sets a `shield` cookie. **Hard rule 5 refuses that outright** — obtaining an access
> cookie by solving a bot-detection challenge is the same class as CAPTCHA solving, so this is a
> RULING and not a capability limit. Do not revisit it with a headless browser. Its older tenure
> blocker stands anyway (predominantly social, out of scope by §1 and Q4), and whether it offers an
> email alert is unknowable from outside the shield.** The exhaustive pass produced a THIRD kind
> of verdict, which the catalogue had no column for. **A11 Toit et Joie is `www.postehabitat.com`**
> (301; the domain was stale, the third in three rows) and it is the delegation pattern a fourth
> time: its availability search is real and returns **0 dwellings** — but that zero is only worth
> anything because the same form returns **8 parkings and 2 commerces**, which is the rule the row
> adds, *a search that answers 0 needs a CONTROL query*. Its lettings route links
> `demande-logement-social.gouv.fr`, out of scope under §1. **A13 Erilia is POLLABLE and worthless**:
> `/louer/recherche` is clean, stable, GET-paginated and quotes rent `cc` on all 49 listings — and
> **zero of the 49 are in Île-de-France**. *Pollable* and *useful* are different columns; a catalogue
> recording only the first keeps proposing work that cannot pay. Erilia also carries the furniture
> class a FIFTH time, in its worst shape yet: the footer widget *"Ai-je droit à un logement social ?"*
> classifies **SOCIAL 0.90 → REJECT**, so a selector capturing the page rather than the card would
> reject every listing on the source while its health stayed green. One thing is worth keeping from
> A11 — its `/Plafonds-de-ressources` page carries the **PLAI/PLUS/PLS ceiling tables** for IdF, the
> social half of the missing tier-4 input; it states **no year**, so it is a pointer, not a figure.
>
> **A12 Logirep/Polylogis IS SOURCE #4, live since 2026-08-22** — and it was the row ranked WEAKEST.
> `scout --domain=rent doctor --source=logirep` returns **113 annonces, 428 ms, `ok`**: one request, no pagination,
> so it is the cheapest source in the tree by a factor of fifty (In'li takes 24 s). One endpoint
> covers four Polylogis landlords. Four things about it are worth carrying forward:
>
> - **Its homepage carries two `€` and two `m²` and no lettings link — and those four markers ARE a
>   lettings search form.** It POSTs to `/` and its results route exists only as a **303 `Location`**
>   (`/recherche?ss_trnsctntp=leasing`). Hence the rule: *a marker count is not a route census* —
>   submit the form, read the `Location`, and check it against `robots.txt` **before** following it.
>   Here that mattered: Logirep disallows `/search/`, and robots matching is LITERAL, so it does not
>   reach `/recherche`. One path over and the answer would have been no.
> - **The payload is JSON inside a `<script>` tag**, which neither adapter could read: `html` maps
>   selectors over repeated card elements and there is only ONE tag, and `json` parses the response
>   body, which is HTML. `type: json` gained **`embedded_json_selector`** — one step in the middle
>   that pulls the element's text and hands it to the ordinary JSON path, so `items_path`, the field
>   map and `ListingMapper` keep exactly one implementation and hard rule 9 is not re-decided. A
>   selector matching nothing **THROWS**; returning `[]` would read as a quiet market forever.
> - **Drupal/Solr boxes every text field as a ONE-ELEMENT LIST** (`"…locality": ["AVON"]`), and
>   `Payload::string()` returned `null` for those. That is the most dangerous bug this build turned
>   up and it is invisible: `matchesCommune()` refuses a null commune, so the source would have
>   mapped 113 listings, matched none of them, ever, and reported a green `SourceHealth` with a
>   plausible count throughout. `Payload::scalarOf()` unwraps a list of scalars — never an
>   associative array, never recursively, and never treating `0` as absent.
> - **Rent is `h.c.` and its charges are NOT reliably recoverable**: a `Charges locatives` field on
>   one detail page (17%), free prose on another (30%), and nothing at all on a third. Two shapes,
>   two ratios, one absence — so no uplift is defensible. It is mapped `charges_included: false`,
>   which `CriteriaEngine` was already built for (*"charges comprises, and never on an HC-only
>   figure"*): the value lands in `rentHc`, `max_rent_cc` never fires on it, and the score line says
>   the ceiling is unverifiable. **Stated cost: the rent ceiling is not checkable for this source.**
>
> **Its live yield today is 0, and the catalogue said otherwise.** A12's row read *"8 of the 19 are
> in the 78/95 departments the criteria filter on… would plausibly yield on day one"*. Running the
> real gate over the frozen payload gave **1 of 113** — `matchesCommune()` needs the commune NAME as
> well as the prefix, and Bezons was the only overlap, on a commercial unit that
> `exclude_title_patterns` then rejects. A department is not a commune; the row said so one sentence
> after claiming the opposite. It is asserted by test, so the number cannot drift back into prose —
> and that test earned its keep two days later: widening the region to all eight departements took
> it to **19 of 113**, across eleven communes, and the assertion is what made someone write the new
> number down rather than discover it in production.
>
> Every apparent tenure hit on the source is false and they come from **UI furniture**, not prose —
> `PLAI` inside the facet `Plain-pied`, `LLI` inside `Ce·lli·er`, `PLUS` inside `plusieurs`, `SNE`
> inside `SURESNES`. All were run through `TenureClassifier`: every one returns `UNKNOWN`/`DIGEST` at
> 0.00, so the guards hold and **nothing needed fixing**. Two are now captured corpus cases, because
> a filter LABEL is not something careful listing copy can ever remove.
>
> Three rules came out of this round, all recorded in `docs/SOURCES.md` and `/add-source`. The A12 one is
> above; the other two: **a client-rendered page is not a dead
> end** — follow the widget's `script src` to its bundle, the bundle to its API host, then check that
> host; and **an unreadable `robots.txt` has two verdicts** — RFC 9309 makes 5xx *unreachable* (MUST
> assume complete disallow, stricter than this repo's own posture) and 4xx *unavailable* (MAY access,
> so a 403 is blocked by this repo's posture, not by the standard). Record which one applies, with
> the date: a 500 can be transient.

**SOURCE #2 IS LIVE, AND IT IS THE FIRST MIXED-TENURE ONE (2026-08-20).** CDC Habitat is
`enabled: true`; `scout --domain=rent doctor --source=cdc_habitat` returns **139 annonces, ~21 s**. In'li is pure
LLI, so until now *nothing exercised §1 against a real payload* — CDC ships `Logement intermédiaire`
and `Logement à loyer libre` badges in one result set and social stock in others. Three things about
it are worth knowing before touching a source again:

- **`robots.txt` decided the whole shape of the config.** CDC disallows `/Recherche/show/`,
  `/Recherche/search` *and seven search QUERY PARAMETERS by name*, so the parameterised search is
  refused outright. What its own `sitemap.xml` advertises is the lowercase, query-free
  `/recherche/location/<region>` tree — and robots path matching is case-sensitive, so `/Recherche/…`
  does not cover `/recherche/…`. That is why pagination here is `page_path` (a PATH segment,
  `/page-2`) and not `page_param`: appending `?page=2` would query a space the site asked robots to
  stay out of. Because the path changes per page, **robots is re-checked for every page**, and CDC's
  `robots.txt` is frozen at `tests/fixtures/rent/cdc_habitat/robots.txt` and asserted per page by test.
- **The walk now stops at the count the site declares**, rather than probing one page past the end.
  CDC's out-of-range page answers `301`, not empty, and the adapter refuses a non-2xx on purpose —
  a redirect landing back on page one ends a walk exactly like a genuine last page.
- **`fields['_text']` is prose and goes through `RawListing::text()`.** It used to be scanned as a
  structured field, where financing acronyms match case-insensitively; CDC's own tooltip defining
  *logement intermédiaire* contains *"au plus près"*, which was read as the excluded acronym `PLUS`
  and vetoed the card's own tier-1 badge. 14 of 16 correctly-badged listings were going to the
  digest. `plus` is one of the commonest words in French — In'li escaped this by luck.

Two smaller things landed with it, both hard rule 9: `Payload::floor()` reads floors as the prose
they are (`RDC` is **0**, not unknown; and the generic number reader would return the ROOM COUNT from
`3 pièces - 4ème étage - 82m²`), and `Payload::bool()` accepts the amenity noun `ascenseur`, which
can only ever yield `true` or `null` and so cannot manufacture the explicit `false` the high-floor
penalty needs. **`tests/fixtures/rent/tenure/corpus.json` now has CAPTURED cases** (130
total, 122 synthetic + 8 captured): two CDC cards — including the `au plus près` one, which is what
stops that classifier fix from being quietly undone — two Cityloger detail pages, and two Logirep
captures added 2026-08-22, one an ordinary card that states no tenure at all and one the site's own
FILTER FACET STRIP, which contains `PLAI` inside `Plain-pied`, `LLI` inside `Ce·lli·er` and `PLUS`
inside `plusieurs`. That last one is the furniture failure class in a shape listing copy cannot
avoid: a UI label, not prose.

**ICF Habitat Novedis (A2) is NOT pollable** and was dropped: measured three levels deep, its site
publishes a directory of *résidences* with zero rents, zero surfaces and zero occurrences of
`disponib`. It was ranked second on portfolio value, not on a verified feed — a `200` had been read
as a feed. See `docs/SOURCES.md`, whose A2 and A3 rows were both corrected.

### The email-alert path — four defects, all found by one real message (2026-08-25)

**`EmailAlertSource`, `EmailMessage`, `FileMailbox` and `ImapMailbox` were written BLIND**, and the
class docblock said so: *"No real portal alert has been seen yet… It is built to be SHAPED by a real
message."* Two SeLoger alerts arrived on 2026-08-25 and running them through the parser returned
**`body len: 0`, `links: 0`** — zero listings, no exception, a source that would look like a quiet
market for ever. Hard rule 3's exact shape, reached without a single `catch`, behind 1886 green
tests.

Four defects, and the ordering of them is the lesson: none was findable without a real payload, and
each hid the next.

1. **An empty MIME part claimed the answer.** RFC 2046 puts a *preamble* before the first boundary
   and nearly every real mailer writes one; read as a part it has no `Content-Type`, defaults to
   `text/plain`, splits to an empty body, and runs `$plain ??= ''` — which `??=` never overwrites.
   Three instances, all fixed as *an empty string is not an answer*, plus the structural half: index
   0 is never a part. The committed `email_demo` fixtures happen to have no preamble.
2. **The RFC 2047 whitespace collapse ran after the decode**, where no `?= =?` sequence survives to
   match — dead from the line it was written on. A folded French subject decoded to
   `exclusivit és`, and the subject becomes a listing's `title`.
3. **A rent could be assembled across a line break.** `\s` in the thousands-separator class matches
   `\n`, and `Payload::int` truncates there, so the rent became whatever sat on the line above:
   `ref 850` over `1 450 EUR charges comprises` extracts **850** — inside the plausibility band, six
   hundred euros low, clearing a ceiling the real rent does not. The separator is `\h` now.
4. **HTML entities reached the classifier undecoded, and that one is §1.** A portal's `text/plain`
   alternative is generated from its HTML, and SeLoger's does not decode entities on the way.
   `Text::fold()` refuses such text outright, naming whose job it is: *an entity inside a label
   deletes that label while leaving others intact*. `logement conventionn&eacute;` folds to
   `logement conventionn` — the label destroyed, the listing apparently unlabelled. Decoding is the
   safe direction and can only ever restore a label: no entity expands to `PLAI` or `PLUS`.

**SeLoger sends no listing URL and no listing id**, which is the design problem the source poses.
Every link is `click.by.seloger.com/?qs=<opaque per-recipient token>`; strip the query, as
link-identity does, and all sixteen links in one alert collapse to a single id — sixteen cards, one
identity, each carrying the FIRST rent and FIRST surface in the whole message. Hence
`params.card_separator`, which cuts the body on the card's terminal CTA, and `params.id_from:
content`, which keys on the dwelling's structural facts.

Three rules travel with that identity, each closing what another opens:

- **The rent is deliberately not in the key.** A price drop is an event this project exists to
  detect; in the key it becomes a brand-new listing with no history and no *en baisse* reason.
- **A no-information floor.** Without it every card whose extraction failed hashes to
  `sha1('seloger|||||')` and they all collapse onto that one id — the store's own *"nothing
  collapses onto a shared key"* guarantee violated one layer up, where the store cannot see it.
  Refusing costs nothing: a listing with no location can never match anyway (Q32).
- **Duplicate ids WITHIN one message keep ONE card, drop the rest, and ANNOUNCE the drop**; across
  messages they are the legitimate re-send content-addressing exists to recognise. Scope is still
  the whole distinction. **This was a `SourceError` until 2026-08-26, and what changed it is worth
  reading before changing it back.** A `Baisse de prix` digest carried three coliving ROOMS in one
  flat at Gros Saule, Aulnay-sous-Bois, each advertised with the WHOLE flat's `6 pièces . 83,99 m²`:
  commune, postcode, rooms, surface and residence genuinely identical, only the OLD price differing
  — and the rent is deliberately not in the key. **`seloger` returned zero listings for seven
  consecutive passes**, over three rooms `exclude_title_patterns` rejects anyway, while the thrown
  message asserted *les champs qui les distinguent n'ont pas été lus* — which was **false**: they
  were read correctly. Two indistinguishable units in one residence is the STATED cost of
  content-addressing arriving, an EVENT, and a throw is for a STATE. Same taxonomy, same mistake and
  same fix as detail hydration. **The silence is NOT relaxed** — the drop is warned on every pass
  that sees it, and `tests/sabotage-check.sh` carries that half as its own case, because a silent
  drop is the regression this change could otherwise become.

**The redirect is never followed at ingest** — one third-party request per listing, on a token tied
to the subscriber, manufacturing an engagement signal from a click nobody made. Hard rule 5's
*identify honestly* one step out. The unresolved link goes in the notification, where a human clicks
it. **Stated cost:** the link expires with the email, and a listing that later gains a
previously-missing surface changes identity once and so notifies once more.

`card_separator` with `mixed_tenure: true` is **refused at load** rather than answered with a
batch-veto mechanism: segmenting removes a regime stated once for a whole digest from every card,
which on a mixed source is a §1 decision nobody has made against a real payload. Outside the
`enabled` branch, because `--source=` force-runs disabled sources.

The corpus gained its **first captured case from an email**, and it is the `plus` class a fourth
time: SeLoger's own CTA button reads **`En savoir plus →`**. Unlike CDC's tooltip or Cityloger's
prose this belongs to the portal's template rather than to anyone's listing copy, so no careful
writing can ever remove it. A first version of the fixture test asserted the word `plus` was absent
from a card — unachievable, and wrong in kind: the guarantee is the classifier's verdict, not the
absence of a common French adverb.

### A title is a position, never a vocabulary (2026-08-26)

**`exclude_title_patterns` was unreachable on 37.5% of SeLoger's cards for a month, and every
document here said the opposite.** `title_pattern` required the line to begin with
`Appartement|Maison|Studio|Duplex|Loft|Chambre` — a guess at what an ESTATE AGENT types — and on
**27 of 72 live cards** it missed, at which point the title silently became the message SUBJECT.
The stored title of a real flat in Moret-Loing-et-Orvanne read `2 nouvelles annonces : Ile-de-France`.

Nothing about that reads as a fault: it is a plausible French sentence in a plausible field, and it
survived a fixture suite, a live acceptance run and a review round. **It was found by querying the
production database, not by any test** — `SELECT title, COUNT(*) FROM listings GROUP BY title` on
`state/rent-watch.sqlite3`, which is worth running after any source goes live.

What it cost is precise, and narrower than the first draft of this entry claimed.
`Criteria::excludedBy()` has two lists: `exclude_patterns` matches title **and description**, and
`exclude_title_patterns` matches the TITLE ONLY — deliberately, because `3 chambres` in a
description is the family flat the criteria are looking for. So `colocation`, `coloc` and the
meublé family kept firing through the description all along; what went unreachable is
**`^\s*chambre\b` and the parking/box/garage/cave/cellier/bureau/terrain family**, which have no
second surface. `^\s*chambre\b` is the exclusion added *because* four of this source's first nine
matches were coliving ROOMS passing every numeric filter.

> **A first version of the test asserted the Saint-Denis COLOCATION was now rejected, and it passed
> before the fix as well as after.** `\bcolocation\b` was catching it via the description the whole
> time. A true observation attached to the wrong mechanism — this repo's named failure, arriving
> while writing the fix for an instance of it. The test that replaced it holds the description
> constant and varies only the title.

Three things carry forward:

- **The replacement is STRUCTURAL**, and it is the correction `commune_pattern` already took on this
  same source: anchor on the layout the portal emits, not on the words someone chose. SeLoger writes
  `<rent> €/mois`, then the agency's free text, then `<n> pièces . <s> m²`; the title is the line
  above the `pièces` line. Measured over the same 72 cards: **27 rescued, 45 identical, 0 lost, 0
  fallbacks left.** The capture floor is **2 characters**, not 3, because `T5` and `T3` are real
  titles; `APARTMENT` is real too, and no French vocabulary list would ever have held it.
- **A configured pattern that misses now yields `''`, never the subject** — and a source configuring
  NO pattern keeps subject semantics, because there the subject IS the answer rather than a
  substitute for one. `''` restores no filtering on its own; what it buys is that the failure is
  VISIBLE instead of wearing an alibi. Hard rule 9 one layer up: an extraction failure is not a value.
- **THE OBVIOUS SABOTAGE DOES NOT DETECT THAT.** With the positional pattern in place every frozen
  card extracts, so the fallback branch is never entered and all six fixture suites stay green while
  the safety is deleted. `EmailAlertSegmentationTest` enters it on purpose with a pattern that cannot
  match. Both halves are in `tests/sabotage-check.sh`. **A guarantee whose branch no fixture reaches
  is dead safety code until something reaches it.**

`\bcoliving\b` joined `exclude_patterns` here (a live card read
`Menilmontant 287 -Premium Coliving House Paris 20`). The meublé patterns were deliberately NOT
widened — `MEUBLE - RUE WAGRAM` and `Beau 3P MEUBLÉ 59m²` both escape
`\b(?:location|louer|loue|appartement|logement|studio|bien|t[1-9])\s+meuble`, and widening it needs
the negation shapes checked first (`non meublé`), which is the lift-negation lesson.

### Bien'ici — source #6, and it disagrees with SeLoger on almost every decision (2026-08-25)

Three real alerts landed within ninety minutes of the subscription being created, and the source was
live the same evening: `scout --domain=rent doctor --source=bienici` returns **13 annonces, `ok`, 731 ms**, and a
seeded pass matches **10 of 13** — the best hit rate in the tree by a wide margin, because the
portal applies the saved search's own criteria before sending and those criteria mirror
`criteria.json`. Prove a change offline with
`MAILBOX_DIR=tests/fixtures/rent/bienici scout --domain=rent doctor --source=bienici`. Four things carry forward:

- **IT PUBLISHES A REAL LISTING ID, so identity is the LINK.** `/annonce/laforet-immo-facile-
  22588736` survives `stableId()` stripping the query. Content-addressing was invented for SeLoger,
  which sends sixteen cards behind one opaque redirect; that is a property of THAT portal, not of
  email alerts, and a real id has neither of the content key's stated costs. `cardListing()` gained
  a link-identity path for the segmented case; `id_from: content` still short-circuits, so SeLoger
  is byte-identical. **Pick the scheme BEFORE the source is first enabled** — nothing migrates a
  stored row from one key to another, so switching later re-notifies the whole backlog. *Ship
  config-only today and improve it later* is the trap here, not the safe option.
- **THE CARD SEPARATOR WAS MEASURED, AND COPYING SELOGER'S WOULD HAVE BEEN WRONG.** Splitting on the
  terminal call to action puts the alert's own criteria line — `1 200 € max - 3 pièces min - 45 m²
  min` — inside segment 0, and starts every later segment with the PRECEDING card's `RÉFÉRENCE :
  Cocon_Loc_T4`, whose `T4` the room reader takes. Over four live messages that is **3 of 13
  surfaces reading 45 and 1 of 13 room counts reading 4**, all under-reported, so `min_surface_m2`
  rejects a real match and nothing says why. `\nPhoto\n` — the line each card STARTS with — makes
  every segment exactly one card and leaves the header a segment of its own, which then drops for
  having no listing link. The four wrong values are asserted against the frozen payloads, so the
  separator cannot be "tidied" back to symmetry.
- **The no-information floor now guards the CARD, not the content key**, and that was found by
  regression rather than by design. Its first argument is identity collapse, to which link identity
  is immune — so a floor living in the content path alone stops applying the moment a portal
  publishes a real id. Its second argument does not depend on the key at all: a segment yielding a
  rent and nothing else is an extraction failure, and admitting it hides that failure behind a row
  quietly rejected for having no location. Retargeting the sabotage case then exposed a real hole —
  no test exercised *describes a flat but does not locate it*, so half the floor could be deleted in
  silence.
- **`params.from` is refused at load for an enabled `email_alert`**, the promise recorded as due
  *"with the next portal"*. One mailbox serves every portal, so it is the source's scope and what
  scopes the IMAP `SEARCH … FROM`. The blanket *"segmented needs `id_from: content`"* rule is
  replaced by the narrower one that survives: a segmented source keyed on its links must name
  `link_host`, because two cards ending on the same stray link is caught loudly at fetch while two
  cards ending on DIFFERENT rotating advert links is caught by nothing.

> **"THE ADDRESS IS ABSENT" IS THE WRONG TEST, AND `tools/scrub-eml.php` had been running it.** Every
> Bien'ici link carries `signedRecipient=eyJ…`, a JWT whose payload base64url-decodes to
> `{"email":"<the subscriber>"}`. Measured on a real capture: the literal address is absent from the
> decoded body, and one `base64 -d` recovers it in full — so the scrubber verified a true absence
> and wrote the file, reporting `scrubbed`. The right test is **not RECOVERABLE**: it now decodes
> every long base64url run and the quoted-printable form before it looks, and refuses when the
> address surfaces in any of them. Stated generally rather than as a Bien'ici special case, because
> the next portal's encoding is unknown. **The first fix still stripped nothing from all three real
> captures**: the tokens are quoted-printable, so a JWT reads `=3DeyJ…` and a `\b` anchor sees the
> `D` of `=3D` and refuses to start — the unit test passed on an unencoded fixture while the tool
> failed on every real one. `tests/test-scrub-eml.sh` now carries the QP case.
>
> `tests/php/Repo/FixtureSecretsTest.php` would have refused the committed fixture (it already
> matches JWTs). It would not have caught the scrubber reporting success, which is the half that
> matters: a tool nobody doubts is a tool nobody checks.

### leboncoin — source #7, and the first HTML-only alert (2026-08-26)

Its first alert ever fired at 07:33 Paris and the source was live the same morning:
`scout --domain=rent doctor --source=leboncoin` returns **3 annonces, `ok`, 864 ms**, and a seeded pass matches
**1 of 3** (Combs-la-Ville, 59,9 m², 935 €; the other two rejected at 48 m² and 45 m² against the
50 m² floor). Prove a change offline with
`MAILBOX_DIR=tests/fixtures/rent/leboncoin scout --domain=rent doctor --source=leboncoin`.

**IT NEEDED A PARSER CHANGE, not config alone — and the failure it would otherwise have produced is
this project's defining one.** leboncoin sends **no `text/plain` alternative**, the first portal to
do so. `stripHtml()` removes tags, and every URL lived in an `href` that went with them: the parser
produced a perfect 15 975-character body carrying all three listings and **zero links**. A source
with no links yields no listings and reports a quiet market for ever, while `doctor` says `ok` —
hard rule 2's exact shape, reached without a single `catch`. `EmailMessage::harvestHrefs()` moves
each anchor's URL into the body text.

- **Into the BODY, not just the side `links` array**, and that decides the whole design:
  `cardListing()` associates a link with a card by scanning **that segment's** text, so a URL known
  only at message level could never be attached to the card it belongs to. It is emitted *after* the
  anchor text so reading order matches the rendered one — a reasoned default rather than a measured
  one, because this payload cannot distinguish the two orders (each card links twice) and the
  sabotage case that claimed otherwise was **retired** rather than left green.
- **Only the HTML path.** A message with a plain alternative is untouched, which matters more than
  the feature: Bien'ici's identity IS its links, so a changed link set would re-key the whole stored
  backlog and re-notify every flat already seen. Asserted by the existing fixture tests passing
  unchanged.

**THE SEPARATOR IS THE CTA HERE — the opposite of Bien'ici — and the first attempt failed.**
`"\nVoir l'annonce\n"` matched nothing, because the CTA sits inside a run of spaces rather than
alone on its line, and the whole message parsed as ONE card: card 3's URL with card 1's rent,
commune and surface. One plausible-looking listing, and nothing about it reads as a fault. The
literal `"Voir l'annonce"` gives every segment exactly one card's data and its own trailing link.
Every value is asserted against hand-read ground truth, so it cannot drift back.

**Identity is the LINK** — `/vi/3256902167.htm` is a real ad id, and the tracking lives in a
`#fragment` that `stableId()` drops along with the query. Chosen before the first enabled pass,
because nothing migrates a stored row between key schemes.

**Rent is `hors charges` by decision, not omission**: the alert mentions charges **nowhere**
(measured — zero occurrences of `charges`, `CC` or `HC`), so the Logirep precedent applies. The
figure lands in `rentHc`, `max_rent_cc` never fires on it, and the score line says so. **Stated
cost: the rent ceiling is not checkable for this source.**

> **URLS ARE CLASSIFIED TEXT NOW, and that is the fifth instance of one failure class.** Harvesting
> hrefs into the body feeds every tracking parameter to the tenure scan. Measured on a campaign
> string carrying `plus`, `lli` and `plai`: two explicit label signals fired and conflicted a
> correct verdict into the digest. leboncoin's real campaign string contains none of them — luck,
> not a guard, and unlike the CDC tooltip or the SeLoger CTA, nobody can rewrite a portal's
> analytics parameters. `RawListing::text()` now strips a URL's **query and fragment** and **keeps
> its path**, and that split is §1: measured, `plai` as a path SEGMENT classifies PLAI/REJECT while
> the same acronym in `?c=plai_plus` classifies nothing, so blanking the whole URL would lose a
> social signal to save a campaign string. Both halves are corpus cases (`url-001`, `url-002`).

> **n=1.** One message, three cards. The separator and `commune_pattern` are measured on that single
> capture, and this repo has twice paid for generalising from one. The second alert to arrive is the
> first regression test.

### PAP — source #8, and the numeric twin of the title lesson (2026-08-26)

`scout --domain=rent doctor --source=pap` returns **2 annonces, `ok`, 483 ms**. Prove a change offline with
`MAILBOX_DIR=tests/fixtures/rent/pap scout --domain=rent doctor --source=pap`. It is the first **direct-from-owner**
portal, so its inventory does not overlap the agency portals every other private source draws from,
and structurally the simplest alert in the tree: a real `text/plain` part, **one listing per
message** (no `card_separator` at all), and a real ad id — `/annonces/-r458301723` — so identity is
the link.

**THE ALERT QUOTES THE SUBSCRIBER'S OWN SEARCH CRITERIA ABOVE THE LISTING, and every generic reader
is a first-match-wins `preg_match`.** Measured on the real capture before any config existed: the
surface read **45** — *"à partir de 45 m²"*, the search FLOOR — instead of the flat's 50. 45 is below
`min_surface_m2`, so **the first PAP alert ever sent would have been rejected for being too small**,
silently, with nothing reading as a fault. That is Bien'ici's defect a second time, **down to the
same number 45**, because the same saved search is used on every portal. Rooms read 3 and were right
*by coincidence* — the criteria line also says 3. Rent survived only because the periodic-figure rule
from the SeLoger price-drop fix outranks a bare `1.200 EUR`. The commune came back `null`, the
SeLoger regression exactly: Milly-la-Forêt is not a RANKED commune, so the vocabulary scan is blind.

The fix is four **POSITIONAL** anchors keyed on the `(NNNNN)` postcode line — the one landmark the
template guarantees — and deliberately not on vocabulary. **This is the numeric twin of *a title is a
position, never a vocabulary*.** Two new per-source params carry it, `surface_pattern` and
`rooms_pattern`, compile-checked beside the other three; **a configured pattern that MISSES yields
`null`, never the generic scan**, because falling back restores the defect *and* gives it an alibi —
the row reads as a small flat rather than as a broken extraction. An unconfigured source keeps the
scan bit-for-bit, and that counterweight is asserted, since without it the guarantee is satisfied by
deleting the feature.

- **`link_host` CARRIES THE PATH here**, as at leboncoin. The message has two links and **both are on
  `www.pap.fr`**: the annonce, and the unsubscribe page at `/utilisateur/alertes`, whose wording
  matches **none** of the noise words `looksLikeAListing()` rejects. A non-segmented source builds one
  listing **per accepted link**, so a host-only value yielded a phantom second listing carrying the
  real flat's rent, commune and surface under its own identity — notified as a separate flat, and
  never delisted, because an unsubscribe page never goes away.
- **`title_pattern` was INERT on every non-segmented source.** `listingsIn()` hardcoded the subject;
  only the segmented path consulted `cardTitle()`. A configured pattern doing nothing — and on this
  source it makes `exclude_title_patterns` unreachable, the In'li/SeLoger lesson a third time.
- **No fixture reaches the miss branch**, so `EmailAlertSegmentationTest` enters it on purpose. That
  is the dead-safety-code trap the SeLoger title walked into hours earlier, avoided by having already
  been paid for once.

**Stated cost: the rent ceiling is not checkable for this source.** The payload mentions charges
nowhere — zero occurrences of `charges`, `CC` or `HC` — so the Logirep and leboncoin precedent
applies and the figure lands in `rentHc`.

> **n=1 lasted about three hours.** The anchors were measured on ONE message with the risk stated;
> a second alert arrived the same afternoon (Meulan-en-Yvelines, 78250), confirms every anchor on a
> different commune, and adds the case the first lacked — a rent written `1.150 EUR / mois`, where
> the dot is a **thousands separator** and *"the rightmost separator is the decimal point"* would
> read it as 1 €. Both are frozen. Append a third; never renumber.
### Transit enrichment — the last empty layer, and the curve that had to be measured (2026-08-26)

`src/php/Rent/Enrich/` was the only spec layer with no code at all. It exists because **nothing in the
score discriminated**: 83 live matches spread over all eight departements scored 16–48, so
`high_priority_score: 70` could never fire and the `!!` marker was dead.

> **COMMUTE DID NOT ON ITS OWN REVIVE THE MARKER, and reading this paragraph as though it had is
> the trap.** It lifted the ceiling from 48 to **70** — measured the same day over all 256 stored
> snapshots — and the marker stayed dead anyway, because `!!` also needs `confidenceBp >= 80` and
> the listings that score highest are precisely the ones whose tenure is a source default at 50.
> The threshold is `50` since 2026-08-26 for that reason. Full measurement in the Q2 block above.

`Enrich/CommutePlanner` is the interface, `Enrich/NavitiaCommute` the IDFM/PRIM implementation over
the ordinary `HttpClient` seam — which is what makes `SCOUT_OFFLINE=1` cover it structurally
rather than by discipline. **Verified against the live API** (hard rule 1): base
`prim.iledefrance-mobilites.fr/marketplace/v2/navitia`, an `apikey` HEADER, and
`journeys?from=<lon>;<lat>` returning `duration` in **SECONDS**. Three details that are easy to get
backwards and silent when wrong — a reversed coordinate pair returns a perfectly plausible journey
between two other places. All three are sabotage cases.

**THE OBVIOUS CURVE WAS BUILT, MEASURED, AND MADE THE PROBLEM WORSE.** Treating `max_minutes` as the
zero point of a scale starting at 0 assumes short commutes are common; measured live, the affordable
communes run **68–131 minutes** (Sartrouville 68, Aulnay 88, Dammarie-les-Lys 112, Dourdan 131).
Under that curve Sartrouville earned 3 points of 30 and everything else earned zero — the component
separated the whole set by **three points** while adding 30 to `positiveTotal()`, so every score in
the tree dropped by about a quarter and the ordering barely moved. The shipped curve is **at or
under `max_minutes` is FULL MARKS**, decaying to zero at twice it: same data, **21 points of spread**,
and Sartrouville reaches 67 against the dead 70 threshold. Predicting this would have got it wrong;
one probe of four communes settled it.

- **A score component, never a disqualifier** — developer ruling, verbatim *"1 hour 15 max ! but keep
  showing even those with more anyway"*, and hard rule 8 independently. Clamped at both ends, so it
  can never go negative and can never act as a back-door rejection.
- **`commuteMinutes` lives on `RawListing`**, not as a `judge()` argument, because `scout --domain=rent reclassify`
  re-judges from the v7 snapshot — a value passed alongside would be absent on every re-judge and a
  stored listing would silently score lower the second time. `floor` and `hasElevator` arrive by the
  same route.
- **Enrichment runs before CLUSTERING**, so it is upstream of the snapshot and of every disqualifier
  (hard rule 8: a disqualifier applied before enrichment rejects on a field enrichment would have
  filled). Upstream of clustering specifically because `$observed` is keyed on object identity.
- **Cached per COMMUNE (schema v9 `commute_cache`), and a FAILURE is never cached** — caching one
  would turn a bad afternoon at the API into a permanently missing component, with nothing to retry
  it. The key is a NORMALISED commune plus postcode: the same commune arrives spelled two ways in one
  response, and commune names repeat across departements.
- **The reference departure is FIXED** (next weekday 08:30), not "now" — cached durations must share
  one timetable or a commune resolved at 02:00 is incomparable with one resolved at 08:30, on the
  heaviest component in the score. *Stated cost:* every duration is a one-time sample of that
  departure, reflecting neither the hour a listing appeared nor a strike.
- **Unknown is UNKNOWN, never far** (hard rule 9): the component goes unscored and the reasons say
  `trajet inconnu — hors score`, because on a phone a missing line reads as a short commute.

> **THE KEY WAS SHADOWED FOR ITS FIRST HOUR, and the cause was an instruction in this session.**
> `.env` ended up with TWO `IDFM_API_KEY=` lines — the empty template default, and the value appended
> after it by a `>> .env` one-liner. `Config\DotEnv` applies the FIRST occurrence and skips every
> later one, and an empty string counts as set, so the real key could never be read. The API said so
> plainly: `{"message":"No API key found in request"}`. **Never append a key that already has a
> template line — edit the line in place.** `.env.example` now carries that warning where it happens.

**Commute is OFF everywhere except the developer's machine.** The activation is a personal address
and lives only in the gitignored `config/rent/criteria.local.json`, and the loader's two-sided guard
refuses `weights.commute` without `commute.enabled` — so CI, the fixtures and the sabotage ledger all
run commute OFF, and the component is exercised by `tests/fixtures/rent/criteria/commute.json`.


**`seloger` IS LIVE as of 2026-08-25 — source #5, and the first that is not a landlord.** The IMAP
credentials arrived, and `scout --domain=rent doctor --source=seloger` against the real mailbox returns **9
annonces, `ok`, ~19 s**. Prove a change without touching the network with
`MAILBOX_DIR=tests/fixtures/rent/seloger scout --domain=rent doctor --source=seloger`; a seeded run over the two
fixtures yields one match (Dourdan, 3p, 52,37 m², 915 € CC) and one rejection (Conflans, 44,71 m²
under the 50 m² floor).

Two defects were found by pointing it at the real mailbox, and neither was findable any other way.
**Four of the first nine matches were COLIVING ROOMS** — a bedroom advertised with the whole flat's
room count and surface, so every numeric filter passed. Excluded by an ANCHORED title pattern
(`^\s*chambre\b`), never by a description match: `3 chambres` in a description is exactly the family
flat the criteria are looking for. **That exclusion was then INERT on 37.5% of the source for a
month, and this file said it worked** — see § "A title is a position, never a vocabulary" above.
And **every listing came back with `commune = null`** while its
postcode parsed correctly: `communeIn()` scanned only `Criteria::communeLabels`, which in region mode
is built from the RANKED communes, so a watch covering all of Île-de-France knew the names of a
handful of towns and no others. Nothing about that looks like a fault from outside — the listing
still matches on its postcode, so the push simply could not say where the flat was, `Dedup` got a
weaker key, and the S1 score could not fire.

The fix is **`commune_pattern`**, a per-source `params` regex read exactly as `title_pattern` and
`residence_pattern` are, with the vocabulary scan kept underneath as the fallback so a source that
configures no pattern is bit-for-bit unchanged. Three rules travel with it:

- **The anchor is the parenthesised postcode BELOW the name, not the quartier comma above it.**
  Measured across all 50 messages in the live mailbox: three of the nine cards (`Mormant`, `Garches`,
  `Moret-Loing-et-Orvanne`) carry no quartier line at all. **Both frozen fixtures do**, so a
  fixture-only test would have proven the wrong shape confidently — the n=1 generalisation this repo
  has already paid for twice. The no-quartier shape has its own test.
- **The pattern beats the vocabulary, deliberately.** The scan is a substring search over the whole
  card, so a card in Mormant whose copy says *"proche Dourdan"* returns Dourdan — the prototype's
  documented over-matching defect. The pattern reads the field the portal laid out.
- **It is compile-checked at load**, alongside the other two, because its failure has an alibi: a
  broken pattern falls back to the scan and reads as *"a listing in an unranked town"* rather than as
  a fault. `matchParam()` uses `@preg_match`, which neither warns nor throws.

> **A §1 RESIDUAL, stated rather than left to be discovered.** `seloger` is `mixed_tenure: false`,
> which this file already rules defensible for a private-market portal — but going live is what
> makes the flag act. What holds and is asserted: an explicit `PLS`/`PLUS`/`PLAI`/`conventionné`
> anywhere in a card is caught at 0.90 by the tier-2 label rules, which **never consult
> `mixed_tenure`**, and a real frozen card with one injected must REJECT. What does not: a card
> stating no tenure at all takes the source default `LIBRE` and matches — which is every live card,
> and the notification says so in its own reason line. It matters because the mailbox proves
> **In'li and CDC Habitat both advertise on SeLoger**, and In'li was itself proven not pure LLI. The
> flag is not armed because SeLoger cards state no tenure at all, so `true` would digest **100% of
> the source** — the In'li lesson of 2026-08-23, *"not §1 satisfied, it is the tool switched off"*.
> PLAI and PLUS are allocated by commission and are not advertised on commercial portals; **PLS
> occasionally is, and that is the residual.** Reversed by one line — `mixed_tenure: true` — and
> `docs/plans/seloger-email-alert.plan.md` records what that costs.

> **ONE MAILBOX SERVING MANY PORTALS IS A SHARED BUDGET, and it zeroed a live source hours after it
> went live (2026-08-25).** The developer widened their Gmail filter to catch five portals and
> re-labelled a year of archive into the same label; SeLoger went **9 listings → 0**, and the only
> thing that said so was `SourceHealth` (`warn_drop`, 0 against a 7-day mean of 9). Measured: the
> folder held **1436** messages, `SEARCH SINCE` matched **124**, and their sequence numbers began at
> **6** — so `fetchRecent`'s tail-of-folder read contained none of the day's alerts. **The mechanism
> behind that ordering is deliberately NOT written down**: a first draft explained it as re-labelling
> minting fresh high UIDs, which its own evidence contradicts, and *a true number attached to an
> invented cause* is this repo's named failure. What is measured is that sequence order disagrees
> with date order. Two fixes: **`SEARCH SINCE`**, so what counts as recent is the SERVER's answer
> about dates (`IMAP_SINCE_DAYS`, default 7, a window of 0 clamped to 1); and **`FROM <the source's
> own sender>` pushed into the query**, so each source gets its own window rather than a slice of
> one — without it a busy portal starves a quiet one silently, and it worsens with every source
> added. `scout --domain=rent doctor --source=seloger` → **74 annonces**, from 0.

> **A rent is a PERIODIC amount, and a wider window is what proved it.** A live `Baisse de prix`
> card quotes three figures — the reduction `baissé de 100 €`, the new rent `1 100 €/mois`, the old
> `1 200 € ↘ 8%` — and only the rent carries a period. The reader took the first and returned 100,
> below the plausibility floor, so the card was refused and the source reported `broken` on an
> unchanged template. **That is luck, not a guard: a 300 € reduction would have been returned as a
> rent**, inside the band and clearing a ceiling the flat comes nowhere near. So a periodic figure
> outranks a bare one, and every match of a pattern is examined rather than only the first —
> `preg_match` stopping at the first hit is what let one implausible figure hide a readable rent
> three lines below it.

> **The mailbox is the developer's personal one, and the `from` filter is doing real work.** Of 50
> messages only 3 are SeLoger alerts. Twelve come from `seloger@s.seloger.com` — *contact receipts*,
> whose unfilled template reads `En avant-première870,00 €cc /mois. · m² · pièces · chambres` beside
> the commune *Saint-Germain-de-Tallevend-la-Lande* (Calvados) and the postcode `75015`: a rent in
> the plausible band, a valid IdF postcode, and a town 250 km away. They are excluded by
> `params.from`, and would be refused again by the no-information floor. Both layers earn their keep.

**THE CAR DOMAIN EXISTS AS OF 2026-08-29 — `scout --domain=car`, `src/php/Car/`,
`config/car/`.** A second domain, not a parameterisation of the rent path: `VehicleListing`,
`VehicleClassifier` (the §1 vehicle set, non-overridable, NEGATION READ FIRST because every term
arrives negated in honest copy), `VehicleCriteria` + `VehicleScorer` (one hard ceiling, one
stated-location filter, everything else a clamped score component), `VehicleStore` (own tables on
its own file, composing the housing `Store` for runs/health/alerts), two adapters (`VehicleEmailSource`
with positional card readers; `SitemapVehicleSource`, the detail-hydration pattern applied to a whole
source), `VehiclePipeline`, `VehicleFormatter`, `Car/Cli/CarScout` (was `Cli/VehicleScout`). The rent path changed in two
places only: the `--domain=car` dispatch line and `Cli/ChannelFactory`, extracted so both CLIs build
channels from one place. First slice: ParuVendu (email, samples its feed — 3 cards per message) and
Autohero (sitemap + JSON-LD, seed before watching). Rulings: `docs/plans/scout-rename-and-car-domain.plan.md`;
build record: `docs/plans/car-domain-first-slice.plan.md`. **The §1 tripwire hook does not cover
the vehicle set** — a `tests/test-vehicle-guard.sh` is owed, recorded there.

`src/phorj/` is **ON INDEFINITE HOLD** (developer ruling, 2026-08-19) — not blocked, deprioritised.
Do not start it; `docs/PHORJ-REQUIREMENTS.md` remains the record of what it would need.

**Q27's LIVENESS SIGNAL IS LIVE (2026-08-22), and it was ruled but unbuilt.** `HEARTBEAT_HOURS` (today `RENT_HEARTBEAT_HOURS`) sat
in `.env.example` read by no code at all: `NotificationKind::HEARTBEAT` existed, but only
`test-notify` used it, so a watcher that died at 03:00 was indistinguishable from one watching a
quiet market until somebody thought to look. `scout --domain=rent run --watch` now emits a LOW-priority beat
every `RENT_HEARTBEAT_HOURS` (default 24) — **whether or not anything matched**, which is the
entire point — carrying passes completed, listings notified and sources OK. **The startup beat is
`isDue()`-gated, not unconditional** (`Scout::runCommand()`, the startup `isDue()` check — cited by LINE for one round, and the line moved twice; a symbol survives an edit above it): the marker is on the mounted volume, so a
restart inside the interval sends nothing, and only a cold start — no marker — beats immediately.
That is the correct behaviour (a redeploy loop must not spam the channel) but it is NOT a
channel-health check, and reading it as one costs time: a redeploy on 2026-08-23 15:48 left the
marker at the previous day's 22:15 and that looked like a fault for several minutes. To prove the
DEPLOYED image can reach the user, run `docker compose run --rm rent-scout test-notify`. `Core/Heartbeat` is the
pure policy (clock injected; a cold start is due, an unreadable marker is due, a marker in the
FUTURE is due — the bias is always one beat too many, never one suppressed), and the marker lives at
`state/rent-heartbeat.txt`, on Q8's mounted volume, so it survives the container being replaced. An
unusable `RENT_HEARTBEAT_HOURS` is a **loud refusal at startup**, not a silent fallback: `0` would
disable the one signal that distinguishes a dead watcher from a quiet market.

**Its health figure counts what the run WATCHES, not what the config enables** (fixed 2026-08-22,
found by running the real container). It read every enabled source, so `--watch --source=x` against
the shipped config reported *"1/5 source(s) en bon état"* — four faults that did not exist, in the
one channel whose entire value is that it can be believed; and it degrades, because an unpolled
source's health record goes `STALE`, so the beat would eventually alarm every day about sources
nobody asked it to watch. **The scope travels with the figure**: when a `--source` is in play the
beat says so and gives both counts, because silently reporting `1/1` would let a deployment with a
forgotten flag look flawless for ever while four landlords went unwatched. The banner states the
count too, but a banner is a log line read once and the beat is what reaches the phone.

Two things about that call site are worth knowing before touching it. **The in-loop beat — the one
that fires on day two — is unreachable under a fixed clock**, because the startup beat writes the
marker at `NOW` and every later check asks `isDue(NOW, NOW)`. That is what makes *"exactly one
beat"* assertable, and it also meant the loop's own call site was never executed by any test: the
argument added above lives outside the closure's `use` list, and the first genuinely due beat would
have thrown a `TypeError` and killed the watcher 24 hours into an unattended run. The way in is to
make the marker **unwritable** (a directory where the file goes): `beat()` writes it with
`@file_put_contents` precisely so a full volume cannot crash a liveness signal, `lastHeartbeat()`
reads `is_file()`, so every check is due — and two beats is then the *correct* result, per the
documented bias. All three guarantees are in `tests/sabotage-check.sh`.

Q27's other half landed with it: a startup refusal from `run` writes `state/rent-last-refusal.txt`, and
the next successful start reports it on the beat and clears it. That covers the failure that reaches
nobody — the process exits before any channel exists, and under Docker its stderr scrolls past in a
log nobody reads. **`ConfigError` and `SourceError` during `run` are recorded too**, because a
malformed config is the commonest startup refusal there is. The note is `Redact`ed before it touches
the disk — a `ConfigError` message quotes the offending VALUE back, which is exactly how a pasted
`imap://user:password@host` ends up in a file.

**No test reaches the network, and that is now structural rather than accidental.**
`tests/bootstrap.php` sets `SCOUT_OFFLINE=1` and `CurlHttpClient::send()` refuses any
third-party host (loopback stays allowed — the wire tests need a real socket). Before In'li was
enabled the offline guarantee held only because every source was disabled; enabling one turned the
suite into a four-page-per-test crawler of a live landlord's site within a single run.
`scout --domain=rent doctor|run --source=<name>` (repeatable) limits a run to one source, which is what onboarding
the next source needs and what keeps the CLI tests off the shipped source list.

**`--source=<name>` also FORCE-RUNS a source that is `enabled: false`** (2026-08-22), and only an
explicit name does — an ordinary pass still skips it, which is asserted separately because deleting
the enabled check is the over-correction. It is a repair, not a convenience: `/add-source` step 5
prescribes running `scout --domain=rent doctor` against a new block *before* flipping the flag, and that order was
impossible while a disabled source could not run. `dump` always behaved this way; the verbs now
agree. Three things travel with it. The run SAYS the source is disabled, because a `--source` left
behind in a deployment is otherwise indistinguishable from one somebody enabled on purpose. Hard
rule 1's `REMPLACER` refusal moved into `Scout::buildSource()` — the single funnel every verb passes
through — because the loader's `enabled: true` check was the entire guard for as long as a disabled
source could never be polled. And hard rule 4's scraping opt-in still fires on a force-run private
portal, asserted, because the enabled check sits above it.

**`fixture_demo` is `enabled: false` for the same reason** — it had shipped enabled since before any
real endpoint existed, so a real pass reported *"5 source(s), 491 annonce(s) · 14 correspondance(s)"*
where 10 listings and 6 matches were fabricated. Nothing fake ever *pushed* under the documented
`--seed` → `--watch` flow (a frozen payload is never new after seeding), so the cost was every number
the operator reads — pass totals, `doctor`, `SourceHealth`, the Q27 beat — plus a real fake push on
any path that loses the seen-set. `ConfigTest::testNoFixtureSourceShipsEnabled` stops the flag
creeping back.

**Two more environment seams, both of them there so a test cannot become a hang or a crawl**
(2026-08-19). `SCOUT_MAX_PASSES=<n>` bounds `scout --domain=rent run --watch` to n passes; absent — the
normal case — the loop runs until stopped, and when it is set the watcher SAYS so on its banner
every time. `tests/php/Rent/Cli/RentScoutTest.php` sets it for every test in the class, because `--watch` is
the one verb whose success case never returns: a test that expects the run to be refused and is
wrong does not fail, it blocks, and it blocks the suite and the sabotage ledger behind it. That was
observed — disabling the Q36 guard made the ledger sit on its FIRST case for eleven minutes printing
nothing. `tests/sabotage-check.sh` now also runs each case under `timeout` (`SABOTAGE_SUITE_TIMEOUT`,
default 300 s) and counts a suite that never finished as a loud FAILURE, since a hang is not a
detection.

**The Q36 flood guard reads the ROWS, not the file** (fixed 2026-08-19). `scout --domain=rent run` refuses to
notify while `Store::isSeenSetEmpty()` — a missing volume mount produces a valid, empty, migrated
database indistinguishable from a healthy one, and every historic listing would push at once. The
guard used to ask whether `Store::open()` had CREATED the file, which any earlier command that
merely opened the database answered away: `scout --domain=rent doctor` opens it, so typing the first command a new
machine invites you to type disarmed the guard for the following run. Q36's other half — a mount
marker file — is WITHDRAWN rather than unimplemented; `docs/OPEN-QUESTIONS.md` records why it cannot
fire in either placement.

**The five sabotage gaps are closed as of 2026-08-12**, each verified individually by a targeted
mini-run going 7/7 red (the two fixed sed expressions included): honest User-Agent pinned;
SMTP-without-STARTTLS proven refused via a scripted loopback server and its wire transcript;
`SmtpTransport::secrets()` wiring proven by a server that echoes the base64 credential back; the
rent plausibility band exercised end to end; and a fifth found while closing them — the block-tag
test covered `</li>` while the sabotage degraded `</p>`; it now iterates the whole tag class. The
full-ledger count is recorded in `docs/plans/milestone-1-pipeline.plan.md` as each run completes.

Anything below describing `enrich` or a real landlord endpoint is the **target**, not
the present. Do not report findings against files that do not exist yet, and do not name `pytest` as
though it were wired — the PHP suite is the only test runner here.

**Every question is closed as of 2026-08-07** — see [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md).
All 25 were resolved in one pass by applying each question's own documented default, on the
developer's instruction. **Nothing is blocking; milestone 1 proceeds.** A default applied is not a
preference expressed: every entry names the one line that reverses it.

Four things are still outstanding, and they are **inputs rather than decisions** — no default can
supply them: the DevTools cURL captures for the first sources (hard rule 1 forbids writing an
endpoint from memory), IMAP credentials for the alert mailbox, one real portal alert email to shape
the parser against, and the `plafonds de ressources` figures for classifier tier 4. **Three of the
four are now closed** — the alert email and the credentials arrived 2026-08-25, and the figures were
fetched and committed 2026-08-26.

**The alert email arrived on 2026-08-25 and the IMAP credentials with it, so that whole track is
CLOSED** — `seloger` is live (§ "The email-alert path"), and pointing the adapter at a real mailbox
cost six defects in one day: four in the MIME parser, one rent reading 600 € low, and four coliving
rooms scored as family flats. **A real payload is the input; a green suite is not a substitute for
one**, and 1 900 of them said nothing about any of the six.

**Bien'ici was the third of those, and it CLOSED the same evening** — the developer recreated the
alert with the current criteria, three messages landed within ninety minutes, and the source was
live. See § "Bien'ici" above. It confirmed the prediction that had been recorded here (a real
listing URL carrying a real listing id, so link identity works) and disproved the assumption
travelling with it: link identity was *unreachable* on a segmented source, because `identityFor()`
answered only for `id_from: content`. **A prediction about a payload is not a prediction about the
code that would read it.**

**leboncoin FIRED ITS FIRST ALERT ON 2026-08-26 and is source #7** (§ "leboncoin"). Before that
morning it had sent only a new-device notice, which is what the paragraph here used to record.

**PAP is the one portal still silent.** Two *Création de votre alerte* receipts and one
*Suppression*, all on 2026-08-25 at 19:29, and no search alert since — so one alert should survive
and is producing nothing. The ask is *check the surviving alert carries the current criteria*, not
*send a file*.

> **This paragraph also said "Jinka has sent a newsletter and no alert", and that was WRONG** — a
> mailbox census on 2026-08-26 found **two real alerts**, on 12 and 15 August, alongside the
> newsletter. The claim was written from a partial look and never re-checked, which is the same
> failure class as the retired *"live yield is 0"* entry: a confident statement about a source's
> behaviour, formed once and repeated. **Jinka is still not a candidate**, for a reason that
> survives the correction and is stronger than the old one: its `text/plain` part is **78 bytes
> total** — *"Bonjour, Sur votre alete Jinka, 1 nouvelles annonces ont été reçues"* — carrying no
> rent, no surface, no commune and no link, so everything is in the HTML and its links are
> `sendgrid.net` tracking redirects. It is also an AGGREGATOR rather than a portal, so it needs its
> own §1 evaluation before it is treated as a source: a truncated description can lose a `PLS` label
> the original listing carried.

The `plafonds` figures were reassigned the same day — hard rule 1 forbids writing a ceiling *from
memory*, not verifying one against a live authoritative source — and **they were fetched and
committed on 2026-08-26**, from two dated official publications, each carried in
`Core/PlafondBands` with its URL: the intermediate ceilings from BOFiP **BOI-BAREME-000017**
(published 2026-03-10, CGI annexe III art. 2 terdecies H), the social ones from the DRIHL's
*"Annexe 4 : grille des plafonds de ressources 2026"* (arrêté du 19 décembre 2025), both on the
2024 revenu fiscal de référence.

> **THE FIGURES REFUTED THE RULE EVERYONE ASSUMED WOULD BE BUILT, and that is the whole story of
> tier 4.** The assumption — at or below the highest social ceiling means social, above it means
> intermediate — fails twice against the real tables. **Even at the SAME household size**, zone B1's
> intermediate ceilings sit BELOW the Paris PLS ceilings for every size from two upward (B1 couple
> 48 268 € against PLS 52 303 €); only 13 of the 18 (zone, size) pairs separate at all. And a
> listing quotes a bare figure with **no household size**, so the sizes must be collapsed — at which
> point the bands overlap from 36 144 € to 109 595 €, a 73 451 € range. Under the assumed rule every
> genuine intermediate ceiling reads SOCIAL: not a §1 breach, since over-rejecting is the safe
> direction, but the tool switched off on the source producing most matches — and zone B1 is exactly
> where the current matches are (Dourdan, Dammarie-les-Lys). It is the numeric echo of a lesson the
> classifier already learned in words: `plafond de ressources` was rejected as a *text* signal
> because LLI has income ceilings too.
>
> **So tier 4 concludes in ONE direction only:** strictly below the lowest intermediate ceiling in
> Île-de-France (36 144 €, zone B1, one person) a figure cannot be an intermediate ceiling, so the
> financing is social. Above that it emits **nothing** — never an intermediate verdict, because
> manufacturing eligibility from a number is the §1-dangerous direction and `PlafondBands` refuses
> such a band *at construction*; and never a doubt either, because a numeric doubt would contradict
> a correct tier-2 label into the digest exactly as `loyer plafonné` once did to `lli-004` and
> `lli-011`. The threshold is DERIVED from the committed table, not written beside it, so the two
> cannot drift at the next January revaluation. **The boundary is strict**: 36 144 € IS an
> intermediate ceiling, and the scaffolding shipped with `<=`.
>
> **Stated cost:** it catches social listings quoting small-household ceilings without naming their
> scheme — PLAI at every size, PLUS for one to two people, PLS for one. It cannot catch a
> large-household social ceiling, and that is a property of the French figures rather than of the
> implementation.
>
> **The extraction is where the dangerous false positive lives**, not the arithmetic. It anchors on
> `plafond de ressources` and never a bare `plafond`; reads the negation first (`sans plafond de
> ressources` is ordinary private-market copy); applies an annual-income plausibility floor, twin of
> the rent band; examines every match rather than the first; and uses `\h` for thousands separators
> so a figure cannot assemble itself across a line break. A sabotage run showed the obvious
> demonstration of the anchor proves nothing — folded, `plafonné` is not `plafond`, and a rent is
> below the floor anyway — so corpus case `plafond-005` uses an intermediate ad quoting its own
> **rent** ceiling as a plausible annual figure, which defeats both other guards and leaves only the
> anchor standing.

---

## ⛔ The one non-negotiable rule

> **`logement social` (PLAI, PLUS) must NEVER be surfaced as a match.**

This is not a config toggle bolted on at the end. It is a first-class domain concept with its own
module (`src/php/Rent/Core/TenureClassifier.php` + `Tenure.php`), its own test suite, and a
**fail-closed default**. It is an
**eligibility fact**, not a ranking preference: the user is not eligible, so a social-housing false
positive is not a slightly-wrong result — it is a wasted application and the reason a user stops
trusting the tool.

Concretely, when working in this repo:

- The excluded set — `PLAI`, `PLUS`, `PLS`, `ANRU`, `ANAH`, `conventionné` absent an explicit
  intermediate label — is **not user-overridable**. Do not add a config key, flag, env var or default that can
  re-enable them. Such a key is a P0 finding even if nothing currently sets it.
- If the classifier's confidence is `< 0.6` **and** the source is known to mix social and intermediate
  stock → tenure is `UNKNOWN`, and the listing goes to the low-priority *"à vérifier"* digest. It must
  **never** be emitted as a match.
- Bias every ambiguous decision toward *not notifying*. A missed listing is annoying; a
  social-housing false positive makes the tool untrustworthy, which is worse.
- Never weaken a classifier test to make a change pass. If a fixture goes red, the classifier
  regressed — fix the classifier, not the fixture. A skipped, xfailed, deleted or relabelled fixture
  is P0 unless the old label was demonstrably wrong and the evidence is in the commit message.
- `.claude/hooks/tenure-guard.sh` is a **tripwire on this rule, not a guarantee** — it greps, it does
  not reason. It runs PostToolUse and exits 2 when it fires, feeding its warning back into the turn.
  If it fires, stop and explain the change before continuing. A clean run proves nothing.

---

## Routing

Work here is handled with the **global reasoning framework** (`~/.claude/CLAUDE.md`) — the 8-phase
workflow, the four-dimension Completion Gate, evidence grades, the anti-bandaid gate. That framework
is the developer's own persistent install; this repo never writes it — the container-era
`scripts/claude-bootstrap/` reinstaller was removed 2026-08-18. On any conflict, **this file wins**.

Repo-native slash skills live in `.claude/skills/` and reviewer agents in `.claude/agents/`; both are
read in place, nothing is installed. `ls .claude/skills/` is the authoritative list — a count written
in prose drifts, so none is written here.

## Questions — `AskUserQuestion`, sparingly

Questions to the developer use the **`AskUserQuestion` tool**, per the global framework: options with
the recommended one FIRST (labelled, with its reason) and a visible *"none of these / challenge the
premise"* escape. Protocol details: `.claude/skills/scout-ask-human/SKILL.md` (renamed from
`ask-human` 2026-08-18 — a repo skill may not share a global skill's name).

> The container-era plain-text protocol and the `❓`/`⏹` end-of-reply markers are **RETIRED**
> (2026-08-18). They existed because `AskUserQuestion` timed out in the dead cloud container; on this
> machine it works, `askUserQuestionTimeout` is `"never"` globally, and the marker's rationale
> (a prose question being indistinguishable from a pause) dies with the prose protocol.

**Do not ask about routine work.** The standing directive for this repo is *no interrupts*: announce
the task size and the plan, then build it. Asking is reserved for the cases in
§ "When this protocol is mandatory" of that skill — chiefly a genuinely ambiguous request, a
user-visible product decision, or anything that would weaken an invariant below. **Never ask whether
to weaken the social-housing exclusion** — ask *how* to satisfy it.

Every unanswered question is also written to `docs/OPEN-QUESTIONS.md` with the default that applies if
it stays unanswered. A question asked only in chat is lost at the next session.

---

## Domain glossary — read this carefully

Tenure is a property of the **listing**, not of the **source**. In'li is pure LLI, but CDC Habitat,
Vilogia, Immobilière 3F and Seqens publish social *and* intermediate stock on the same pages, sometimes
in the same result set.

| Term | Meaning | In scope? |
|---|---|---|
| **LLI** — Logement Locatif Intermédiaire | Ordonnance 2014-159. Rent capped ~10–20% below market. Income ceilings exist but are far above social housing. Zones A bis / A / B1 only. Allocated **directly by the landlord** — no commission, no SNE number. | **YES — primary target** |
| **PLS** — Prêt Locatif Social | Highest tier of *social* financing. High ceilings, often marketed alongside intermediate stock. Was genuinely ambiguous; the Q4 answer settled it. | **NEVER** — ruled 2026-08-06 (Q4) |
| **PLUS** — Prêt Locatif à Usage Social | Mainstream social housing. Requires SNE registration (numéro unique), allocated by commission d'attribution. | **NEVER** |
| **PLAI** — Prêt Locatif Aidé d'Intégration | Very-low-income social housing. | **NEVER** |
| **LIBRE** | Private market rate, no cap, no income condition. SeLoger / Leboncoin / PAP / Bien'ici / agencies. | **YES** — ruled 2026-08-06 (Q4), a full match on its own track |
| **ANRU / ANAH / conventionné** | Various subsidised regimes. Treat as social unless explicitly labelled intermediate. | **NEVER** |

Classifier signal priority (highest → lowest confidence). A lower-priority signal must never override a
higher one:

1. **Explicit structured field** — `financement`, `typeProduit`, `categorie`. Worth real effort to find.
2. **Explicit label in text** — `logement intermédiaire`, `LLI`, `loyer intermédiaire`, `PLS`, `PLUS`,
   `PLAI`, `logement social`, `conventionné`. Must match accent- and case-insensitively.
3. **Procedural tells** — `numéro unique d'enregistrement`, `SNE`, `commission d'attribution`,
   `demande de logement social` ⇒ strong social signal. `sans condition de commission` or a
   direct-booking flow ⇒ intermediate signal. The cheapest reliable discriminator the domain offers.
4. **Plafonds de ressources** — compare quoted ceilings against known LLI vs PLUS/PLAI bands for the zone.
5. **Source default** — lowest confidence, used only when nothing else fires. An **absent** signal must
   *lower* confidence, never silently inherit `default_tenure` at full confidence.

---

## Architecture

Single repo, **two languages**, layered, with **adapters as the only site-specific code**.

Two languages because the developer ruled 2026-08-06: *"do it in both phorj and php so i can test
phorj lift and transpile"*. So the tree is `src/<lang>/`, and the spec's single-language `src/core/`
is amended accordingly. The **pure core** — `models`, `tenure`, `criteria`, `dedup` — is written
twice and diffed fixture-by-fixture against one shared corpus. Everything that touches IMAP, HTTP,
SQLite or SMTP stays PHP-only: phorj refuses to transpile those domains, so a whole-app port is
impossible by design rather than by omission (`docs/PHORJ-REQUIREMENTS.md`).

| Layer | Path | Responsibility |
|---|---|---|
| Entry point | `src/php/Cli/` | `Scout` — the `--domain=<slug>` dispatcher, which NEVER defaults — plus `Domains` (the registry: a new domain is one entry), `WatchLoop`, `ChannelFactory`. `bin/scout --domain=rent …` / `--domain=car …` |
| Core (generic) | `src/php/Core/` | What no domain owns: `Text`, `Redact` (masks secrets in adapter error text), `Pacer`, `Heartbeat`, `health` (`SourceHealth` + `SourceStatus`), `Offline`, and the Notify channels/transports |
| Rent domain | `src/php/Rent/{Core,Config,Adapters,Store,Enrich,Notify,Cli}/` · later `src/phorj/core/` | Everything housing-bound: `models`, `tenure` (the classifier), `criteria` (score + hard disqualifiers), `dedup`, the SQLite store, the field maps and source contract, transit enrichment, the rent formatter and `Cli/RentScout` |
| Car domain | `src/php/Car/` | The vehicle twin — `Vehicle*` listing, classifier, criteria, scorer, store, sources, pipeline, formatter — and `Cli/CarScout` |
| Store | `src/php/Rent/Store/` | SQLite seen-set, price history, run log and the schema-v4 cross-portal `group_key`. **PHP-only** — it touches a database, so phorj will not transpile it. |
| Notify | `src/php/Core/Notify/` | One module per channel. Every notification carries `score` + human-readable `reasons[]`. |
| Adapters | `src/php/Adapters/` (generic: `Http/*`, `Mail/*`, `SourceError`, `FeedFreshness`) · `src/php/Rent/Adapters/` (the `Source` interface, `http_json`, `html`, `email_alert` (IMAP), `browser` (Playwright, opt-in), `sites/` for per-site overrides) | Site-specific code lives ONLY here |
| Enrich | `src/php/Rent/Enrich/` | `transit` (IDFM / PRIM door-to-door commute), `geo` (commune → INSEE code, coords) |
| Config | `config/<domain>/` — `config/rent/`, `config/car/` | `criteria.json` (user criteria), `sources.json` (source definitions + field maps) — both committed. **JSON, not YAML** — ruled 2026-08-07 (Q22): no `ext-yaml` here and no way to install one. `_`-prefixed keys are comments; any other unknown key is a validation error. A gitignored `criteria.local.json` overrides field-by-field |
| Fixtures | `tests/fixtures/<domain>/<source>/` | Frozen HTML/JSON payloads, and frozen `.eml` alerts for an `email_alert` source. Parser tests run **offline**. No network in CI. |
| Classifier corpus | `tests/fixtures/rent/tenure/corpus.json` | **Language-neutral.** Read by both implementations — that shared file is what makes the differential test mean anything. |

PHP is **8.5**, no runtime dependencies, PSR-4 `Scout\` → `src/php/`. The test runner is
PHPUnit's official PHAR, not a Composer dev dependency — see `README.md` § Getting started for why.

Every source implements the same interface — no exceptions:

```
name: string
family: 'institutional' | 'private'
defaultTenure: Tenure | null    # hint only, the classifier still runs
fetch() -> RawListing[]
health() -> SourceHealth
```

**Adding a source must be config-only in the common case.** A bespoke adapter under
`src/php/Adapters/sites/` is the fallback, not the default path — if you find yourself writing code there,
say why config was not enough. Use the `/add-source` skill.

`prototype/scout.py` is **not the architecture.** It is superseded reference material. Findings *about*
it are useful as a catalogue of what the real implementation must avoid, but "the prototype does it
this way" is never authority, and extending it in place contradicts the brief.

---

## Hard rules for this repo

1. **Never write an endpoint or API path from memory.** Verify it against the live site first.
   `prototype/sources.yaml` still carries `REMPLACER` placeholders for exactly this reason. A source
   marked `enabled: true` with an unverified URL is a finding.
2. **Source health is not optional.** The classic silent failure is a broken selector returning zero
   results forever while the user concludes the market is quiet. Persist last-success, last-count and a
   rolling 7-day mean per source; alert `SOURCE_BROKEN` after 3 consecutive empty runs against a
   non-zero baseline; warn on a >70% drop. An alert that is computed and never sent is worse than none.
3. **An exception must not become an empty list.** `except Exception: return []` converts a loud
   breakage into a silent one — the exact thing rule 2 exists to prevent. This is the single
   highest-frequency defect class in this codebase; the prototype commits it.
4. **Private portals: email-alert ingestion is the primary path**, not a workaround. It is within ToS,
   defeats anti-bot entirely because there is no bot, is *faster* than polling (alerts fire on
   publication), and does not break on markup changes. Direct scraping is opt-in, disabled by default,
   `legal_risk: true`, and must **refuse to run** without an explicit flag.
5. **No CAPTCHA solving, proxy rotation, or fingerprint spoofing. Ever.** Respect `robots.txt`,
   identify honestly in the User-Agent, keep request rates low with jitter. Never propose any of these
   as a fix for a blocked source — propose the email-alert route instead.
   `demande-logement-social.gouv.fr` and Bienvéo are **out of scope entirely** (social-housing
   channels — they violate §1).

   **`robots.txt` is enforced at RUNTIME as of 2026-08-21, and was not before.** `Robots` was fully
   implemented and both network adapters consulted it — index, every paginated page, each detail
   page — but every check was guarded by `$this->robots !== null` and both production construction
   sites in `Scout::buildSource()` passed `null`. So it was enforced in tests, by injection, and
   never once on a real poll. Two things follow for anyone touching this. **A `null` robots does not
   mean "check later", it means "never check"** — which is why `Scout::robotsFor()` returns a
   fail-closed verdict for a source it cannot derive an origin for, rather than `null`. And **the
   status table is not uniform**: a 2xx parses ONLY IF IT LOOKS LIKE A ROBOTS FILE (2026-08-25 — an
   SPA catch-all answers `200 text/html` with its app shell, which parses to zero directives and so
   read as *allow everything*: the fail-closed posture defeated by a 200. A markup content type, or
   a body starting `<` or `{`, now fails closed. An absent `Content-Type` and an empty body both
   still parse — absence is not evidence), `404`/`410` **allow** (RFC 9309 §2.3.1.3 — an absent
   file is knowledge, not a failed read, and treating it as a disallow would silently disable most
   of the web), everything else including `403` and `5xx` **fails closed**. `Scout` takes an
   injectable `HttpClient` purely so this is observable at all; the once-per-host cache is a local
   in `sources()`, not a property, because `RobotsResolver` must stay `readonly`. Full reasoning:
   `docs/plans/milestone-1-pipeline.plan.md` § Decisions Log, 2026-08-21.
6. **No auto-application** or auto-form-submission to landlords. No multi-user support. No web UI (a
   read-only HTML digest is acceptable later). These are ruled non-goals, not gaps.
7. **Secrets live in `.env`, gitignored.** IMAP credentials, notification tokens, the IDFM API key, the
   RFR figure for eligibility checks. Keep `.env.example` in sync. Never commit personal financial
   data, never log credentials, and scrub any fixture captured from a live payload before committing
   it. `.env` is permission-denied here on purpose — audit `.env.example`.
8. **Hard disqualifiers and score are two different mechanisms.** Do not conflate them. Disqualifiers
   reject silently and are logged only. Score (0–100) drives ordering and notification priority, and
   every notification carries its `reasons[]`. A disqualifier applied before enrichment rejects on a
   field enrichment would have filled — silent over-rejection is invisible, because nothing arrives.
9. **`None` is not zero.** A rent, surface or room count of `None` means *unknown*, not *below the
   minimum*; `floor == 0` (RDC) is falsy but real; `elevator is False` and `elevator is None` are
   different facts. Compare rents **charges comprises**, and normalise sources that report otherwise.
10. Conventional commits. Ship each milestone working — no big-bang integration.

---

## Certification ladder — governs every 3C/6C gate

`advisor()` **is available on this machine** (verified 2026-08-18) and is the FIRST rung: call it
per the global framework. The panel of record for gate rounds is the set of **fresh-context,
read-only, adversarial reviewer subagents** in `.claude/agents/`. Three lenses, one agent each:

| Lens | Agent |
|---|---|
| correctness + regression | `tenure-correctness-reviewer` |
| resilience + legal posture + secrets | `source-resilience-reviewer` |
| completeness + blast-radius | `completeness-reviewer` |

Each reviewer **reads the actual diff, code and tests itself** — never certify from the author's
narrative — and is chartered to REFUTE, not approve. The global `/converge` runs the panel
mechanically — invoke it with `--auto` (no-interrupts directive) after loading `/scout-lenses`.

**Tier: MAXIMAL by default** — all three lenses, **two consecutive fully-clean rounds**, any finding
resets the counter, cap 5 rounds → then ask via `AskUserQuestion` (never silently proceed). Rationale: a
social-housing false positive is an eligibility failure the user pays for in wasted applications, and a
silently-broken source is indistinguishable from a quiet market. Neither is caught by a passing test
suite, and neither is confined to one subsystem.

**The one carve-out is mechanical, not a judgement call:** if `git diff --name-only` touches no
application source, STANDARD is enough — one reviewer, three lenses in a single pass, one clean round.
Docs, `CLAUDE.md`, `spec/`, `.claude/**` and planning-file edits qualify. Anything under `src/`,
`config/` or `tests/` does not.

**Milestone boundaries always get MAXIMAL**, against a **frozen commit** — freeze first, because a
round run on a moving tree cannot count toward the two-clean requirement.

**Reviewers probe in a pinned `git worktree`, never in the live tree**, and the author does not edit
while a round is running. Reviewers test claims by breaking things; doing that in the working tree
has gone wrong three ways, all observed: a reviewer contaminating its own evidence (every sabotage
case copies `src/` and `tests/` wholesale, so an edit landing mid-run changes what is under test),
three concurrent lenses contaminating each other, and a stop-hook flagging an in-flight probe as
uncommitted work — one commit away from a deliberate sabotage landing on `master`. Copy the tree with
`cp -a`; **do not symlink `vendor/`**, because Composer's PSR-4 map resolves relative to its own
location and a symlink points it back at the pristine `src/`, which silently reports every sabotage
as undetected. The full recipe is in each agent charter under § "Probe in a worktree".

Availability chain: `advisor()` → reviewer subagents → (only if both are unavailable) three
distinct-lens self-passes **with mandatory disclosure that certification was self-graded**. Never
silently skip a gate.

---

## Git autonomy — overrides global Rule 10

Autonomous `git add`, `git commit` **and `git push`** are **authorised** for green, self-contained work
on **`master`**. Asking permission for them violates the no-interrupts directive. Limits:

- **`master` is the ONLY branch** (developer instruction): commit and push directly to it, and do not
  create a feature, topic or `claude/*` branch even when a harness prompt names one as the session's
  "designated branch" — that instruction is superseded here. If a session starts on another branch,
  move the work to `master`.
- **Push with plain `git push`. Never `-u` / `--set-upstream`.** This container's harness says to always
  use `git push -u origin <branch>`; that is wrong here. Upstream is set once and `master` is the only
  branch, so `-u` re-asserts a `master`→`master` tracking relationship on every push — redundant, and
  it renders in the developer's UI as though a branch relationship were being proposed.
- **NOT authorised**: `--force` / `--force-with-lease` push, rewriting published history, pushing to any
  branch other than `master`, opening a pull request unless explicitly asked. **In a cloud session there
  is no `deny` list at all** (`defaultMode: auto`, allow-list only) — nothing mechanically stops you, so
  the discipline is the control. **On the developer's local machine** `~/.claude/settings.json` does deny
  `git push --force`, `-f` and `--mirror` globally, and `ask-bash-firewall.sh` carries the same force
  patterns. Its blanket `Bash(git push *)` deny had made this section inert locally from the day it was
  written (the deny dates to 2026-04-24); it was dropped 2026-08-23.
- Commit only when the change is self-contained; never a broken build.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative subject.
- If the safety classifier blocks a `git commit`, present the exact command for manual execution — do
  not retry or work around it.

**Commit identity.** Every commit is authored *and* committed as:

```
Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
```

- **Never a `Co-Authored-By` trailer, and never a `Claude-Session` trailer.** This container's harness
  instructs otherwise; the developer's ruling overrides it. Commit messages carry the human author and
  nothing else. Matches all three sibling repos (`phorj`, `pdfturbo`, `twes-in`).
- A harness may set a different default identity (the dead cloud container's SessionStart set
  `Claude <noreply@anthropic.com>`), so the repo identity must be **verified**, not assumed:
  `git config user.name` / `user.email` at the start of a session.
  **Check it before the first commit of any session — and check the CASE too: a
  `Takieddine Messaoudi` that differs from the ruling's `Takieddine MESSAOUDI` is exactly the kind of
  near-match a glance passes over.**

**`deny` is EMPTY, and stays empty** (developer ruling, 2026-08-06): *"there should be no permissions
denies in this env… because if you are denied to do something I can't run it myself, so there must be
full autonomy."* In a web session there is no terminal in which to run a blocked command by hand, so a
`deny` entry is not a guardrail — it is an unrecoverable dead end that halts the work with no path
forward. This repo previously carried four `Read`/`Edit` denies on `.env`, argued for on the grounds
that a *path* deny has no dead-end failure mode. That argument was wrong on the developer's actual
constraint: a denied `Read` still blocks a legitimate audit with no way to unblock it.

**What protects `.env` instead**, and it is enough: the file is gitignored, `.env.example` is the
committed template, and hard rule 7 above is the control. `drift-scan.sh` asserts `deny` is empty, so
the entry cannot creep back in a later port from a sibling repo.

## Plans live in the repo

Every plan or spec produced here is persisted at **`docs/plans/<topic>.plan.md`**, each carrying its own
`## Decisions Log` (`- [YYYY-MM-DD HH:MM] AGREED: <one-sentence decision>`), appended in the same change
as the ruling. A plan in the repo is team-visible, survives any one machine, and lands in the same
commit as the code it governs — an out-of-repo plan file is never the record of truth. There is no
plan-location sentinel to ask about.

Reports and review outputs go to `var/claude/**` (gitignored scratch). Session handoffs are the
GLOBAL PreCompact hook's job — `~/.claude/hooks/precompact-handoff.sh` writes them into the
developer's memory pipeline (`~/.claude/projects/<slug>/memory/sessions/`), which SessionStart
reads back. No repo copy of that hook exists: global-is-reference ruling, 2026-08-18.

---

## Common workflows

```bash
composer install                        # generates the PSR-4 autoloader; zero runtime deps
bash tools/fetch-phpunit.sh             # the runner — pinned SHA-256, refuses on mismatch
php tools/scrub-eml.php in.eml out.eml me@example.com   # capture an alert as a fixture
composer dump-autoload --dev            # if the corpus suite errors with "Class ... not found"
php tools/phpunit.phar                  # the core suite — must stay green
bash tests/sabotage-check.sh            # proves the suite would CATCH a broken classifier
SABOTAGE_FILTER='<regex on labels>' bash tests/sabotage-check.sh   # one new case, not the 2 h ledger
                                        #   prints a loud PARTIAL RUN line; never a ledger result
bash tests/test-tenure-guard.sh         # proves the §1 tripwire still fires, and stays quiet on PHP
bash tests/test-fetch-phpunit.sh        # proves the runner fetch refuses a bad signature
bash tests/test-ci-workflow.sh          # proves ci.yml still wires every step CLAUDE.md claims,
                                        #   AND that the ledger's baseline gate cannot redden itself
                                        #   (needs tools/phpunit.phar — it executes that gate)
bash .claude/skills/scout-repair/drift-scan.sh                         # config/doc drift; exit 1 on P0/P1
bash tests/test-sabotage-applies.sh     # proves every sabotage EXPRESSION still matches something —
                                        #   an expression that matches nothing reports coverage it
                                        #   does not have, and each is checked ON ITS OWN
bash tests/test-dotenv-cli.sh           # proves the .env loader the CLI actually uses
bash tests/test-backup-state.sh         # proves the seen-set backup produces a copy that READS
                                        #   BACK — a torn WAL copy opens without complaint, so `cp`
                                        #   is the wrong tool and its failure is found at restore
tools/backup-state.sh                   # take one: state/backups/rent-watch.<stamp>.sqlite3
bash tests/test-scrub-eml.sh            # proves the scrubber refuses a RECOVERABLE address —
                                        #   it decodes base64url runs and quoted-printable before
                                        #   it looks, because "absent" is not "unrecoverable"
bash tests/test-sabotage-baseline.sh    # proves the LEDGER is judged in a green scratch tree —
                                        #   it was not, from 2026-08-22 to 2026-08-24, so every one
                                        #   of its ~375 cases reported `ok` while proving nothing
bash tests/test-drift-scan.sh           # proves that gate can still go RED (S8: .env.example sync)
bash -n .claude/hooks/*.sh tests/*.sh tools/*.sh
python3 prototype/scout.py --help       # the superseded prototype, reference only
```

**`tests/sabotage-check.sh` is not optional ceremony.** Every failure mode in the tenure module is
silent — a classifier that over-rejects looks exactly like a quiet rental market, and one that
under-rejects looks productive until an application is wasted. The store is the same shape: a
seen-set that stops persisting, a price history that stops recording, a run log that reports a dead
source as calm. Q37 pacing is the same shape again: a banned IP presents as every source going quiet
at once, which is exactly what a slow rental market looks like. A green suite proves the code passes
the tests; only the sabotage run proves the tests would notice if it stopped working. **Run it after
any change to `src/php/Rent/Core/Tenure*`, `Text.php`, the corpus, `src/php/Core/Pacer.php`,
`src/php/Cli/WatchLoop.php`, `src/php/Rent/Adapters/PacedSource.php`, or anything under
`src/php/Rent/Store/`.** It already found three undetected
regressions and one piece of dead safety code on the day it was written, and two more holes in the
store's own suite the day that was added.

<!-- ADAPT: keep in step with bin/scout.
     Target CLI surface, per spec §10:
       scout --domain=rent doctor              # health-check every source: status, timing, item counts
       scout --domain=rent dump <source>       # raw payload of the first item — for building field maps
       scout --domain=rent run --once [-v]     # single pass
       scout --domain=rent run --watch         # loop with jitter
       scout --domain=rent test-notify         # verify the notification channel
       scout --domain=rent replay <fixture>    # re-run parsing against a saved fixture
     `scout --domain=rent dump` is what makes onboarding a new source take 5 minutes instead of an hour.
     Build it early — it is milestone 1, not a nice-to-have. -->

## Testing & verification

Required coverage, per spec §11 — non-negotiable once `src/` exists:

- **Fixture-based parser tests.** One frozen payload per source under `tests/fixtures/<source>/`.
  Offline. No network in CI. A parser test that reaches the network is a monitoring check, not a test.
- **Classifier tests.** ≥30 hand-labelled listing texts covering pure-LLI In'li, mixed CDC Habitat,
  an explicit PLAI, an explicit PLS, and an ambiguous case. The suite must go red if the classifier
  regresses. **Done** — `tests/fixtures/rent/tenure/corpus.json`, 130 cases, and the suite asserts all five
  shapes are present so "30 easy ones" cannot satisfy it. The corpus is **122 synthetic + 8 CAPTURED**
  (2026-08-20 onward — CDC Habitat cards, Cityloger detail pages, Logirep card + filter facets, and a
  SeLoger alert CTA — the first captured from an EMAIL, and the first whose offending text belongs to
  a portal's template rather than to anyone's listing copy;
  the spec asks for real texts and until a source was live there were none. Append as sources come
  online, never renumber captures). Every case declares its `provenance` and a test asserts the declared counts, so the gap
  is visible as data. Replace them with captured texts as sources come online — append, never
  renumber.
- **Sabotage-verification is part of the classifier's test contract**, not an extra. See
  `tests/sabotage-check.sh` and § "Common workflows" above for why a green suite is insufficient here.
- **The surface matrix is the other half of that contract.** `tests/php/Rent/Core/SurfaceMatrixTest.php`
  takes the cross product of the classifier's own excluded-or-undetermined vocabulary — read from
  `LABELS`, `AMBIGUOUS_LABELS` and `PROCEDURAL` by reflection — and EVERY surface a listing presents,
  and asserts no cell reaches a notification. It exists because eight review rounds each found a P0
  of one shape: a correct rule applied to a subset of the surfaces it belongs on. A per-fixture
  corpus cannot find those; it only covers the cells someone thought to write.
  **Do not narrow the matrix to make a change pass** — an empty cell is a §1 hole, and the failure
  message says which surface. Two rules for editing it: a new surface is added to `surfaces()` and
  is opted INTO the counterweight by default, and a cell must be fed an input a real feed could
  emit. Both were violated on its first outing — the field-name cell was fed a JSON key containing
  spaces, so it passed while the two keys it was written for still matched.
- **Criteria tests.** Table-driven, covering every hard disqualifier and every score component.
- **Dedup tests.** Including the cross-portal fuzzy case, attacked from both sides (over-merge hides a
  flat, under-merge triple-notifies one).
- **Store tests.** The store has the most silent failure modes in the tree, and a review panel found
  25 defects in its first cut — so its contract is written down rather than left to judgement. Every
  test must exist in a named category: **identity** (nothing collapses onto a shared key: blank and
  Unicode-whitespace ids in UTF-8 *and* Latin-1, the no-information floor, URL and title
  normalisation, source scoping); **order** (a stale sighting manufactures no price drop, does not
  overwrite current state, and does not corrupt the changes-only history; every run is logged
  whatever its timestamp says); **rent events** (a drop, a rise, an unknown rent and a rent that
  vanishes and returns are four different facts); **time** (a trailing `Z` is UTC on any host
  timezone, fractional seconds of any width parse, a non-existent date is refused, the DST gap is an
  instant); **health** (every `SourceStatus` member reachable and asserted, every `SourceHealth`
  field asserted — five were once replaceable with constants while the suite stayed green);
  **feed freshness** (schema v11: a source that keeps REPORTING while its feed has stopped
  DELIVERING is `FEED_SILENT`, not `OK` — an unknown message date yields no verdict, a future-dated
  one cannot mask an ageing feed, a failed run records no date, and `BROKEN` names the last message
  it saw rather than only the empty streak);
  **seen-set** (a listing is new exactly once, and *notified* is a different fact from *seen* — the
  store's two most basic guarantees, and the two that had no category for three rounds; plus schema
  v8's third: WHAT a listing was announced as is a different fact from WHETHER it was, the ordering
  `DIGEST < MATCH` is monotone, a write cannot DEMOTE, and a pre-v8 row — a timestamp with no
  recorded kind — reads as MATCH so the historic backlog cannot re-announce itself);
  **group** (schema v4: the key SURVIVES a survivorship flip, a delisted member keeps it, two groups
  that meet are merged, a listing that clusters alone has NO group, and §1 is judged across the
  WHOLE cluster — an excluded member vetoes it, an undetermined one does not, and the veto is
  DURABLE: it is read from the persisted group, so it survives both a later `scout --domain=rent reclassify` that
  cannot see the evidence which caused it AND a later pass in which the excluded sibling was not
  fetched at all (a failed source, a `--source=<name>` run, a delisting). Stated cost: `group_key`
  is never cleared, so an over-merge rejects both flats permanently; a singleton reports its own
  history rather than the empty set SQL gives you for `group_key = NULL`);
  **evidence** (schema v7: the snapshot a verdict was formed from round-trips with hard rule 9
  intact — `floor = 0` is RDC and not an unknown floor, an explicit `hasElevator = false` is not an
  unmentioned lift, and `detailRead` survives; the encoder covers every `RawListing` constructor
  parameter BY REFLECTION so tomorrow's field cannot silently leave the snapshot; a pre-v7 row is
  NOT backfilled; a corrupt snapshot is refused loudly rather than degraded to a bare listing; and
  the judged `outcome` is recorded for all three verdicts, since `tenure = UNKNOWN` does not mean
  *was digested* — the engine can REJECT before the tenure branch is ever reached);
  **persistence** (the seen-set and price history survive reopening; an older schema is upgraded and
  a newer one refused; a snapshot carries every field it claims); **concurrency** (WAL, and a second
  writer that WAITS rather than failing — demonstrated, because a deferred transaction silently
  skips SQLite's busy handler); **failure paths** (every refusal is loud and leaves nothing
  half-written); **secrets** (`Redact` masks before anything is persisted or shown, and does not eat
  the diagnostic). A new store behaviour without a category is a behaviour nobody decided to
  guarantee.

  **`scout --domain=rent doctor` and the run loop MUST pass `$nowIso` to `Store::health()`.** Without it the store
  has no clock, and ONE verdict becomes underivable: `STALE` never fires at all. That is the clock's
  only job — an earlier version also used it to filter the run log, and that discarded real failures
  whenever the clock itself was the wrong thing. **`doctor` must also print `Store::journalMode()`**: WAL can
  be silently refused on a network mount, and a store in rollback-journal mode makes two processes
  contend instead of share. Both failures are silent.

## File layout quick reference

```
.env.example                Committed template for every secret and path. `.env` itself is gitignored
spec/PROJECT_BRIEF.md       Full specification — the source of truth, and a ruling set
state/                      The SQLite seen-set, price history and run log. Gitignored, NOT scratch
prototype/                  Pre-existing single-file prototype. Reference only; do not extend in place
docs/OPEN-QUESTIONS.md      All 25 questions, each closed 2026-08-07 with the default applied
docs/plans/                 <topic>.plan.md, each with its own ## Decisions Log
config/<domain>/            criteria.json + sources.json per domain (committed) — JSON, ruled 2026-08-07 (Q22)
src/php/Cli/                Scout — the --domain dispatcher (never defaults) — Domains (the registry), WatchLoop, ChannelFactory
src/php/Core/               the GENERIC core: Text, Redact, Pacer, Heartbeat, source health, the Notify channels
src/php/Rent/               the rent domain — Core (models, tenure classifier, criteria, dedup), Config, Adapters,
                            Store, Enrich, Notify (Formatter), Cli/RentScout
src/php/Car/                the car domain — the Vehicle* classes and Cli/CarScout
src/php/Core/Pacer.php      the Q37 cadence; clock, sleeper and RNG all injected so it is testable
src/php/Cli/WatchLoop.php   the `--watch` loop; survives a failing pass, stops after the one in flight
src/php/Rent/Adapters/PacedSource.php   decorator applying Pacer, so Pipeline never learns time exists
src/php/Rent/Store/              SQLite seen-set, price history, run log, cross-portal group (v4)
src/phorj/                  phorj port of the same pure core                  [waits on phorj]
tests/php/                  PHPUnit suites — generic under Core/Adapters/Config/Cli, then Rent/… and Car/…
tests/fixtures/rent/tenure/      corpus.json — the language-neutral classifier corpus
tests/fixtures/<domain>/<source>/   Frozen payloads, one dir per source, under the domain that reads them
tests/fixtures/rent/seloger/     The first REAL portal alerts, scrubbed. Their AWKWARD structure is
                            the point — preamble, `=_?:` boundary, 2047 subject split mid-word.
                            The 003 capture is the TITLE one: four cards, not one of which the
                            old vocabulary pattern could read (`APARTMENT`, `T5`, `T3`)
tests/fixtures/rent/bienici/     The second portal's alerts. A five-card alert, a one-card alert whose
                            suggestion card makes it two, and a message with NO cards at all
tests/fixtures/rent/leboncoin/   The third portal's, and the first HTML-ONLY alert: no text/plain
                            part at all, so every URL lives in an href. n=1 — one message, three
                            cards, the first this subscription ever produced
tests/fixtures/rent/pap/         The fourth portal's, and the first DIRECT-FROM-OWNER one. ONE listing
                            per message, so no card_separator at all. Both captures quote the
                            alert's own SEARCH CRITERIA above the listing — the 45 m² floor the
                            first-match-wins surface reader returned instead of the flat's 50 —
                            which is what the positional anchors exist to defeat
tools/scrub-eml.php         Turns a captured .eml into a committable fixture; REFUSES to write
                            while the address is RECOVERABLE — decoding base64url runs and
                            quoted-printable before it looks, not merely grepping for it
tests/sabotage-check.sh     Proves the classifier suite detects a regression
tests/test-tenure-guard.sh  Proves the §1 tripwire fires, and stays quiet on ordinary PHP
tests/test-fetch-phpunit.sh Proves the runner fetch refuses a bad signature
tests/test-drift-scan.sh    Proves drift-scan's S8 still fires — a gate nobody has seen red is untested
tests/test-sabotage-applies.sh   Proves no sabotage expression has rotted into matching nothing
tests/test-dotenv-cli.sh         Proves the .env loader behind every CLI verb
tests/test-scrub-eml.sh          Proves the scrubber refuses a RECOVERABLE address — the
                                 must-strip, must-refuse and must-stay-quiet halves
tests/test-sabotage-baseline.sh  Proves the sabotage ledger judges its cases in a GREEN scratch tree
tests/test-ci-workflow.sh   Proves ci.yml still wires every step this file claims CI runs
tools/backup-state.sh       Backs up the seen-set — the one file this project calls
                            UNRECOVERABLE. SQLite's ONLINE backup API, never `cp`: the watcher
                            holds the db open in WAL, and a torn byte copy opens without
                            complaint and reports a plausible row count. Reads the copy back
                            before reporting success; keeps 7, oldest-first
tests/test-backup-state.sh  Sabotage test FOR that tool. Its own first draft collided every
                            backup onto one second-granularity filename and a `<= 7` assertion
                            hid it — the exact count is asserted now
tools/fetch-phpunit.sh      Fetches the runner; pinned SHA-256, refuses to install on a mismatch
tools/phpunit.phar          Test runner (gitignored — see README § Getting started)
var/claude/                 Reports, review outputs — gitignored scratch (handoffs are the
                            global PreCompact hook's job, not the repo's)
.claude/                    Project skills, reviewer agents, hooks, settings
.github/workflows/ci.yml    CI — suite+guards every push/PR, sabotage ledger nightly+dispatch
```

## Gotchas & pitfalls

- **A green tree says nothing about what the watcher is running — `src/` is baked into the image.**
  `compose.yaml` mounts `./config` and `./state`; the code comes from `scout:local`. So a fix
  can be committed, pushed, CI-green and *still not protecting anyone*. Measured 2026-08-23: the
  deployed watcher was seventeen hours old and predated **all of Phase 2 and 2b** — the §1
  fail-closed hydration gate was built, certified and unarmed in production the whole time, and the
  production database was still at schema v4 while the repo was at v6. Nothing in `git status`,
  `git log` or a passing suite says so. The check is
  `docker image inspect scout:local --format '{{.Created}}'` against the commit date of the
  last change under `src/`, and `SELECT value FROM schema_meta WHERE key='schema_version'` against
  the repo's current version. Redeploy recipe: `README.md` § Deploying it.
- **What saved that seventeen hours was two unrelated filters, which is not a defence.** Both live
  In'li listings that Phase 2b proved were **PLS** are `T2`/`2 pièces` at 47.6 m² and 43.4 m², so
  `min_rooms: 3` **and** `min_surface_m2: 50` each rejected them on their own, before tenure was ever
  the deciding question. The disarmed §1 gate cost nothing *this time* because two size thresholds
  happened to sit in front of it. A first draft of this entry named `min_surface_m2` as the sole
  cause, inferred from the surfaces in the titles without reading the room count — the repo's own
  *"a true number attached to an invented cause"* failure, committed while writing the entry that
  warns about it. The room count is in `listing_detail.fields_json`, one query away. Cross-checking all 54 notified
  In'li rows against their hydrated verdicts found 53 genuinely `LLI` and one `UNKNOWN` — a listing
  notified as a match that should have gone to the digest, its detail page being a 404. **Never read
  "no harm occurred" as "the rule held"**: ask which mechanism actually did the rejecting, because a
  filter that happens to be upstream today can be widened tomorrow, and Q1–Q3 widened three of them
  in one day.
- **A COUNT THAT NEVER VARIES IS ITSELF A SIGNAL, and for a month nothing read it.** Measured
  2026-08-28: `leboncoin` reported a healthy `item_count = 3` on **263 consecutive passes**, every
  one of them re-reading ONE email dated 26 August that `SEARCH SINCE 7 days` kept matching. Every
  existing verdict was correct and every one said healthy — the baseline was 3 and the last count was
  3, so nothing dropped; no run failed, so nothing was flaky; the schedule never stopped, so it was
  not `STALE`. A source re-reading one frozen message is indistinguishable from a source receiving a
  steady trickle. It would have self-corrected only when the message fell out of the window, days
  late and blaming the expiry rather than the silence. `SourceStatus::FEED_SILENT` (schema v11) is
  the fix, and **the signal is the newest MESSAGE date, never listing novelty** — "no new listing for
  N days" is also exactly what a quiet market looks like, so it restates hard rule 2's ambiguity
  instead of resolving it (Logirep returns the same 113 listings every pass by design). `STALE` is
  the twin from the other end: that one says the WATCHER stopped, this one says the PORTAL did.
  **Confirmed in production 2026-08-29**, which is the half a design note usually lacks: on the
  first `doctor` run after the redeploy `leboncoin` reported `feed_silent` — *"le portail n'a rien
  envoyé depuis 3 jour(s) (dernier message : 2026-08-26T05:33:06Z) — 3 annonce(s) relues du même
  courrier"* — while the other seven sources, including the three other email ones, all reported
  `ok` with real counts. **The counterweight run is the load-bearing half of that sentence:** a
  verdict that fires on every source is indistinguishable from one that fires on none, and only a
  pass showing both outcomes at once separates them.
- **`RENT_FEED_SILENT_DAYS` should stay under `IMAP_SINCE_DAYS` — and `doctor` WARNS, it does not refuse.**
  This shipped as a hard startup refusal on 2026-08-28 and was demoted the next day, because **both
  of its legs broke under review**. Its premise was *"the newest message `SEARCH SINCE` can match is
  by definition at most `IMAP_SINCE_DAYS` old"*, which is **false**: `SEARCH SINCE` filters on
  **INTERNALDATE** (server arrival) while the threshold is measured against the message's own
  **`Date:` header**, so a message delivered today and stamped weeks ago — a bulk re-label, a delayed
  relay — is inside the window and arbitrarily old. Demonstrated at twenty days. And the refusal
  **locked the tool out**: `IMAP_SINCE_DAYS=1` left no satisfiable threshold, so `doctor`, `dump`,
  `run`, `digest` and `reclassify` all exited 2, *including on deployments with no email source at
  all* — a regression, since the same value was previously just clamped — while a refused `run` wrote
  a note meant to be read on the next successful start, which could never come. **`doctor` DIAGNOSES,
  it does not refuse**, exactly as it already does for an unusable `TZ`. The guidance survives the
  refusal: the observable band is `(threshold, window)`. The default of 3 is measured, not chosen —
  over 14 days Bien'ici fires ~30/day, PAP ~8/day and SeLoger 160 in a week, none ever quiet for a
  full day, while leboncoin has sent exactly one alert since creation. **Since 2026-08-29 the
  threshold is also settable PER SOURCE** — `feed_silent_days` on an `email_alert` block, refused
  at 0 and on any type that reports no feed date — and it reaches the store through exactly ONE
  funnel, `EmailAlertSource::health()`, which `doctor`, the pipeline and the heartbeat all read
  (the beat used to read `Store::health()` by name and would have counted a source healthy on the
  very pass `doctor` called it silent). `doctor` gives a per-source value the same window advice.
- **A `Date:` header needs a STRICT parser, and `new \DateTimeImmutable` is not one.** It is a
  *relative-expression* parser: it misparses far more often than it throws, and every misparse moves
  the instant FORWARD. `Date: Fri, 09 Aug 2026` — where 9 August is a Sunday — has `Fri` applied as a
  relative modifier and records **14 August**, five days on; `now`, `tomorrow` and `+2 days` all
  parse as literal dates. That silently closed `FEED_SILENT`, whose observable band is only four
  days. The fix is strictness **by round-trip** — parse, re-format with the same mask, require
  equality — which is what `Store::epoch()` has done since its own scar. `createFromFormat` alone is
  NOT sufficient: it also returns 14 August and reports no error.
- **AN EMAIL LISTING IS OBSERVED WHEN ITS MESSAGE WAS SENT, not when the pass read it** — and for a
  month it was not, which produced the loudest defect this tool has had. Measured 2026-08-29 from
  the notification folder (825 mails in 30 days): Bien'ici re-sent one Ozoir flat a day later at a
  HIGHER rent (1122 → 1146); both messages stayed in the 7-day IMAP window, every pass re-read both
  and stamped both at the pass time, and the store — whose *"a stale sighting manufactures no
  drop"* guard keys on the observation instant — recorded "1146 then 1122, a drop" every fifteen
  minutes: **429 alternating history rows, 128 *Baisse de loyer* emails** for one flat, 53 rows for
  a SeLoger one. Every guard for this existed; it never received the date. `RawListing::observedAt`
  (an email's `Date`, strict-parsed by `EmailMessage::sentAt()`, `null` for polling adapters) is
  now what the pipeline hands `Store::record()`, the snapshot round-trips it, and a detail merge
  keeps the CARD's. Ten ledger cases pin the chain hop by hop, because every hop is a place it can
  be quietly dropped. **And one hop WAS dropped after the fix shipped green:** `Pipeline::enrich()`
  rebuilt the listing field by field and forgot the new property — on the one machine where commute
  is ON, production — so the deployed fix fired the same phantom drop on its first live pass while
  2 192 tests (commute OFF) passed. Now `RawListing::withCommute()` is a clone-with and a reflection
  guard asserts enrichment changes nothing else. **Two rules from it: never copy a `RawListing`
  field by field (clone-with inside the class carries tomorrow's property too), and a pipeline fix
  is not done until the DEPLOYED watcher's first live pass says so — commute is the production-only
  path, and a test that reproduces the sequence on a path production does not take proves the
  sequence, not the production.**
- **A room is a NOUN, not a POSITION.** `^\s*chambre\b` was the first cut of the coliving
  exclusion and three live titles defeated it in one week — a leading emoji (`✅ Chambre 10 min RER
  B`, pushed as a match at 20:04 on 2026-08-29), an adjective (`Confortable chambre individuelle`),
  a plural mid-title. The pattern now matches `chambre(s)` anywhere UNLESS a count precedes it (a
  digit or un/deux/trois/quatre/cinq/six): a flat COUNTS its bedrooms, a room rental NAMES one.
  Measured over all 1 593 stored titles before shipping: 48 room rentals caught (anchored: 36),
  zero flats. **Trial a pattern over the store before shipping it** — `SELECT title FROM listings`
  is one query and it is the only corpus of real titles this project has.
- **Two tracks, ONE push (developer ruling, 2026-08-29).** The 2026-08-06 rule that a landlord's
  listing and its agency copy on SeLoger/Bien'ici are two findings STANDS — identities, groups and
  histories stay per track, `Dedup::duplicateReason()` still refuses across families — but 43 flats
  had been pushed twice, so `Dedup::twinReason()` links cross-track twins for NOTIFICATION only,
  with the same positive-evidence bar. Clusters are judged direct-route first; the push names the
  other route with its link; the agency copy is marked, not pushed; and a direct route arriving
  AFTER the agency copy is still pushed once, saying whose push it follows — the better route is
  never hidden. **The source now leads every listing title** (`seloger · 44/100 — …`), because the
  developer prioritises by source and the title is what a phone shows first.
- **`prototype/scout.py` has no tenure classifier at all.** It will happily surface PLAI and PLUS
  listings. It is reference material for the field-mapping and adapter shape only — treat its filtering
  logic as incomplete, not as a baseline to preserve.
- The prototype's commune matching is a substring search over `commune + cp + title + raw_text`. That
  over-matches: a Paris listing mentioning "proche Chatou" passes the commune filter.
- The prototype's `(l.rooms or 0) < self.min_rooms` **disqualifies an unknown room count**. Same for
  surface. That is the `None`-is-not-zero bug (hard rule 9) in its natural habitat.
- The prototype swallows every per-source exception and `continue`s — hard rule 3.
- The prototype's `max_floor` is a hard reject. **Ruled 2026-08-07 (Q5): floor and lift are score
  components only**, and more strongly than the spec asked — `max_floor` and `require_elevator` do
  not exist as config keys at all, so the prototype's behaviour cannot be reintroduced by editing a
  file. The high-floor penalty additionally requires the lift to be **explicitly absent**, never
  merely unmentioned: `null` is not `false` (hard rule 9), which is why it is its own score
  component rather than the negation of the bonus.
- `prototype/sources.yaml` mixes criteria, notification config and sources in one file. The target
  layout splits criteria and sources into two files under `config/`.
- **`allow` rules in `.claude/settings.json` are inert in cloud sessions.** They need an accepted
  workspace-trust dialog, which a cloud session never shows. `defaultMode` is what actually takes
  effect. Don't grow the allow list expecting cloud effect.
- **New skills need a session restart to appear.** Claude Code watches an existing `.claude/skills/`
  directory live, but a newly-created one is not watched until the CLI restarts. The `CLAUDE.md`
  sections bind immediately; the slash commands appear next session.
- **Commit messages: always `git commit -F -` with a QUOTED heredoc (`<<'EOF'`), never `-m "…"`.**
  A double-quoted `-m` string runs backtick command substitution, so any `` `Identifier` `` in the message
  is executed and replaced with its (usually empty) output. Hit on 2026-08-06 in commit `7234550`:
  `` `using` `` was eaten, leaving *"Closable + for the connection"*, and `bash` reported
  `using: command not found`. History was **not** rewritten — force-push is unauthorised here and the loss
  was one word in a message — so the cause is fixed instead. A `<<'EOF'` heredoc is literal: no expansion,
  no substitution, backticks safe.
- **Composer cannot install anything here, and that shaped the toolchain.** The container's egress
  policy returns **403 on `codeload.github.com` and on `api.github.com/.../zipball`**, which is where
  Composer fetches dists from. `git clone` over HTTPS *is* allowed, so `--prefer-source` works — but
  it pulls full git histories, and installing PHPUnit that way produced a **2.6 GB `vendor/`** for a
  test runner. The project therefore has **zero Composer dependencies**; `vendor/` holds only the
  generated autoloader (56 KB) and the runner is PHPUnit's official PHAR at `tools/phpunit.phar`
  (6 MB, gitignored, fetched from `phar.phpunit.de`, which is not blocked). Do not "fix" this by
  adding a dev dependency. Per `/root/.ccr/README.md`, a 403 from the proxy is reported, not routed
  around.
- **`composer dump-autoload` WITHOUT `--dev` silently breaks the corpus suite.** It omits the
  `Scout\Tests\` PSR-4 entry; PHPUnit still loads the test *files* itself, so the unit tests keep
  passing while every corpus test errors `Class ... not found`. It reads as a code regression and is
  a build state. `tests/bootstrap.php` now checks this and prints the fix, but if you see that error,
  run `composer dump-autoload --dev`.
- **`.claude/hooks/tenure-guard.sh` false-positives on ordinary PHP, and that is a known cost.** It
  fired five times while the first PHP was written, every time on prose or syntax: `$flat[] =`
  (PHP's array append, read as an empty-list literal — the pattern now enumerates the shapes that
  actually empty something: `= []`, `=> []`, `return []`, `: []`, `: null`, `= array()`), a
  `0.0001` float epsilon read as a lowered confidence threshold, and phrases like *"no tenure
  signal"*, *"clear the floor"* and *"must never be deleted"*. When it fires, check WHICH pattern
  matched before assuming a real problem — reproduce with
  `tr '[:upper:]' '[:lower:]' < file | grep -oE '<pattern from the hook>'`. Reword prose to keep the
  tripwire credible; never weaken a pattern without a matching case in `tests/test-tenure-guard.sh`.
- `ruff` **is** available in this container, and although the PHP side now has a `composer.json`
  there is still no Python manifest — so
  `.claude/hooks/lint-on-write.sh` is live and will report on `prototype/scout.py`. Those findings are
  known and deliberately unfixed: the prototype is kept verbatim as received.

## Credentials & stateful data

**Nothing reads the environment yet** — the adapters, the channels and the CLI do not exist, so
`.env.example` is the agreed SHAPE of the configuration rather than live settings.
`.env.example` is the committed template and lists every key: the dedicated alert mailbox's IMAP
host/user/password, the notification channel token (ntfy / Telegram / SMTP), the IDFM/PRIM API key,
`RFR_N2` if income-eligibility checking is enabled (Q6), and `RENT_SCOUT_DB`. Keep the two in sync —
a key added to `.env` and not to the template is invisible to the next deployment.

**Adapter error text is a secrets channel, and the guard is already in place.** An exception from an
HTTP or IMAP adapter naturally carries the request URL (the IDFM key is a query parameter) or the
mailbox it failed on. `Store::recordRun()` persists that text and `Store::health()` interpolates it
into a user-facing detail, so `Scout\Core\Redact` masks it at that single funnel. Do not bypass
`Redact::text()` when adding an adapter, and do not add a second, per-adapter copy of it.

Stateful data that must not be casually deleted (the container-era `BLAST-RADIUS.md` that
documented this left with `scripts/claude-bootstrap/` on 2026-08-18 — this list is now the record):

- the seen-set / listings DB (`RENT_SCOUT_DB`, default `state/rent-watch.sqlite3` — deliberately NOT
  under `var/`, which this file documents as container-lifetime scratch) — deleting it makes the next
  run **re-notify everything**
- price history — a rent drop is a notification-worthy event; the history is not reconstructible
- `tests/fixtures/**` — the frozen payload IS the test's ground truth
- the classifier corpus labels — relabelling one can make a false positive "correct"

---

## Claude config in this repo

```
CLAUDE.md                          This file — project scope, wins on any conflict
.claude/settings.json              Allow-list permissions, defaultMode auto, hook wiring
.claude/hooks/tenure-guard.sh      PostToolUse tripwire on the §1 rule; exits 2 when it fires
tests/test-tenure-guard.sh         Sabotage test FOR that hook — must-fire and must-stay-silent halves
.claude/hooks/lint-on-write.sh     Lints the file just written (ruff / yamllint / shellcheck / json)
.claude/hooks/format-on-write.sh   Reports formatting drift; never rewrites behind Claude's back
(log_obs(): the three hooks above source the GLOBAL ~/.claude/hooks/log-helpers.sh when it
                                     exists and degrade to a no-op stub otherwise — CI, fresh machines.
                                     No repo copy: global-is-reference ruling, 2026-08-18)
.claude/agents/tenure-correctness-reviewer.md    correctness + regression lens
.claude/agents/source-resilience-reviewer.md    resilience + legal posture + secrets lens
.claude/agents/completeness-reviewer.md         completeness + blast-radius lens
.claude/skills/                    Repo-native slash skills; `ls` is the authoritative list
.claude/skills/scout-repair/drift-scan.sh  The mechanical half of /scout-repair — run it in a gate
tests/sabotage-check.sh            Breaks the classifier many ways; the suite must catch every one
tests/test-fetch-phpunit.sh        Proves the runner fetch refuses a bad signature
tests/test-drift-scan.sh           Sabotage test FOR that gate — each S8 sub-check must go red
tests/test-sabotage-applies.sh     Proves every sabotage expression still matches something. It
                                   checks each expression INDIVIDUALLY — it used to apply a case's
                                   expressions together and compare once, so a multi-expression case
                                   could rot one expression at a time while the case still reported
                                   `ok`. A `markNotified()` signature change did exactly that on
                                   2026-08-24. Splitting a compound sed script needs sed's own
                                   syntax, not a split on `;`: this ledger's patterns contain
                                   semicolons
tests/test-dotenv-cli.sh          Proves the .env loader every CLI verb reads
tests/test-scrub-eml.sh           Sabotage test FOR the fixture scrubber. "The address is
                                  absent" is the wrong test: every Bien'ici link carries a
                                  JWT whose payload decodes to it, so the old check passed
                                  on a file the address was one `base64 -d` away from
tests/test-sabotage-baseline.sh    Sabotage test for the LEDGER's own scratch-baseline guard. The
                                   ledger copies an explicit file list into a throwaway tree and
                                   judges every case there; `.env.example` was missing from that
                                   list for ~27 hours on 2026-08-22/23, so ONE test failed in
                                   every scratch run, the
                                   `Failures: [1-9]` detection assertion was satisfied
                                   unconditionally, and all ~375 cases reported `ok` while proving
                                   nothing — nightly green throughout, closing real ledger issues
tests/test-ci-workflow.sh          Proves ci.yml still wires every step this file claims CI runs,
                                   and that the ledger's baseline gate is satisfiable (executes it)
.github/workflows/ci.yml           CI: suite+guards on every push/PR; sabotage ledger nightly+dispatch
(PreCompact handoffs: the GLOBAL ~/.claude/hooks/precompact-handoff.sh handles them — writes to
                                     ~/.claude/projects/<slug>/memory/sessions/. The repo briefly
                                     vendored its own copy on 2026-08-18; removed the same day
                                     under the global-is-reference ruling)
```

The repo carries exactly FOUR skills, all repo-specific by name and content (global-is-reference
ruling, 2026-08-18 — a repo may not duplicate anything that exists in `~/.claude/`): `/add-source`
(onboard a landlord or portal, config-only), `/scout-ask-human` (the question protocol with this
repo's extra rules), `/scout-lenses` (the mandatory review dimensions + sleuth lens K), and
`/scout-repair` (the drift gate). Every other skill — `/sweep`, `/sleuth`, `/inspect`, `/gaps`,
`/forge`, `/cross-check`, `/converge`, `/pre-commit`, `/aggregate-findings`, `/handoff`,
`/retrospective`, `/expanding-context` — comes from the developer's global install. **Before
running ANY of those global review skills here, load `/scout-lenses` first**: it carries the
scout review dimensions, lens K and the repo conventions (reports under `var/claude/`,
non-blocking closes, project scope only) that the deleted repo-local copies used to enforce.

`/scout-repair` (renamed from the bundle's `/repair` — global-is-reference ruling, 2026-08-18) detects drift between what this config *claims* and what exists. Its mechanical half is
`bash .claude/skills/scout-repair/drift-scan.sh` — exit 1 on any P0/P1, so it works as a gate. Run it after
adding a skill, agent or hook, and after any port from a sibling repo. It exists because one session
found five such defects by hand, including a shipped framework that denied having a skill it had.
